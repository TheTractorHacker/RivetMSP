<?php
// MSP-DS-173 (re-review M4-8): Re-anchor of a plain chain writes the signed anchor FILE first (mode hmac, from N), then the database mirror. If the database write of
// audit_chain_mode failed, the next row was sealed plain (sealKey() read the database) and Re-anchor and the legacy step refused forever. Now the signed file anchor
// decides how rows are sealed, so a failed mirror write self-heals. The failure is simulated with a trigger that rejects that one settings write (real AuditChain).
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m4_8.php
require __DIR__ . '/lib/audit_chain_m3.php';

$real = $GLOBALS['config_settings_enc_key'];
unset($GLOBALS['config_settings_enc_key']); $reset(); $add(4);
$c = $chain(); $c->seal(); $c->record($c->verify());                    // an install without a key: plain chain, rows 1-4
$GLOBALS['config_settings_enc_key'] = $real;
$ok('setup: plain chain of 4 sealed rows, mode plain', (int) $db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NOT NULL')->fetch_row()[0] === 4 && $get('audit_chain_mode') === 'plain');
$headId = (int) $db->query('SELECT MAX(audit_id) FROM audit_events')->fetch_row()[0];

$db->query("CREATE TRIGGER rivetmsp_m48_fail BEFORE INSERT ON platform_settings FOR EACH ROW BEGIN IF NEW.setting_key = 'audit_chain_mode' AND NEW.setting_value = 'hmac' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated lock wait timeout'; END IF; END");
$res = $chain()->reanchor();
$db->query('DROP TRIGGER rivetmsp_m48_fail');
$file = is_file($anchor) ? json_decode(file_get_contents($anchor), true) : [];
$ok('the file was written (mode hmac, from head+1) and the database mode write failed', ($file['mode'] ?? '') === 'hmac' && (int) ($file['from'] ?? -1) === $headId + 1 && $get('audit_chain_mode') === 'plain');

$add(1); $s = $chain()->seal();
$id = (int) $db->query('SELECT MAX(audit_id) FROM audit_events')->fetch_row()[0];
$row = $db->query("SELECT * FROM audit_events WHERE audit_id = $id")->fetch_assoc();
$keyed = \RivetMSP\Audit\AuditChain::rowHash($row, (string) $row['prev_hash'], \RivetMSP\Audit\AuditChain::deriveKey()) === $row['row_hash'];
$ok('the next row is sealed KEYED (layout from the signed file, not from the stale database mode)', count($s) === 1 && $keyed);
$res = $chain()->reanchor();
$ok('Re-anchor now succeeds (it refused forever before)', $res['ok']);
$ok('...and the database mirror is healed: mode hmac, hmac_from = the file\'s from, verify ok', $get('audit_chain_mode') === 'hmac' && (int) $get('audit_chain_hmac_from') === $headId + 1 && $chain()->verify()['status'] === 'ok');
$add(2); $chain()->seal();
$ok('later rows keep sealing and verifying', $chain()->verify()['status'] === 'ok');

exit($fail ? 1 : 0);
