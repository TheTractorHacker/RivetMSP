<?php
// MSP-DS-173 (re-review M3-15): the unsigned-DB-head check returned before the file anchor was compared, so stripping the head's mac turned "rows were
// deleted" into "predates signing: use Re-anchor". The file anchor is compared first.
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_15.php
require __DIR__ . '/lib/audit_chain_m3.php';

$fresh(6);
$db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 2');                       // the cut
$d = json_decode($get('audit_chain_head'), true); unset($d['mac']); $set('audit_chain_head', json_encode($d));   // strip the mac to hide behind "predates signing"
$r = $chain()->verify();
$ok('M3-15 cut + stripped head mac is broken', $r['status'] === 'broken');
$ok('M3-15 ...the reason is the deleted rows, not "predates signing"', !str_contains($r['reason'], 'predates signing') && str_contains($r['reason'], 'anchor file recorded'));

$fresh(6); $d = json_decode($get('audit_chain_head'), true); unset($d['mac']); $set('audit_chain_head', json_encode($d));
$r = $chain()->verify();
$ok('M3-15 a stripped mac on an intact chain is still refused (unsigned DB head)', $r['status'] === 'broken' && str_contains($r['reason'], 'no key signature'));
$reset();
exit($fail ? 1 : 0);
