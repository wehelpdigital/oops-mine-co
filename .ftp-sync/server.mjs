#!/usr/bin/env node
/**
 * ftp-sync — MCP server + CLI that keeps the files git does NOT carry in sync
 * between this machine and the live host over FTPS / FTP / SFTP.
 *
 *   Code (tracked files)      → GitHub → cPanel "Update from Remote"   (not this tool)
 *   Media & other ignored data → this tool, both directions
 *
 *   MCP mode : node .ftp-sync/server.mjs                (stdio; wired up in .mcp.json)
 *   CLI mode : node .ftp-sync/server.mjs <command> …    status | pin | sync | pull | upload | mark-synced | watch | ls | get | rm
 *
 * Sync set = files on disk under config.include (default wp-content/uploads)
 * that are NOT tracked by git, minus config.exclude (generated caches that bake
 * in the local hostname, logs). Tracked files are refused unless force:true —
 * uploading them dirties the server's git working tree and disables the cPanel
 * Update button. wp-config.php and .git/ are never touched in either direction.
 *
 * Push detection is a size+mtime snapshot (state.json); pull compares remote
 * listings with local files (missing locally → download; size differs → only
 * with overwrite:true).
 */
import fs from "node:fs";
import fsp from "node:fs/promises";
import path from "node:path";
import posix from "node:path/posix";
import { Writable } from "node:stream";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const TOOL_DIR = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(TOOL_DIR, "..");
const CONFIG_PATH = process.env.FTP_SYNC_CONFIG || path.join(TOOL_DIR, "config.json");
const STATE_PATH = path.join(TOOL_DIR, "state.json");

// Never transferred or deleted in either direction, even with force.
const HARD_BLOCK = [/^\.git(\/|$)/, /^\.ftp-sync(\/|$)/, /^wp-config\.php$/, /(^|\/)\.ftpquota$/];
const DEFAULT_INCLUDE = ["wp-content/uploads"];
// Generated per-environment or noise. Never PUSHED (a locally generated copy can embed http://oopsmine.test).
const DEFAULT_EXCLUDE = [
  "wp-content/uploads/moderno/**",        // Moderno theme: min.css/min.js/customizer_vars.php (theme rebuilds per host)
  "wp-content/uploads/elementor/css/**",  // Elementor generated CSS — pulled (see below) but never pushed
  "wp-content/uploads/wc-logs/**",        // WooCommerce logs
  "wp-content/uploads/wpcf7_uploads/**",  // Contact Form 7 temporary attachments
  "wp-content/uploads/cache/**",
  "**/*.log", "**/.DS_Store", "**/Thumbs.db", "**/desktop.ini",
];
// PULL exceptions: generated files that are still needed locally because the shared database says they
// exist (Elementor records "css file generated" in post meta, so the local site never rebuilds them).
const PULL_ANYWAY = ["wp-content/uploads/elementor/css/**"];
// Push with no baseline refuses beyond this unless allow_large (guards against a mis-configured include).
const LARGE_FILES = 2000, LARGE_BYTES = 500 * 1024 * 1024;

/* ───────────────────────── helpers ───────────────────────── */

