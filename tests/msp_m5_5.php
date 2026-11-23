<?php
// MSP-DS-173 (re-review M5-5): every documented restore path runs setup first (genesis anchor signed with a placeholder key), the restore replaces the DB and the
// anchor stays, so verify() said "does not carry a valid key signature" (tampering) or, when the key differed, "this is tampering, not a key change". Now restore.sh
// and the setup wizard move a genesis anchor aside, and verify() calls a genesis anchor in front of restored rows a restore (anchor_missing) / key_changed.
// Real AuditChain on a scratch DB; the real set_genesis_anchor_aside() from deploy/restore.sh is run against the real genesis file startFreshChain() wrote.
// Run: SCRATCH_TABLES="audit_events platform_settings recovery_alerts recovery_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m5_5.php
require __DIR__ . "/lib/audit_chain_m3.php";
use RivetMSP\Audit\AuditChain;
$K0 = bin2hex(random_bytes(32)); $K1 = bin2hex(random_bytes(32));   // K0: setup's placeholder key, K1: the backup's key
$mk = fn (string $k) => new AuditChain($db, $k, $anchor, 0);
$wipe = function () use ($db, $anchor) { $db->query('DELETE FROM audit_events'); $db->query('DELETE FROM platform_settings'); foreach (glob($anchor . '*') ?: [] as $f) { @unlink($f); } };
$ins = function (string $table, array $r) use ($db) {
    $cols = implode(',', array_map(fn ($k) => "`$k`", array_keys($r)));
    $vals = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'", $r));
    $db->query("INSERT INTO $table ($cols) VALUES ($vals)");
};

// the "backup": a chain sealed and verified under K1
$wipe(); $mk($K1)->startFreshChain(); $add(5); $c = $mk($K1); $c->seal(); $c->record($c->verify());
$rows = $db->query('SELECT * FROM audit_events ORDER BY audit_id')->fetch_all(MYSQLI_ASSOC);
$sets = $db->query('SELECT * FROM platform_settings')->fetch_all(MYSQLI_ASSOC);
$ok('backup chain verifies under its own key', $mk($K1)->verify()['status'] === 'ok');

/** setup on the new box (placeholder key K0, genesis anchor), then the restore replaces the database */
$restoreOverSetup = function () use ($db, $wipe, $mk, $K0, $rows, $sets, $anchor, $ins) {
    $wipe(); $mk($K0)->startFreshChain();
    $db->query('DELETE FROM audit_events'); $db->query('DELETE FROM platform_settings');
    foreach ($rows as $r) { $ins('audit_events', $r); }
    foreach ($sets as $r) { $ins('platform_settings', $r); }
    return is_file($anchor);
};

// A: the backup's key was restored too (apply_settings_enc_key); the genesis anchor is signed with K0
$ok('A setup anchor present after the restore', $restoreOverSetup());
$r = $mk($K1)->verify();
$ok('A verify() calls it a restore (anchor_missing), not tampering', $r['status'] === 'broken' && ($r['cause'] ?? '') === 'anchor_missing' && stripos($r['reason'], 'Re-anchor') !== false);
$ok('A ...and does not say "does not carry a valid key signature"', stripos($r['reason'], 'does not carry a valid key signature') === false);
$ok('A sealing stays blocked (no oracle) until Re-anchor', $mk($K1)->seal() === []);

// B: the key was NOT restored (no manifest key): current key is K0, the rows say K1
$restoreOverSetup();
$r = $mk($K0)->verify();
$ok('B a different key is key_changed, not "this is tampering, not a key change"', $r['status'] === 'key_changed' && stripos($r['reason'], 'tampering') === false);

// C: the restore step moves the genesis anchor aside (PHP helper used by the wizard)
$restoreOverSetup();
$has = method_exists(AuditChain::class, 'setGenesisAnchorAside');
$ok('C helper exists', $has);
$moved = $has ? AuditChain::setGenesisAnchorAside($anchor) : null;
$ok('C the genesis anchor is renamed with a timestamp', $moved !== null && !is_file($anchor) && is_file((string) $moved) && str_contains((string) $moved, '.pre-restore-'));
$r = $mk($K1)->verify();
$ok('C then verify() is anchor_missing with Re-anchor advice', $r['status'] === 'broken' && ($r['cause'] ?? '') === 'anchor_missing' && stripos($r['reason'], 'Re-anchor') !== false);
$re = $mk($K1)->reanchor();
$ok('C Re-anchor works and the chain verifies', $re['ok'] && $mk($K1)->verify()['status'] === 'ok');
$ok('C a real (non-genesis) anchor is not moved', $has && AuditChain::setGenesisAnchorAside($anchor) === null && is_file($anchor));

// D: the real restore.sh function (not a copy) on the real genesis file startFreshChain() wrote
$restoreOverSetup();
$sh = sys_get_temp_dir() . '/m55_' . bin2hex(random_bytes(3)); mkdir($sh . '/app/config', 0777, true);
copy($anchor, $sh . '/app/config/audit_chain_head.json');
$root = dirname(__DIR__);
$fn = (string) shell_exec("sed -n '/^set_genesis_anchor_aside() {/,/^}/p' " . escapeshellarg($root . '/deploy/restore.sh'));
file_put_contents($sh . '/fn.sh', $fn);
$run = fn () => (string) shell_exec('bash -c ' . escapeshellarg('source ' . $root . '/deploy/lib/common.sh; source ' . $sh . '/fn.sh; APP_DIR=' . $sh . '/app; set_genesis_anchor_aside; echo rc=$?') . ' 2>&1');
$out = $run();
$left = glob($sh . '/app/config/*') ?: [];
$ok('D restore.sh function renamed the genesis file (timestamped)', trim($fn) !== '' && count($left) === 1 && str_contains($left[0], '.pre-restore-') && str_contains($out, 'rc=0'));
file_put_contents($sh . '/app/config/audit_chain_head.json', json_encode(['id' => 7, 'hash' => str_repeat('a', 64), 'from' => 0]));
$run();
$ok('D a non-genesis anchor is left alone by the shell function', is_file($sh . '/app/config/audit_chain_head.json'));
$main = (string) file_get_contents($root . '/deploy/restore.sh');
$ok('D main() runs it right after import_database', (bool) preg_match('/import_database\s+set_genesis_anchor_aside\s+restore_uploads/', $main));
$ok('D the setup wizard calls the helper after importing the SQL', (bool) preg_match('/importSqlFile\(\$mysqli, \$sqlPath\);.*?setupAuditAnchorAside\(\)/s', (string) file_get_contents($root . '/setup/index.php')));
exec('rm -rf ' . escapeshellarg($sh));
$wipe();
exit($fail ? 1 : 0);
