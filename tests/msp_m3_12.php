<?php
// MSP-DS-173 (re-review M3-12): reanchor() walked a plain-mode chain entirely as plain, but real installs got the key later, so keyed rows follow the plain
// ones and it refused forever. It now walks the segments and sets hmac_from at the boundary.
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_12.php
require __DIR__ . '/lib/audit_chain_m3.php';
use RivetMSP\Audit\AuditChain;

$real = $GLOBALS['config_settings_enc_key'];
unset($GLOBALS['config_settings_enc_key']); $reset(); $add(4); (new AuditChain($db, null, $anchor, 0))->seal();      // sealed before the install had a key
$GLOBALS['config_settings_enc_key'] = $real;
$set('audit_chain_mode', 'hmac'); $set('audit_chain_key_id', substr(hash('sha256', (string) AuditChain::deriveKey()), 0, 12));
$forceGenesis(); $add(3); $chain()->seal();                                                                                         // keyed rows follow the plain ones
$set('audit_chain_mode', 'plain'); $set('audit_chain_key_id', '');                                                 // what the database still says
@unlink($anchor);
$res = $chain()->reanchor();
$ok('M3-12 setup: plain rows then keyed rows (7 sealed)', (int) $db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NOT NULL')->fetch_row()[0] === 7);
$ok('M3-12 re-anchor of plain-then-keyed succeeds', $res['ok'] && $res['checked'] === 7);
$boundary = (int) $db->query('SELECT MIN(audit_id) + 4 FROM audit_events')->fetch_row()[0];
$ok('M3-12 hmac_from is the first keyed row', (int) $get('audit_chain_hmac_from') === $boundary && $get('audit_chain_mode') === 'hmac');
$ok('M3-12 the anchor file binds the same segment start', (int) json_decode(file_get_contents($anchor), true)['from'] === $boundary);
$ok('M3-12 verify is ok afterwards', $chain()->verify()['status'] === 'ok');
$add(2); $chain()->seal(); $ok('M3-12 new rows are keyed and verify', $chain()->verify()['status'] === 'ok');
$db->query("UPDATE audit_events SET summary = 'x' WHERE audit_id = $boundary");
$ok('M3-12 editing a keyed row is detected', $chain()->verify()['status'] === 'broken');
$db->query("UPDATE audit_events SET summary = 'row' WHERE audit_id = $boundary");
$db->query('UPDATE audit_events SET summary = \'x\' WHERE audit_id = ' . ($boundary - 2));
$ok('M3-12 editing a plain row is detected', $chain()->verify()['status'] === 'broken');

// a plain-hashed row AFTER keyed rows is never accepted
$fresh(5); $rehashPlainTail = function () use ($db) { $id = (int) $db->query('SELECT MAX(audit_id) FROM audit_events')->fetch_row()[0]; $r = $db->query("SELECT * FROM audit_events WHERE audit_id = $id")->fetch_assoc(); $h = AuditChain::rowHash($r, $r['prev_hash'], null); $db->query("UPDATE audit_events SET row_hash = '$h' WHERE audit_id = $id"); };
$rehashPlainTail(); @unlink($anchor); $set('audit_chain_mode', 'plain');
$res = $chain()->reanchor();
$ok('M3-12 a plain row after keyed rows is refused', !$res['ok']);
$reset();
exit($fail ? 1 : 0);