const toPosix = (p) => p.split(path.sep).join("/").replace(/^\.\//, "");
const isHardBlocked = (rel) => HARD_BLOCK.some((re) => re.test(rel));
const fmtBytes = (n) => (n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`);

function globToRegex(glob) {
  let re = "";
  for (let i = 0; i < glob.length; i++) {
    const ch = glob[i];
    if (ch === "*") {
      if (glob[i + 1] === "*") { re += ".*"; i++; if (glob[i + 1] === "/") i++; } else re += "[^/]*";
    } else if (ch === "?") re += "[^/]";
    else re += ch.replace(/[.+^${}()|[\]\\]/g, "\\$&");
  }
  return new RegExp("^" + re + "$");
}

function loadConfig() {
  if (!fs.existsSync(CONFIG_PATH)) {
    throw new Error(`No config at ${CONFIG_PATH}. Copy config.example.json to config.json and fill in the FTP details.`);
  }
  const c = JSON.parse(fs.readFileSync(CONFIG_PATH, "utf8"));
  const protocol = String(c.protocol || "ftps").toLowerCase();
  if (!["ftp", "ftps", "sftp"].includes(protocol)) throw new Error(`config.protocol must be ftp, ftps or sftp (got "${protocol}")`);
  for (const k of ["host", "user", "password"]) if (!c[k]) throw new Error(`config.${k} is required`);
  const include = (c.include?.length ? c.include : DEFAULT_INCLUDE).map((p) => toPosix(posix.normalize(p)).replace(/\/+$/, ""));
  const excludeGlobs = [...DEFAULT_EXCLUDE, ...(c.exclude || [])];
  return {
    protocol,
    host: c.host,
    port: Number(c.port) || (protocol === "sftp" ? 22 : 21),
    user: c.user,
    password: c.password,
    remoteRoot: posix.normalize("/" + (c.remoteRoot || "/")).replace(/\/+$/, "") || "/",
    tlsRejectUnauthorized: c.tlsRejectUnauthorized !== false,
    // Trust-on-first-use pinning for hosts with a self-signed FTP certificate (see `pin` command).
    tlsPinSha256: c.tlsPinSha256 ? String(c.tlsPinSha256).replace(/[^0-9a-f]/gi, "").toUpperCase() : null,
    concurrency: Math.max(1, Math.min(8, Number(c.concurrency) || 3)),
    timeoutMs: Number(c.timeoutMs) || 30000,
    include, excludeGlobs, exclude: excludeGlobs.map(globToRegex), pullAnyway: PULL_ANYWAY.map(globToRegex),
  };
}
const isExcluded = (rel, cfg) => cfg.exclude.some((re) => re.test(rel));
const isExcludedForPull = (rel, cfg) => isExcluded(rel, cfg) && !cfg.pullAnyway.some((re) => re.test(rel));
const inInclude = (rel, cfg) => cfg.include.some((inc) => rel === inc || rel.startsWith(inc + "/"));

const loadState = () => { try { return JSON.parse(fs.readFileSync(STATE_PATH, "utf8")); } catch { return { files: {}, lastSync: null }; } };
const saveState = (s) => fs.writeFileSync(STATE_PATH, JSON.stringify(s, null, 1));

function git(args) {
  return execFileSync("git", ["-c", "core.safecrlf=false", ...args], { cwd: ROOT, encoding: "utf8", maxBuffer: 256 * 1024 * 1024, windowsHide: true });
}
let trackedCache = null;
/** Files git carries — these deploy through GitHub, never through FTP (unless forced). */
function trackedSet() {
  if (!trackedCache) trackedCache = new Set(git(["ls-files", "-z"]).split("\0").filter(Boolean).map(toPosix));
  return trackedCache;
}

async function statFile(rel) {
  try { const st = await fsp.stat(path.join(ROOT, rel)); return st.isFile() ? { rel, size: st.size, mtimeMs: Math.round(st.mtimeMs) } : null; }
  catch { return null; }
}

async function walkFs(rel) {
  const abs = path.join(ROOT, rel);
  let st; try { st = await fsp.stat(abs); } catch { return []; }
  if (st.isFile()) return [rel];
  const out = [];
  for (const e of await fsp.readdir(abs, { withFileTypes: true })) {
    const child = rel ? posix.join(rel, e.name) : e.name;
    if (isHardBlocked(child)) continue;
    if (e.isDirectory()) out.push(...(await walkFs(child)));
    else if (e.isFile()) out.push(child);
  }
  return out;
}

/** The push set: untracked files under the include paths, minus excludes. */
async function listSyncSet(cfg) {
  const tracked = trackedSet();
  const out = [];
  for (const inc of cfg.include) {
    for (const rel of await walkFs(inc)) if (!tracked.has(rel) && !isExcluded(rel, cfg)) out.push(rel);
  }
  return out;
}

/** Compare the snapshot in state.json with the working tree. */
async function planFromState(cfg, state) {
  const set = await listSyncSet(cfg);
  const upload = [];
  for (const rel of set) {
    const f = await statFile(rel);
    if (!f) continue;
    const prev = state.files[rel];
    if (!prev || prev.size !== f.size || prev.mtimeMs !== f.mtimeMs) upload.push(rel);
  }
  const present = new Set(set);
  const orphans = Object.keys(state.files).filter((rel) => !present.has(rel));
  return { upload, orphans };
}

/** Expand explicit paths (files or directories) into project-relative files, applying the rules. */
async function expandPaths(paths, cfg, force) {
  const out = new Set(), blocked = [];
  const tracked = trackedSet();
  for (const raw of paths) {
    const abs = path.resolve(ROOT, raw);
    const rel = toPosix(path.relative(ROOT, abs));
    if (!rel || rel.startsWith("..")) { blocked.push({ path: raw, reason: "outside the project" }); continue; }
    if (isHardBlocked(rel)) { blocked.push({ path: rel, reason: "hard-blocked (never synced)" }); continue; }
    if (!fs.existsSync(abs)) { blocked.push({ path: rel, reason: "does not exist locally" }); continue; }
    let files = await walkFs(rel), kept = [];
    for (const f of files) {
      if (isHardBlocked(f)) continue;
      if (!force && tracked.has(f)) { blocked.push({ path: f, reason: "tracked by git — deploy it via GitHub + cPanel Update; force:true uploads anyway (dirties the server's git tree)" }); continue; }
      if (!force && isExcluded(f, cfg)) { blocked.push({ path: f, reason: "excluded (generated/per-environment); force:true to upload" }); continue; }
      kept.push(f);
    }
    kept.forEach((f) => out.add(f));
  }
  return { files: [...out], blocked: blocked.length > 50 ? [...blocked.slice(0, 50), { path: `… ${blocked.length - 50} more`, reason: "" }] : blocked };
}

/* ───────────────────────── transports ───────────────────────── */

/**
 * TLS policy. With a pinned fingerprint we skip CA-chain checks (cPanel's FTP
 * cert is self-signed, and local AV/proxies may re-sign it anyway) and instead
 * require the exact certificate we trusted on first use — checked on the live
 * control socket BEFORE the password is sent. Without a pin: normal CA checks.
 */
const tlsOptions = (cfg, { insecureProbe = false } = {}) =>
  insecureProbe || cfg.tlsPinSha256 ? { rejectUnauthorized: false } : { rejectUnauthorized: cfg.tlsRejectUnauthorized };
const fp256 = (cert) => String(cert?.fingerprint256 || "").replace(/[^0-9a-f]/gi, "").toUpperCase();
const PIN_HINT = "Pin it once with: node .ftp-sync/server.mjs pin  (re-run only after the host confirms a certificate change)";

class FtpTransport {
  constructor(cfg, opts = {}) { this.cfg = cfg; this.opts = opts; this.dirs = new Set(); }
  async connect() {
    const { Client } = await import("basic-ftp");
    this.c = new Client(this.cfg.timeoutMs);
    const { host, port, user, password, protocol, tlsPinSha256 } = this.cfg;
    try {
      await this.c.connect(host, port);
      if (protocol === "ftps") {
        await this.c.useTLS(tlsOptions(this.cfg, this.opts));
        if (tlsPinSha256 && !this.opts.insecureProbe) {
          const cert = this.peerCertificate();
          if (fp256(cert) !== tlsPinSha256) {
            this.c.close();
            throw new Error(`TLS certificate does not match the pinned fingerprint — refusing to send credentials. Server presented ${cert?.fingerprint256} (${cert?.subject?.CN || "?"}, issued by ${cert?.issuer?.CN || "?"}). ${PIN_HINT}`);
          }
        }
      }
      if (this.opts.skipLogin) return;
      await this.c.login(user, password);
      await this.c.useDefaultSettings();
    } catch (e) {
      const msg = String(e?.message || e);
      if (!tlsPinSha256 && /self[- ]signed|certificate/i.test(msg)) throw new Error(`${msg}. ${PIN_HINT}`);
      throw e;
    }
  }
  peerCertificate() { return this.c.ftp.socket?.getPeerCertificate?.(true) || null; }
  async ensureDir(dir) { if (this.dirs.has(dir)) return; await this.c.ensureDir(dir); this.dirs.add(dir); }
  async upload(local, remote) { await this.ensureDir(posix.dirname(remote)); await this.c.uploadFrom(local, remote); }
  async download(remote) {
    const chunks = [];
    await this.c.downloadTo(new Writable({ write(ch, _e, cb) { chunks.push(ch); cb(); } }), remote);
    return Buffer.concat(chunks);
  }
  async downloadToFile(remote, local) { await fsp.mkdir(path.dirname(local), { recursive: true }); await this.c.downloadTo(local, remote); }
  async list(dir) {
    return (await this.c.list(dir)).map((e) => ({
      name: e.name, type: e.isDirectory ? "dir" : e.isSymbolicLink ? "link" : "file", size: e.size,
      modified: e.modifiedAt ? e.modifiedAt.toISOString() : e.rawModifiedAt || null,
    }));
  }
  async remove(remote) { await this.c.remove(remote); }
  async close() { this.c.close(); }
}

class SftpTransport {
  constructor(cfg, opts = {}) { this.cfg = cfg; this.opts = opts; this.dirs = new Set(); }
  async connect() {
    const Sftp = (await import("ssh2-sftp-client")).default;
    this.c = new Sftp();
    await this.c.connect({ host: this.cfg.host, port: this.cfg.port, username: this.cfg.user, password: this.cfg.password, readyTimeout: this.cfg.timeoutMs });
  }
  peerCertificate() { return null; }
  async ensureDir(dir) { if (this.dirs.has(dir)) return; if (!(await this.c.exists(dir))) await this.c.mkdir(dir, true); this.dirs.add(dir); }
  async upload(local, remote) { await this.ensureDir(posix.dirname(remote)); await this.c.fastPut(local, remote); }
  async download(remote) { return this.c.get(remote); }
  async downloadToFile(remote, local) { await fsp.mkdir(path.dirname(local), { recursive: true }); await this.c.fastGet(remote, local); }
  async list(dir) {
    return (await this.c.list(dir)).map((e) => ({
      name: e.name, type: e.type === "d" ? "dir" : e.type === "l" ? "link" : "file", size: e.size, modified: new Date(e.modifyTime).toISOString(),
    }));
  }
  async remove(remote) { await this.c.delete(remote); }
  async close() { await this.c.end(); }
}

const makeTransport = (cfg, opts) => (cfg.protocol === "sftp" ? new SftpTransport(cfg, opts) : new FtpTransport(cfg, opts));
const remotePath = (cfg, rel) => posix.join(cfg.remoteRoot, rel);

async function withTransport(cfg, fn) {
  const t = makeTransport(cfg);
  await t.connect();
  try { return await fn(t); } finally { await t.close().catch(() => {}); }
}

/** Recursively list remote files under a project-relative directory → [{rel,size}]. */
async function walkRemote(t, cfg, rel) {
  const out = [];
  let entries;
  try { entries = await t.list(remotePath(cfg, rel)); } catch { return out; }
  for (const e of entries) {
    if (e.name === "." || e.name === "..") continue;
    const child = posix.join(rel, e.name);
    if (isHardBlocked(child)) continue;
    if (e.type === "dir") out.push(...(await walkRemote(t, cfg, child)));
    else if (e.type === "file") out.push({ rel: child, size: e.size });
  }
  return out;
}

/** Run `work(item, transport)` over a small pool of connections. Directories are pre-created on one connection to avoid MKD races. */
async function withPool(cfg, items, { preCreateDirs = false, work, onProgress }) {
  if (!items.length) return { done: [], failed: [] };
  const n = Math.min(cfg.concurrency, items.length);
  const pool = [];
  for (let i = 0; i < n; i++) { const t = makeTransport(cfg); await t.connect(); pool.push(t); }
  const done = [], failed = [];
  try {
    if (preCreateDirs) {
      const dirs = [...new Set(items.map((f) => posix.dirname(remotePath(cfg, f.rel))))].sort((a, b) => a.length - b.length);
      for (const d of dirs) await pool[0].ensureDir(d);
      for (const t of pool) dirs.forEach((d) => t.dirs.add(d));
    }
    const queue = [...items];
    const isConnectionLoss = (e) => /closed|FIN packet|ECONNRESET|EPIPE|ETIMEDOUT|timeout|Timeout|421|426|not connected/i.test(String(e?.message || e));
    await Promise.all(pool.map(async (t, slot) => {
      for (;;) {
        const f = queue.shift();
        if (!f) return;
        let lastErr = null;
        for (let attempt = 1; attempt <= 3; attempt++) {
          try { await work(f, t); done.push(f); lastErr = null; break; }
          catch (e) {
            lastErr = e;
            if (!isConnectionLoss(e) || attempt === 3) break;
            // Server dropped this connection (idle/anti-flood/reset): open a fresh one and retry the same file.
            await t.close().catch(() => {});
            await new Promise((r) => setTimeout(r, 1000 * attempt));
            const fresh = makeTransport(cfg);
            try { await fresh.connect(); } catch (ce) { lastErr = ce; continue; }
            fresh.dirs = t.dirs;
            pool[slot] = t = fresh;
          }
        }
        if (lastErr) failed.push({ path: f.rel, error: String(lastErr?.message || lastErr) });
        onProgress?.(done.length + failed.length, items.length, f.rel);
      }
    }));
  } finally {
    await Promise.allSettled(pool.map((t) => t.close()));
  }
  return { done, failed };
}

/* ───────────────────────── operations ───────────────────────── */

/** PUSH: local → server. */
async function runSync({ paths, deleteOrphans = false, dryRun = false, force = false, allowLarge = false, onProgress } = {}) {
  const cfg = loadConfig();
  const state = loadState();
  let upload, del = [], blocked = [], mode;

  if (paths?.length) {
    mode = "paths";
    ({ files: upload, blocked } = await expandPaths(paths, cfg, force));
  } else {
    mode = "snapshot";
    ({ upload, orphans: del } = await planFromState(cfg, state));
  }

  const files = (await Promise.all(upload.map(statFile))).filter(Boolean);
  const toDelete = deleteOrphans ? del : [];
  const bytes = files.reduce((a, f) => a + f.size, 0);

  if (mode === "snapshot" && !Object.keys(state.files).length && !allowLarge && (files.length > LARGE_FILES || bytes > LARGE_BYTES)) {
    throw new Error(`First push would upload ${files.length} files (${fmtBytes(bytes)}). Check config.include/exclude, or pass allow_large:true (or run ftp_mark_synced if the server already has them).`);
  }
  if (dryRun) {
    return { dryRun: true, mode, include: cfg.include, upload: files.map((f) => f.rel), count: files.length, bytes: fmtBytes(bytes), delete: toDelete, orphansNotDeleted: deleteOrphans ? [] : del, blocked };
  }

  const started = Date.now();
  let sent = 0;
  const { done, failed } = await withPool(cfg, files, {
    preCreateDirs: true, onProgress,
    work: async (f, t) => { await t.upload(path.join(ROOT, f.rel), remotePath(cfg, f.rel)); sent += f.size; },
  });
  const deleted = [], deleteFailed = [];
  if (toDelete.length) {
    await withTransport(cfg, async (t) => {
      for (const rel of toDelete) {
        try { await t.remove(remotePath(cfg, rel)); deleted.push(rel); }
        catch (e) { if (/550|no such file|not found/i.test(String(e?.message || e))) deleted.push(rel); else deleteFailed.push({ path: rel, error: String(e?.message || e) }); }
      }
    });
  }
  for (const f of done) state.files[f.rel] = { size: f.size, mtimeMs: f.mtimeMs };
  for (const rel of deleted) delete state.files[rel];
  state.lastSync = new Date().toISOString();
  saveState(state);

  return {
    mode, uploaded: done.map((f) => f.rel), uploadedCount: done.length, bytes: fmtBytes(sent), failed, deleted, deleteFailed,
    orphansNotDeleted: deleteOrphans ? [] : del, blocked, seconds: +((Date.now() - started) / 1000).toFixed(1),
  };
}

/** PULL: server → local, for the include paths. Never overwrites local files unless overwrite:true (size differs). */
async function runPull({ dryRun = false, overwrite = false, includeTracked = false, paths, onProgress } = {}) {
  const cfg = loadConfig();
  const state = loadState();
  // Default: the include paths (media). Explicit paths may be any project folder — e.g. a plugin the
  // demo importer installed on live — but git-tracked files are only replaced with includeTracked:true.
  const roots = paths?.length ? paths.map((p) => toPosix(posix.normalize(p)).replace(/\/+$/, "")) : cfg.include;
  for (const r of roots) if (!r || r.startsWith("..") || isHardBlocked(r)) throw new Error(`"${r}" is not a pullable project path`);
  const tracked = trackedSet();

  const remote = await withTransport(cfg, async (t) => (await Promise.all(roots.map((r) => walkRemote(t, cfg, r)))).flat());
  const download = [], skippedExisting = [], skippedTracked = [], excluded = [];
  for (const f of remote) {
    if (isHardBlocked(f.rel)) continue;
    if (isExcludedForPull(f.rel, cfg)) { excluded.push(f.rel); continue; }
    const isTracked = tracked.has(f.rel);
    const local = await statFile(f.rel);
    if (!local) download.push(f);
    else if (local.size !== f.size) {
      if (isTracked && !includeTracked) skippedTracked.push(f.rel);
      else if (overwrite) download.push(f);
      else skippedExisting.push(f.rel);
    }
  }
  const bytes = download.reduce((a, f) => a + (f.size || 0), 0);
  const report = { roots, count: download.length, bytes: fmtBytes(bytes), sizeDiffersNotOverwritten: skippedExisting, trackedDiffersNotReplaced: skippedTracked, excludedCount: excluded.length };
  if (dryRun) return { dryRun: true, ...report, download: download.map((f) => f.rel) };

  const started = Date.now();
  const { done, failed } = await withPool(cfg, download, {
    onProgress,
    work: async (f, t) => { await t.downloadToFile(remotePath(cfg, f.rel), path.join(ROOT, f.rel)); },
  });
  // Pulled MEDIA is by definition identical to the server → record it so the next push doesn't re-upload it.
  // Code files are deliberately not recorded: the snapshot describes the media push set only.
  for (const f of done) {
    if (!inInclude(f.rel, cfg) || tracked.has(f.rel) || isExcluded(f.rel, cfg)) continue;
    const s = await statFile(f.rel);
    if (s) state.files[f.rel] = { size: s.size, mtimeMs: s.mtimeMs };
  }
  saveState(state);
  return { ...report, downloaded: done.map((f) => f.rel), downloadedCount: done.length, failed, seconds: +((Date.now() - started) / 1000).toFixed(1) };
}

async function markSynced(paths) {
  const cfg = loadConfig();
  const state = loadState();
  const rels = paths?.length ? (await expandPaths(paths, cfg, false)).files : await listSyncSet(cfg);
  let n = 0;
  for (const rel of rels) { const f = await statFile(rel); if (f) { state.files[rel] = { size: f.size, mtimeMs: f.mtimeMs }; n++; } }
  state.lastSync = state.lastSync || new Date().toISOString();
  state.baselineAt = new Date().toISOString();
  saveState(state);
  return { marked: n, totalTracked: Object.keys(state.files).length };
}

/** Trust-on-first-use: fetch the FTPS server certificate and pin its SHA-256 fingerprint in config.json. */
async function pinCertificate() {
  const cfg = loadConfig();
  if (cfg.protocol !== "ftps") throw new Error("pin only applies to protocol \"ftps\"");
  const t = new FtpTransport(cfg, { insecureProbe: true, skipLogin: true }); // no credentials are sent while probing
  await t.connect();
  const cert = t.peerCertificate();
  await t.close().catch(() => {});
  if (!cert || !cert.fingerprint256) throw new Error("could not read the server certificate");
  const raw = JSON.parse(fs.readFileSync(CONFIG_PATH, "utf8"));
  const previous = raw.tlsPinSha256 || null;
  raw.tlsPinSha256 = cert.fingerprint256;
  raw.tlsPinnedSubject = cert.subject?.CN || null;
  raw.tlsPinnedIssuer = cert.issuer?.CN || null;
  fs.writeFileSync(CONFIG_PATH, JSON.stringify(raw, null, 2) + "\n");
  const issuer = cert.issuer?.CN || "";
  const out = {
    pinned: cert.fingerprint256, previous,
    subject: cert.subject?.CN || cert.subject, issuer, validFrom: cert.valid_from, validTo: cert.valid_to,
    note: "Future connections must present exactly this certificate (checked before the password is sent). Re-run pin only after the host confirms a re-issue.",
  };
  if (/kaspersky|avast|avg|eset|bitdefender|norton|mcafee|zscaler|fortinet|sophos|palo alto|proxy|firewall|inspection/i.test(issuer)) {
    out.warning = `The certificate is issued by "${issuer}" — a local antivirus/proxy is intercepting TLS on this machine, so you pinned what IT presents, not the server's own certificate. Fine on this PC; on another machine (or if that software is disabled) run pin again.`;
  }
  return out;
}

