<?php
// MSP-DS-173 (re-review M3-13): audit_chain_hmac_from lives in the database and switched HMAC checking off below it. With no anchor a plain rehash plus
// hmac_from = max + 1 read ok. hmac_from is now bound in the signed anchor and the database value only has to agree with it.
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_13.php
require __DIR__ . '/lib/audit_chain_m3.php';

// DB-only attacker, no anchor file anywhere: edit, rehash the whole chain unkeyed, lift hmac_from above every row, drop the head and last verify
$fresh(6); @unlink($anchor);
$db->query("UPDATE audit_events SET summary = 'edited' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) + 2 AS m FROM audit_events) t)");
$rehashPlain();
$max = (int) $db->query('SELECT MAX(audit_id) FROM audit_events')->fetch_row()[0];
$set('audit_chain_hmac_from', (string) ($max + 1));
$db->query("DELETE FROM platform_settings WHERE setting_key IN ('audit_chain_head', 'audit_chain_last_verify')");
$ok('M3-13 plain rehash + hmac_from=max+1 with no anchor is not ok', $chain()->verify()['status'] !== 'ok');

// the anchor file is present: the database copy of hmac_from cannot disagree with it
$fresh(6);
$db->query("UPDATE audit_events SET summary = 'edited' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) + 2 AS m FROM audit_events) t)");
$rehashPlain();
$max = (int) $db->query('SELECT MAX(audit_id) FROM audit_events')->fetch_row()[0];
$set('audit_chain_hmac_from', (string) ($max + 1));
$r = $chain()->verify();
$ok('M3-13 with the signed anchor present the lifted hmac_from is broken', $r['status'] === 'broken');

// the segment start is in the signed anchor: tampering the file's from breaks its signature
$fresh(6); $a = json_decode(file_get_contents($anchor), true); $ok('M3-13 the signed anchor carries from', array_key_exists('from', $a));
$a['from'] = 99; file_put_contents($anchor, json_encode($a));
$ok('M3-13 editing from in the anchor file breaks its signature', $chain()->verify()['status'] === 'broken');
$fresh(6); $ok('M3-13 an untouched chain still verifies', $chain()->verify()['status'] === 'ok');
$reset();
exit($fail ? 1 : 0);
