<?php
// MSP-DS-173 re-review MRR-18..21 on the real AuditChain: a forged audit_chain_key_id (18), a full wipe (19), a missing / unreadable anchor file and a
// head without a mac (20), and the supported re-anchor recovery incl. the plain-to-keyed upgrade (21).
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_mrr_18_21.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
require dirname(__DIR__) . '/vendor/autoload.php';
use RivetMSP\Audit\AuditChain;
use RivetMSP\Platform\PlatformSettings;
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
$anchor = sys_get_temp_dir() . '/audit_anchor_' . bin2hex(random_bytes(4)) . '.json';
$chain = fn (?int $ret = null) => new AuditChain($db, null, $anchor, $ret ?? 0);
$add = function (int $n) use ($db) { for ($i = 0; $i < $n; $i++) { $db->query("INSERT INTO audit_events (event_type, action, summary) VALUES ('test', 'x', 'row')"); } };
$reset = function () use ($db, $anchor) { $db->query('DELETE FROM audit_events'); $db->query('DELETE FROM platform_settings'); if (is_dir($anchor)) { @rmdir($anchor); } @unlink($anchor); };
$fresh = function (int $n = 6) use ($reset, $add, $chain) { $reset(); $add($n); $c = $chain(); $c->seal(); $c->record($c->verify()); return $c; };
$set = fn (string $k, string $v) => PlatformSettings::set($db, $k, $v);

// MRR-18: forged key id
$fresh(); $set('audit_chain_key_id', 'xxxxxxxxxxxx');
$db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 2');   // the cut the forged id was meant to hide
$r = $chain()->verify();
$ok('MRR-18 forged key id + cut rows is broken, not "key changed"', $r['status'] === 'broken' && str_contains($r['reason'], 'key id'));
$fresh(); $set('audit_chain_key_id', 'xxxxxxxxxxxx');
$ok('MRR-18 forged key id alone is broken (the key still verifies the signed head)', $chain()->verify()['status'] === 'broken');
$fresh(); $real = $GLOBALS['config_settings_enc_key']; $GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
$ok('MRR-18 a genuinely different key is still reported as key_changed', $chain()->verify()['status'] === 'key_changed');
$GLOBALS['config_settings_enc_key'] = $real;
$fresh(); $a = json_decode(file_get_contents($anchor), true); $a['kid'] = 'aaaaaaaaaaaa'; file_put_contents($anchor, json_encode($a));
$ok('MRR-18 the key id is bound into the anchor signature', $chain()->verify()['status'] === 'broken');

// MRR-19: full wipe
$fresh(); $db->query('DELETE FROM audit_events');
$r = $chain()->verify();
$ok('MRR-19 deleting every sealed row is broken, not empty', $r['status'] === 'broken' && str_contains($r['reason'], 'wiped'));
$fresh(); $db->query('DELETE FROM audit_events'); $add(3); $c = $chain(); $c->seal();   // wiped, then new rows sealed on a fresh chain
$ok('MRR-19 wipe + new rows (oldest row does not follow the head) is broken', $chain()->verify()['status'] === 'broken');
// a head recorded 40 days ago, signed by the real class (headMac via reflection stands in for "it was written back then")
$fresh(); $a = json_decode(file_get_contents($anchor), true); $a['at'] = gmdate('c', time() - 40 * 86400);
$m = (new ReflectionClass(AuditChain::class))->getMethod('headMac'); $m->setAccessible(true);
$a['mac'] = $m->invoke($chain(), (int) $a['id'], $a['hash'], $a['at'], $a['kid'], (int) $a['from'], 'hmac'); file_put_contents($anchor, json_encode($a));
$d = json_decode(PlatformSettings::get($db, 'audit_chain_head', ''), true); $d['at'] = $a['at']; $d['mac'] = $a['mac']; $set('audit_chain_head', json_encode($d));
$db->query('DELETE FROM audit_events');
$ok('MRR-19 retention explains the wipe when the head is older than the window (30 days)', in_array($chain(30)->verify()['status'], ['ok', 'empty'], true));
$ok('MRR-19 ...but not when retention keeps forever', $chain(0)->verify()['status'] === 'broken');