async function status() {
  const cfg = loadConfig();
  const state = loadState();
  const out = {
    config: {
      protocol: cfg.protocol, host: cfg.host, port: cfg.port, user: cfg.user, password: "••••••", remoteRoot: cfg.remoteRoot, concurrency: cfg.concurrency,
      tls: cfg.tlsPinSha256 ? `pinned ${cfg.tlsPinSha256.slice(0, 8)}…` : cfg.tlsRejectUnauthorized ? "system CA verification" : "UNVERIFIED",
      include: cfg.include, exclude: cfg.excludeGlobs, configPath: CONFIG_PATH,
    },
    project: ROOT,
    snapshot: { filesTracked: Object.keys(state.files).length, lastSync: state.lastSync, baselineAt: state.baselineAt || null },
  };
  try {
    out.connection = await withTransport(cfg, async (t) => {
      const root = await t.list(cfg.remoteRoot);
      const looksLikeWordPress = root.some((e) => e.name === "wp-content") && root.some((e) => e.name === "wp-includes");
      const includes = {};
      for (const inc of cfg.include) { try { includes[inc] = (await t.list(remotePath(cfg, inc))).length + " entries"; } catch (e) { includes[inc] = "missing on server"; } }
      return { ok: true, remoteRoot: cfg.remoteRoot, looksLikeWordPress, remoteInclude: includes };
    });
  } catch (e) { out.connection = { ok: false, error: String(e?.message || e) }; }
  const p = await planFromState(cfg, state);
  out.pendingPush = { upload: p.upload.length, orphans: p.orphans.length, sampleUpload: p.upload.slice(0, 15) };
  return out;
}

