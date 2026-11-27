<?php
// Shared setup for tests/msp_m3_10 .. msp_m3_20: the REAL RivetMSP\Audit\AuditChain on a scratch database (never the live one), a throwaway anchor file,
// and helpers to build the attacks. Include after setting the "// Run:" scratch tables in the test.
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use RivetMSP\Audit\AuditChain;
use RivetMSP\Platform\PlatformSettings;
$fail = 0;
$ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
$anchor = sys_get_temp_dir() . '/audit_anchor_' . bin2hex(random_bytes(4)) . '.json';
$chain = fn (?int $ret = 0) => new AuditChain($db, null, $anchor, $ret);
$add = function (int $n) use ($db) { for ($i = 0; $i < $n; $i++) { $db->query("INSERT INTO audit_events (event_type, action, summary) VALUES ('test', 'x', 'row')"); } };
$reset = function () use ($db, $anchor, $chain) { $db->query('DELETE FROM audit_events'); $db->query('DELETE FROM platform_settings'); if (is_dir($anchor)) { @rmdir($anchor); } @unlink($anchor); (function ($c) { if (method_exists($c, 'startFreshChain')) { $c->startFreshChain(); } })($chain()); };   // M4-6: what setup does on a fresh install: the genesis anchor
$fresh = function (int $n = 6) use ($reset, $add, $chain) { $reset(); $add($n); $c = $chain(); $c->seal(); $c->record($c->verify()); return $c; };
$set = fn (string $k, string $v) => PlatformSettings::set($db, $k, $v);
$get = fn (string $k) => PlatformSettings::get($db, $k, '');
/** Re-sign the file anchor and the database head with another recorded time (stands in for "it was written back then"). Works on old and new headMac signatures. */
$backdate = function (int $days) use ($db, $anchor, $chain) {
    $m = new ReflectionMethod(AuditChain::class, 'headMac');
    $m->setAccessible(true);
    $c = $chain();
    $sign = function (array $h) use ($m, $c, $days) {
        $h['at'] = gmdate('c', time() - $days * 86400);
        $args = [(int) $h['id'], (string) $h['hash'], $h['at'], (string) $h['kid'], (int) $h['from']];
        if ($m->getNumberOfParameters() > 5) { $h['mode'] = $h['mode'] ?? 'hmac'; $args[] = $h['mode']; }
        $h['mac'] = $m->invokeArgs($c, $args);
        return $h;
    };
    $a = json_decode(file_get_contents($anchor), true);
    file_put_contents($anchor, json_encode($sign($a)));
    $d = json_decode(PlatformSettings::get($db, 'audit_chain_head', ''), true);
    if (is_array($d)) { PlatformSettings::set($db, 'audit_chain_head', json_encode($sign($d))); }
};
/** Put a signed genesis anchor in place without the "no rows yet" guard (tests that build odd histories: a plain prefix then keyed rows). */
$forceGenesis = function () use ($chain) {
    $c = $chain();
    $sh = new ReflectionMethod(AuditChain::class, 'signHead'); $sh->setAccessible(true);
    $wa = new ReflectionMethod(AuditChain::class, 'writeAnchor'); $wa->setAccessible(true);
    return $wa->invoke($c, $sh->invoke($c, 0, AuditChain::GENESIS, 0));
};
/** Rewrite every row's hash UNKEYED (plain SHA-256), relinking prev_hash: what someone without the key does after editing a row. */
$rehashPlain = function () use ($db) {
    $prev = AuditChain::GENESIS;
    $res = $db->query('SELECT * FROM audit_events WHERE row_hash IS NOT NULL ORDER BY audit_id');
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    foreach ($rows as $r) {
        $h = AuditChain::rowHash($r, $prev, null);
        $db->query("UPDATE audit_events SET prev_hash = '$prev', row_hash = '$h' WHERE audit_id = " . (int) $r['audit_id']);
        $prev = $h;
    }
};