// MRR-20: fail closed
$fresh(); unlink($anchor);
$r = $chain()->verify();
$ok('MRR-20 missing anchor file on a keyed, previously verified install is broken, distinct message', $r['status'] === 'broken' && str_contains($r['reason'], 'missing') && str_contains($r['reason'], 'Re-anchor'));
$fresh(); unlink($anchor); mkdir($anchor);
$r = $chain()->verify();
$ok('MRR-20 an unreadable anchor (not a file) is broken, "unreadable"', $r['status'] === 'broken' && str_contains($r['reason'], 'unreadable'));
$fresh(); file_put_contents($anchor, 'garbage');
$ok('MRR-20 an anchor file with junk is broken', $chain()->verify()['status'] === 'broken');
$fresh(); $d = json_decode(PlatformSettings::get($db, 'audit_chain_head', ''), true); unset($d['mac']); $set('audit_chain_head', json_encode($d));
$r = $chain()->verify();
$ok('MRR-20 a DB head without a mac is broken ("no key signature")', $r['status'] === 'broken' && str_contains($r['reason'], 'no key signature'));
$fresh(); unlink($anchor); $db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 2'); $db->query("DELETE FROM platform_settings WHERE setting_key = 'audit_chain_head'");
$ok('MRR-20 DB head deleted and file gone after a verified chain is broken (cannot hide a cut)', $chain()->verify()['status'] === 'broken');
$reset(); $add(3); $c = $chain(); $c->seal(); $r = $c->verify();
$ok('MRR-20 a brand-new install (never verified, no anchors yet) is still ok', $r['status'] === 'ok');
$ok('MRR-20 clean fresh chain verifies', $fresh()->verify()['status'] === 'ok');

// MRR-21: re-anchor
$fresh(); $add(2); $chain()->seal(); $chain()->record($chain()->verify());   // anchor at the newer head
$db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 3');       // "restore of an older backup": the rows are older than the anchor
$ok('MRR-21 before: an older restored database reads as broken', $chain()->verify()['status'] === 'broken');
$prevHead = json_decode(file_get_contents($anchor), true);
$res = $chain()->reanchor();
$ok('MRR-21 re-anchor succeeds and returns the previous head for the log', $res['ok'] && str_contains($res['previous'], '#' . $prevHead['id']) && $res['head_id'] !== null && $res['head_id'] < $prevHead['id']);
$ok('MRR-21 verify is ok afterwards, anchor file rewritten', $chain()->verify()['status'] === 'ok' && json_decode(file_get_contents($anchor), true)['id'] === $res['head_id']);
$add(2); $c = $chain(); $c->seal(); $ok('MRR-21 the chain keeps sealing and verifying after a re-anchor', $c->verify()['status'] === 'ok');

$fresh(); $db->query("UPDATE audit_events SET summary = 'edited' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) + 2 AS m FROM audit_events) t)");
$res = $chain()->reanchor();
$ok('MRR-21 re-anchor refuses a chain whose rows were edited (cannot launder tampering)', !$res['ok'] && str_contains($res['reason'], 'do not verify') && $chain()->verify()['status'] === 'broken');

$fresh(); unlink($anchor);
$res = $chain()->reanchor();
$ok('MRR-21 re-anchor recovers a lost anchor file', $res['ok'] && is_file($anchor) && $chain()->verify()['status'] === 'ok');

// plain chain sealed before the install had a key, then a key arrives
$reset(); unset($GLOBALS['config_settings_enc_key']); $add(4); $c = new AuditChain($db, null, $anchor, 0); $c->seal(); $c->record($c->verify());
$ok('MRR-21 setup: a keyless install seals a plain chain', PlatformSettings::get($db, 'audit_chain_mode', '') === 'plain');
$GLOBALS['config_settings_enc_key'] = bin2hex(random_bytes(32));
$ok('MRR-21 before: the plain chain on a keyed install is broken', $chain()->verify()['status'] === 'broken');
$add(2); $chain()->seal();   // sealing continues unkeyed until re-anchored, so the chain stays consistent
$res = $chain()->reanchor();
$ok('MRR-21 re-anchor upgrades the plain chain', $res['ok'] && $res['upgraded'] && PlatformSettings::get($db, 'audit_chain_mode', '') === 'hmac');
$add(3); $c = $chain(); $c->seal(); $c->record($c->verify());
$ok('MRR-21 new rows are keyed and the mixed chain verifies', $c->verify()['status'] === 'ok');
$db->query("UPDATE audit_events SET summary = 'rehashed' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) AS m FROM audit_events) t)");
$ok('MRR-21 editing a pre-upgrade plain row is still detected', $chain()->verify()['status'] === 'broken');
$reset();
exit($fail ? 1 : 0);