const cap = (arr, n = 200) => (arr.length > n ? [...arr.slice(0, n), `… and ${arr.length - n} more`] : arr);
const capResult = (r) => { for (const k of ["upload", "uploaded", "download", "downloaded", "delete", "deleted", "orphansNotDeleted", "sizeDiffersNotOverwritten"]) if (Array.isArray(r[k])) r[k] = cap(r[k]); return r; };

/* ───────────────────────── MCP server ───────────────────────── */

async function startMcp() {
  const { McpServer } = await import("@modelcontextprotocol/sdk/server/mcp.js");
  const { StdioServerTransport } = await import("@modelcontextprotocol/sdk/server/stdio.js");
  const { z } = await import("zod");

  const server = new McpServer({ name: "ftp-sync", version: "2.0.0" });
  const text = (obj) => ({ content: [{ type: "text", text: typeof obj === "string" ? obj : JSON.stringify(obj, null, 2) }] });
  const guard = (fn) => async (args) => { try { return text(await fn(args ?? {})); } catch (e) { return { isError: true, ...text(`ERROR: ${e?.message || e}`) }; } };

  server.registerTool("ftp_status", {
    title: "FTP sync status",
    description: "Show the sync configuration (password masked), test the connection to the live host, confirm the remote root is the WordPress docroot, and count local media changes pending push.",
    inputSchema: {},
  }, guard(status));

  server.registerTool("ftp_sync", {
    title: "Push local media/ignored files to the live host",
    description:
      "Upload files git does NOT carry (default: wp-content/uploads/) that changed since the last push. Generated caches (uploads/moderno, uploads/elementor/css, logs) are excluded. " +
      "Tracked code is never pushed here — it deploys via GitHub + cPanel Update. Use dry_run:true to preview.",
    inputSchema: {
      dry_run: z.boolean().optional().describe("Only report what would be uploaded/deleted"),
      delete_orphans: z.boolean().optional().describe("Also delete on the server files that were deleted locally (default false)"),
      allow_large: z.boolean().optional().describe("Permit a first push larger than 2000 files / 500 MB"),
    },
  }, guard(({ dry_run, delete_orphans, allow_large }) => runSync({ dryRun: !!dry_run, deleteOrphans: !!delete_orphans, allowLarge: !!allow_large }).then(capResult)));

  server.registerTool("ftp_pull", {
    title: "Pull media from the live host to local",
    description:
      "Download files under the include paths (default wp-content/uploads/) that exist on the server but not locally — e.g. media uploaded through the live admin. " +
      "Existing local files are never overwritten unless overwrite:true (then size-different files are refreshed). Use dry_run:true to preview.",
    inputSchema: {
      dry_run: z.boolean().optional(),
      overwrite: z.boolean().optional().describe("Also replace local files whose size differs from the server copy"),
      include_tracked: z.boolean().optional().describe("Allow replacing git-tracked files (code changed on the server, e.g. plugins a demo importer updated). Commit the result afterwards."),
      paths: z.array(z.string()).optional().describe("Project-relative folders to pull; default = the include paths. May be any folder, e.g. ['wp-content/plugins/some-plugin']"),
    },
  }, guard(({ dry_run, overwrite, include_tracked, paths }) => runPull({ dryRun: !!dry_run, overwrite: !!overwrite, includeTracked: !!include_tracked, paths }).then(capResult)));

  server.registerTool("ftp_upload", {
    title: "Upload specific files or folders",
    description:
      "Upload the given project-relative paths. Git-tracked files and excluded generated files are refused unless force:true (uploading tracked files dirties the server's git tree and disables cPanel's Update button). " +
      "wp-config.php and .git/ are never uploaded, even with force.",
    inputSchema: {
      paths: z.array(z.string()).min(1).describe("Project-relative paths, e.g. ['wp-content/uploads/2026/09']"),
      force: z.boolean().optional(),
      dry_run: z.boolean().optional(),
    },
  }, guard(({ paths, force, dry_run }) => runSync({ paths, force: !!force, dryRun: !!dry_run }).then(capResult)));

  server.registerTool("ftp_download", {
    title: "Download a single file from the live host",
    description: "Fetch a remote file (path relative to remoteRoot, e.g. 'wp-config.php', 'error_log', 'wp-content/debug.log'). Returns text content, or saves to save_to (project-relative) when given.",
    inputSchema: {
      remote_path: z.string(),
      save_to: z.string().optional().describe("Project-relative local path to write the file to instead of returning its content"),
    },
  }, guard(async ({ remote_path, save_to }) => {
    const cfg = loadConfig();
    const buf = await withTransport(cfg, (t) => t.download(remotePath(cfg, remote_path)));
    if (save_to) {
      const abs = path.resolve(ROOT, save_to);
      await fsp.mkdir(path.dirname(abs), { recursive: true });
      await fsp.writeFile(abs, buf);
      return { saved: toPosix(path.relative(ROOT, abs)), bytes: buf.length };
    }
    const isBinary = buf.subarray(0, 8000).includes(0);
    if (isBinary) return { remote_path, bytes: buf.length, note: "binary file — use save_to to store it locally" };
    const s = buf.toString("utf8");
    return s.length > 100000 ? s.slice(0, 100000) + `\n… [truncated, ${buf.length} bytes total]` : s;
  }));

  server.registerTool("ftp_list", {
    title: "List a remote directory",
    description: "List files in a directory on the live host (path relative to remoteRoot; empty for the root).",
    inputSchema: { remote_path: z.string().optional() },
  }, guard(async ({ remote_path }) => {
    const cfg = loadConfig();
    const entries = await withTransport(cfg, (t) => t.list(remotePath(cfg, remote_path || "")));
    return { path: remotePath(cfg, remote_path || ""), count: entries.length, entries: cap(entries, 300) };
  }));

  server.registerTool("ftp_delete", {
    title: "Delete files on the live host",
    description: "DESTRUCTIVE: delete the given remote files (paths relative to remoteRoot). wp-config.php and .git/ are always refused; git-tracked files are refused unless force:true.",
    inputSchema: { paths: z.array(z.string()).min(1), force: z.boolean().optional() },
  }, guard(async ({ paths, force }) => {
    const cfg = loadConfig();
    const state = loadState();
    const tracked = trackedSet();
    const refused = [], deleted = [], failed = [];
    await withTransport(cfg, async (t) => {
      for (const p of paths) {
        const rel = toPosix(posix.normalize(p));
        if (isHardBlocked(rel) || (!force && tracked.has(rel))) { refused.push(rel); continue; }
        try { await t.remove(remotePath(cfg, rel)); deleted.push(rel); delete state.files[rel]; }
        catch (e) { failed.push({ path: rel, error: String(e?.message || e) }); }
      }
    });
    saveState(state);
    return { deleted, failed, refused };
  }));

  server.registerTool("ftp_mark_synced", {
    title: "Set the push baseline without uploading",
    description: "Record the current local media files as already present on the server. Subsequent ftp_sync calls then push only what changes from here.",
    inputSchema: { paths: z.array(z.string()).optional().describe("Limit to these paths; default: the whole include set") },
  }, guard(({ paths }) => markSynced(paths)));

  await server.connect(new StdioServerTransport());
  console.error(`[ftp-sync] MCP server ready — project ${ROOT}`);
}

