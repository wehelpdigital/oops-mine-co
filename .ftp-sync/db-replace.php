<?php
/**
 * Serialization-safe search/replace across every text column of a MySQL database.
 * Used by `node .ftp-sync/server.mjs db-pull` after importing the live dump locally.
 *
 * Usage: php db-replace.php '<json>'   where json = {host,port,user,password,name,pairs:[[from,to],...]}
 */
$cfg = json_decode($argv[1] ?? '', true);
if (!$cfg || empty($cfg['pairs'])) { fwrite(STDERR, "db-replace: missing config json\n"); exit(2); }
$pairs = $cfg['pairs'];

$db = @new mysqli($cfg['host'], $cfg['user'], $cfg['password'], $cfg['name'], (int) ($cfg['port'] ?? 3306));
if ($db->connect_errno) { fwrite(STDERR, "db-replace: connect failed: {$db->connect_error}\n"); exit(1); }
$db->set_charset('utf8mb4');

function replace_deep($v, $pairs) {
    if (is_string($v)) {
        if (preg_match('/^[aOs]:\d+:/', $v)) {
            $u = @unserialize($v);
            if ($u !== false) return serialize(replace_deep($u, $pairs));
        }
        foreach ($pairs as [$a, $b]) $v = str_replace($a, $b, $v);
        return $v;
    }
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = replace_deep($x, $pairs); return $v; }
    if (is_object($v)) { foreach (get_object_vars($v) as $k => $x) $v->$k = replace_deep($x, $pairs); return $v; }
    return $v;
}

$tables = array_column($db->query('SHOW TABLES')->fetch_all(), 0);
$total = 0; $per = [];
foreach ($tables as $t) {
    $cols = $db->query("SHOW COLUMNS FROM `$t`")->fetch_all(MYSQLI_ASSOC);
    $pk   = array_values(array_filter($cols, fn($c) => $c['Key'] === 'PRI'));
    $text = array_filter($cols, fn($c) => preg_match('/char|text|blob/i', $c['Type']));
    if (!$pk || !$text) continue;
    $pkn = $pk[0]['Field'];
    foreach ($text as $c) {
        $f = $c['Field'];
        $conds = [];
        foreach ($pairs as $p) {
            $needle  = str_replace('\\', '\\\\', $p[0]); // LIKE needs backslashes doubled
            $conds[] = "`$f` LIKE '%" . $db->real_escape_string($needle) . "%'";
        }
        $rows = $db->query("SELECT `$pkn` AS pk, `$f` AS v FROM `$t` WHERE " . implode(' OR ', $conds));
        while ($r = $rows->fetch_assoc()) {
            $new = replace_deep($r['v'], $pairs);
            if ($new !== $r['v']) {
                $st = $db->prepare("UPDATE `$t` SET `$f` = ? WHERE `$pkn` = ?");
                $st->bind_param('ss', $new, $r['pk']);
                $st->execute();
                $total++;
                $per["$t.$f"] = ($per["$t.$f"] ?? 0) + 1;
            }
        }
    }
}
arsort($per);
echo json_encode(['rowsUpdated' => $total, 'byColumn' => array_slice($per, 0, 12, true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
