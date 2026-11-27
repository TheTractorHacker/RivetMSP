<?php
// MSP-DS-173 (re-review M3-14): re-anchor's plain-to-keyed upgrade ignored a valid signed file anchor proving the chain was keyed: edit a row, rehash
// plain, set mode=plain, Re-anchor laundered the edit. reanchor() now consults the file anchor first.
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_14.php
require __DIR__ . '/lib/audit_chain_m3.php';

$fresh(6);
$db->query("UPDATE audit_events SET summary = 'edited' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) + 2 AS m FROM audit_events) t)");
$rehashPlain();
$set('audit_chain_mode', 'plain'); $set('audit_chain_key_id', '');
$res = $chain()->reanchor();
$ok('M3-14 re-anchor of an edited, plain-rehashed chain is refused when the signed anchor proves it was keyed', !$res['ok'] && str_contains($res['reason'], 'do not verify'));
$ok('M3-14 the mode was not flipped to hmac / the edit was not blessed', $get('audit_chain_mode') === 'plain' && $chain()->verify()['status'] === 'broken');

// the same without lowering the mode: edit + plain rehash of the tail only
$fresh(6);
$db->query("UPDATE audit_events SET summary = 'edited' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) + 2 AS m FROM audit_events) t)");
$rehashPlain(); $set('audit_chain_hmac_from', '999');
$ok('M3-14 a lifted hmac_from does not help the re-anchor either', !$chain()->reanchor()['ok']);

// the intended recovery still works: older restored rows under a valid anchor
$fresh(6); $add(2); $chain()->seal(); $c = $chain(); $c->record($c->verify());
$db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 3');
$res = $chain()->reanchor();
$ok('M3-14 a restore (rows older than the anchor) still re-anchors', $res['ok'] && $chain()->verify()['status'] === 'ok');
$reset();
exit($fail ? 1 : 0);