/* ───────────────────────── CLI ───────────────────────── */

function parseArgs(argv) {
  const flags = {}, rest = [];
  for (const a of argv) {
    if (a.startsWith("--")) { const [k, v] = a.slice(2).split("="); flags[k] = v === undefined ? true : v; }
    else rest.push(a);
  }
  return { flags, rest };
}

function progressPrinter() {
  return (i, n, rel) => process.stdout.write(`\r  [${String(i).padStart(String(n).length)}/${n}] ${rel.slice(-70).padEnd(70)}`);
}

async function watch({ deleteOrphans }) {
  const cfg = loadConfig();
  const dirs = cfg.include.filter((inc) => fs.existsSync(path.join(ROOT, inc)));
  if (!dirs.length) { console.error(`None of the include paths exist locally: ${cfg.include.join(", ")}`); process.exit(1); }
  console.log(`Watching ${dirs.join(", ")} — new/changed media uploads ~1.5s after it lands. Ctrl+C to stop.${deleteOrphans ? " (deletions mirrored)" : ""}`);
  let timer = null, running = false, again = false;
  const flush = async () => {
    if (running) { again = true; return; }
    running = true;
    try {
      const r = await runSync({ deleteOrphans, allowLarge: true, onProgress: progressPrinter() });
      if (r.uploadedCount || r.deleted.length || r.failed.length) {
        process.stdout.write("\r" + " ".repeat(90) + "\r");
        const ts = new Date().toLocaleTimeString();
        r.uploaded.forEach((p) => console.log(`${ts}  ↑ ${p}`));
        r.deleted.forEach((p) => console.log(`${ts}  ✕ ${p}`));
        r.failed.forEach((f) => console.log(`${ts}  ✗ ${f.path}: ${f.error}`));
        console.log(`${ts}  ${r.uploadedCount} uploaded (${r.bytes}) in ${r.seconds}s`);
      }
    } catch (e) { console.error(`\n${new Date().toLocaleTimeString()}  sync error: ${e.message}`); }
    running = false;
    if (again) { again = false; schedule(); }
  };
  const schedule = () => { clearTimeout(timer); timer = setTimeout(flush, 1500); };
  for (const d of dirs) {
    fs.watch(path.join(ROOT, d), { recursive: true }, (_evt, fname) => {
      if (!fname) return;
      const rel = posix.join(d, toPosix(String(fname)));
      if (isHardBlocked(rel) || isExcluded(rel, cfg)) return;
      schedule();
    });
  }
  await flush();
}

