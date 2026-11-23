<?php
// MSP-DS-173 (re-review M3-10): DELETE audit_id <= N with later rows surviving used to read ok because the oldest surviving row follows the head's hash.
// A cut older than the head is accepted only when the retention window explains it. Real AuditChain, scratch DB.
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_10.php
require __DIR__ . '/lib/audit_chain_m3.php';

$fresh(6); $add(2); $c = $chain(); $c->seal();          // anchor at row 6 (recorded); rows 7, 8 sealed since
$db->query('SELECT 1');
$r0 = $chain()->verify();
$ok('setup: the chain with rows after the head verifies', $r0['status'] === 'ok');
$first = (int) $db->query('SELECT MIN(audit_id) FROM audit_events')->fetch_row()[0];
$db->query('DELETE FROM audit_events WHERE audit_id <= ' . ($first + 5));   // up to and including the anchored head row; rows 7, 8 survive and follow its hash
$r = $chain()->verify();
$ok('M3-10 DELETE up to the head with later rows surviving is broken (the survivor follows the head hash)', $r['status'] === 'broken' && str_contains($r['reason'], 'retention'));

// the same cut IS legitimate when the head is older than the retention window
$fresh(6); $add(2); $c = $chain(); $c->seal(); $backdate(40);
$first = (int) $db->query('SELECT MIN(audit_id) FROM audit_events')->fetch_row()[0];
$db->query('DELETE FROM audit_events WHERE audit_id <= ' . ($first + 5));
$ok('M3-10 ...but accepted when the head is older than a 30 day window', $chain(30)->verify()['status'] === 'ok');
$ok('M3-10 ...and not when retention keeps forever', $chain(0)->verify()['status'] === 'broken');

// ordinary retention (oldest rows beyond the head's reach) still works
$fresh(6); $db->query('DELETE FROM audit_events WHERE audit_id <= (SELECT m FROM (SELECT MIN(audit_id) + 1 AS m FROM audit_events) t)');
$ok('M3-10 retention of the oldest rows while the head row survives is still accepted', $chain()->verify()['status'] === 'ok');
$reset();
exit($fail ? 1 : 0);
