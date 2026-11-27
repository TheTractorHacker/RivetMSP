<?php
// MSP-DS-173: the audit chain's trust anchors are not only in the database. A database-only writer (no key, no file access) who cuts the newest
// rows and moves the stored head, deletes the head, edits its mac or flips the chain to "plain" is caught by the real AuditChain::verify().
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_173.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
use RivetMSP\Audit\AuditChain;
use RivetMSP\Platform\PlatformSettings;
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
$anchor = sys_get_temp_dir() . '/audit_anchor_' . bin2hex(random_bytes(4)) . '.json';
$chain = fn () => new AuditChain($db, null, $anchor);
$add = function (int $n) use ($db) { for ($i = 0; $i < $n; $i++) { $db->query("INSERT INTO audit_events (event_type, action, summary) VALUES ('test', 'x', 'row')"); } };
$reset = function () use ($db, $anchor, $chain) { $db->query('DELETE FROM audit_events'); $db->query('DELETE FROM platform_settings'); @unlink($anchor);; (function ($c) { if (method_exists($c, 'startFreshChain')) { $c->startFreshChain(); } })($chain()); };   // M4-6: setup writes the genesis anchor of a fresh install

(function ($c) { if (method_exists($c, 'startFreshChain')) { $c->startFreshChain(); } })($chain());   // M4-6: what setup does
$add(6); $c = $chain(); $c->seal();
$r = $c->verify(); $c->record($r);
$ok('clean chain verifies and records its head', $r['status'] === 'ok' && $r['checked'] === 6 && is_file($anchor));
$ok('the anchor file is 0600 and carries a mac', (fileperms($anchor) & 0777) === 0600 && !empty(json_decode(file_get_contents($anchor), true)['mac']));
$ok('verifies again with both anchors in place', $chain()->verify()['status'] === 'ok');

// legitimate retention: the oldest rows go, the chain stays valid
$db->query('DELETE FROM audit_events WHERE audit_id <= (SELECT m FROM (SELECT MIN(audit_id) + 1 AS m FROM audit_events) t)');
$ok('retention of the oldest rows is accepted', $chain()->verify()['status'] === 'ok');

// the attack: cut the newest 2 rows, then move the DB head to the new last row
$reset(); $add(6); $c = $chain(); $c->seal(); $c->record($c->verify());
$db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 2');
$last = $db->query('SELECT audit_id, row_hash FROM audit_events ORDER BY audit_id DESC LIMIT 1')->fetch_assoc();
PlatformSettings::set($db, 'audit_chain_head', json_encode(['id' => (int) $last['audit_id'], 'hash' => $last['row_hash']]));   // no key, so no valid mac
$r = $chain()->verify();
$ok('cut newest rows + rewritten DB head is reported broken', $r['status'] === 'broken');
$db->query("DELETE FROM platform_settings WHERE setting_key = 'audit_chain_head'");
$r = $chain()->verify();
$ok('same cut with the DB head deleted is still caught by the anchor file', $r['status'] === 'broken' && str_contains($r['reason'], 'anchor file recorded'));

// a forged DB head with a wrong mac
$reset(); $add(4); $c = $chain(); $c->seal(); $c->record($c->verify());
$h = json_decode(PlatformSettings::get($db, 'audit_chain_head', ''), true); $h['mac'] = str_repeat('0', 64);
PlatformSettings::set($db, 'audit_chain_head', json_encode($h));
$r = $chain()->verify();
$ok('a DB head with a wrong key signature is reported', $r['status'] === 'broken' && str_contains($r['reason'], 'key signature'));

// tampered anchor file
$reset(); $add(4); $c = $chain(); $c->seal(); $c->record($c->verify());
$a = json_decode(file_get_contents($anchor), true); $a['hash'] = str_repeat('a', 64); file_put_contents($anchor, json_encode($a));
$ok('an anchor file whose mac no longer matches is reported', $chain()->verify()['status'] === 'broken');

// plain downgrade on an install that has a key
$reset(); $add(4); $c = $chain(); $c->seal(); $c->record($c->verify());
PlatformSettings::set($db, 'audit_chain_mode', 'plain');
$r = $chain()->verify();
$ok('mode flipped to plain on an install with a key is reported as a downgrade', $r['status'] === 'broken' && str_contains($r['reason'], 'unkeyed'));

// an install without a key keeps working in plain mode (no key to downgrade from)
unset($GLOBALS['config_settings_enc_key']); $reset(); $add(3); $c = new AuditChain($db, null, $anchor); $c->seal();
$r = $c->verify(); $c->record($r);
$ok('a keyless install still seals and verifies', $r['status'] === 'ok' && $r['checked'] === 3);
$ok('a keyless install detects a cut through the anchor file', (function () use ($db, $anchor) { $db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 1'); $db->query("DELETE FROM platform_settings WHERE setting_key = 'audit_chain_head'"); return (new AuditChain($db, null, $anchor))->verify()['status'] === 'broken'; })());
@unlink($anchor);
exit($fail ? 1 : 0);