async function cli(argv) {
  const { flags, rest } = parseArgs(argv);
  const [cmd, ...args] = rest;
  const print = (o) => console.log(JSON.stringify(o, null, 2));
  const prog = flags["dry-run"] ? undefined : progressPrinter();
  switch (cmd) {
    case "status": return print(await status());
    case "pin": return print(await pinCertificate());
    case "sync": { const r = await runSync({ deleteOrphans: !!flags.delete, dryRun: !!flags["dry-run"], allowLarge: !!flags["allow-large"], onProgress: prog }); process.stdout.write("\n"); return print(capResult(r)); }
    case "pull": { const r = await runPull({ dryRun: !!flags["dry-run"], overwrite: !!flags.overwrite, includeTracked: !!flags["include-tracked"], paths: args, onProgress: prog }); process.stdout.write("\n"); return print(capResult(r)); }
    case "upload": {
      if (!args.length) throw new Error("usage: upload <path…> [--force] [--dry-run]");
      const r = await runSync({ paths: args, force: !!flags.force, dryRun: !!flags["dry-run"], onProgress: prog }); process.stdout.write("\n"); return print(capResult(r));
    }
    case "mark-synced": return print(await markSynced(args));
    case "watch": return watch({ deleteOrphans: !!flags.delete });
    case "ls": {
      const cfg = loadConfig();
      const entries = await withTransport(cfg, (t) => t.list(remotePath(cfg, args[0] || "")));
      return print({ path: remotePath(cfg, args[0] || ""), count: entries.length, entries: cap(entries, 300) });
    }
    case "get": {
      if (!args.length) throw new Error("usage: get <remote path> [local path]");
      const cfg = loadConfig();
      const buf = await withTransport(cfg, (t) => t.download(remotePath(cfg, args[0])));
      if (args[1]) { fs.writeFileSync(path.resolve(ROOT, args[1]), buf); return print({ saved: args[1], bytes: buf.length }); }
      return process.stdout.write(buf.toString("utf8"));
    }
    case "rm": {
      if (!args.length) throw new Error("usage: rm <remote path…>");
      const cfg = loadConfig();
      return print(await withTransport(cfg, async (t) => {
        const deleted = [], failed = [];
        for (const p of args) { if (isHardBlocked(p)) { failed.push({ path: p, error: "hard-blocked" }); continue; } try { await t.remove(remotePath(cfg, p)); deleted.push(p); } catch (e) { failed.push({ path: p, error: e.message }); } }
        return { deleted, failed };
      }));
    }
    default:
      console.log(`ftp-sync — media & non-git files between local and the live host
  node .ftp-sync/server.mjs status
  node .ftp-sync/server.mjs pin                          trust the host's FTPS certificate once
  node .ftp-sync/server.mjs sync   [--dry-run] [--delete] [--allow-large]   push local uploads/ changes
  node .ftp-sync/server.mjs pull   [--dry-run] [--overwrite] [--include-tracked] [folder…]   fetch server files missing locally
  node .ftp-sync/server.mjs upload <path…> [--force] [--dry-run]
  node .ftp-sync/server.mjs mark-synced [path…]
  node .ftp-sync/server.mjs watch  [--delete]                                 auto-push as media lands locally
  node .ftp-sync/server.mjs ls [remote dir] | get <remote file> [local] | rm <remote file…>
  (no command)  → run as an MCP server over stdio`);
  }
}

if (process.argv.length > 2) {
  cli(process.argv.slice(2)).catch((e) => { console.error(`error: ${e.message}`); process.exit(1); });
} else {
  startMcp().catch((e) => { console.error(`[ftp-sync] fatal: ${e.stack || e}`); process.exit(1); });
}
