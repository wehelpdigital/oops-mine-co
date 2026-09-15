# ftp-sync

Keeps the files **git does not carry** in sync between this machine and the live host
over FTPS / FTP / SFTP — in both directions.

| What | How it deploys |
|---|---|
| Code: core, themes, plugins (git-tracked) | commit → push to GitHub → cPanel **Update from Remote** |
| Media & other ignored data: `wp-content/uploads/` | **this tool** |
| `wp-config.php`, `.htaccess` | by hand, per environment (never touched by this tool) |

Runs two ways:

| Mode | How | Use |
|---|---|---|
| **MCP server** | registered in `../.mcp.json`; Claude Code loads it at startup | Claude calls `ftp_sync` / `ftp_pull` / `ftp_upload` / `ftp_download` / `ftp_list` / `ftp_delete` / `ftp_status` / `ftp_mark_synced` |
| **CLI / watcher** | `node .ftp-sync/server.mjs <command>` | `watch` in a terminal auto-pushes media as it lands locally |

## One-time setup

1. `cd .ftp-sync && npm install`
2. Copy `config.example.json` → `config.json`, fill in the FTP details (git-ignored, never committed).
   Use a dedicated cPanel FTP account whose *Directory* is the site's **document root** — cPanel's
   form defaults to `<domain>/<login>`; delete that suffix, or the account is jailed one level too deep.
3. `node .ftp-sync/server.mjs pin` — cPanel's FTP service uses a self-signed certificate; trust it once
   (like SSH's known_hosts). Every later connection must present exactly that certificate, checked
   **before** the password is sent. If a local antivirus/proxy intercepts TLS the output warns you
   (e.g. issuer "Kaspersky …"); that pin is valid on that PC only — re-pin elsewhere.
4. `node .ftp-sync/server.mjs status` — must report `looksLikeWordPress: true` for the remote root.
5. `node .ftp-sync/server.mjs pull --dry-run` then `pull` to fetch media that already lives on the server.
6. Restart Claude Code once so the MCP server is loaded (`/mcp` lists **ftp-sync**).

## Rules

- **Sync set** = files under `include` (default `wp-content/uploads`) that git does not track,
  minus `exclude`. Built-in excludes are generated per-environment artefacts and noise:
  `uploads/moderno/**`, `uploads/elementor/css/**`, `uploads/wc-logs/**`, `uploads/wpcf7_uploads/**`,
  `uploads/cache/**`, `**/*.log`, OS junk. Live regenerates those itself.
- **Git-tracked files are refused** (push and delete) unless `force:true` — uploading them makes the
  server's git working tree dirty, which greys out cPanel's Update button.
- **Never**, even with force: `wp-config.php`, `.git/`, `.ftp-sync/`.
- **Push** (`sync`) detects changes with a size+mtime snapshot (`state.json`); deletions are only
  mirrored with `delete_orphans:true` / `--delete`.
- **Pull** downloads server files missing locally; existing local files are only replaced with
  `overwrite:true` / `--overwrite` (size differs). Pulled media is recorded so it isn't pushed back.
  `uploads/elementor/css/**` is pulled even though it is never pushed: Elementor records "CSS file
  generated" in post meta, which lives in the shared database, so the local site never rebuilds those
  files itself. After editing pages on live, run `pull --overwrite` to refresh them locally.
  Any project folder can be pulled explicitly (e.g. a plugin installed on live); git-tracked files are
  only replaced with `include_tracked:true` / `--include-tracked` — commit the result afterwards.

## CLI

```
node .ftp-sync/server.mjs status
node .ftp-sync/server.mjs pin
node .ftp-sync/server.mjs sync   [--dry-run] [--delete] [--allow-large]
node .ftp-sync/server.mjs pull   [--dry-run] [--overwrite] [folder…]
node .ftp-sync/server.mjs upload <path…> [--force] [--dry-run]
node .ftp-sync/server.mjs mark-synced [path…]
node .ftp-sync/server.mjs watch  [--delete]
node .ftp-sync/server.mjs ls [remote dir] | get <remote file> [local] | rm <remote file…>
node .ftp-sync/server.mjs db-pull [--dry-run]
```

## Database: local copy, refreshed from live

The local site runs on its **own** copy of the database (XAMPP MariaDB, port 3307) — pages, menus
and settings you create locally stay local, and pages load in ~1 s instead of 15–20 s against the
remote DB. `db-pull` refreshes that copy from live:

1. `mysqldump` of the live DB over the whitelisted remote-MySQL connection (`config.db.remote`)
2. backup of the current local DB to `.ftp-sync/db/local-backup-<time>.sql`
3. drop + re-import into `config.db.local`
4. serialization-safe URL rewrite using `config.db.replace` (live URL → `http://oopsmine.test`, incl. the
   JSON-escaped form Elementor stores, and the server's absolute path)

**It overwrites everything local-only in the DB** (new pages, plugin settings…) — that is what the backup
is for. Publishing local content *to* live is a separate, deliberate step (see the project notes).


`config.json` keys: `protocol` (ftps|ftp|sftp), `host`, `port`, `user`, `password`, `remoteRoot`,
`include` (project-relative folders), `exclude` (extra globs, added to the built-ins),
`tlsPinSha256` (written by `pin`), `tlsRejectUnauthorized` (only matters without a pin),
`concurrency` (1–8, default 3), `timeoutMs`.

If Claude Code cannot start the server from the relative path in `.mcp.json`, replace
`".ftp-sync/server.mjs"` with the absolute path to this file.
