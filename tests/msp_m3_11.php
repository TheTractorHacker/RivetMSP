<?php
// MSP-DS-173 (re-review M3-11): a keyed install with no anchor file must not read ok because a database writer deleted audit_chain_head and
// audit_chain_last_verify. A fresh install is fine because SETUP writes its genesis anchor (M4-6: never the application, never from database state; see msp_m4_6.php).
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_11.php
require __DIR__ . '/lib/audit_chain_m3.php';

// upgraded install: a keyed chain exists, there is no anchor file, a DB writer removed the head and the last-verify record
$reset(); $add(5); $c = $chain(); $c->seal(); $c->record($c->verify());
@unlink($anchor);
$db->query("DELETE FROM platform_settings WHERE setting_key IN ('audit_chain_head', 'audit_chain_last_verify')");
$r = $chain()->verify();
$ok('M3-11 keyed chain, no anchor file, no DB head, no last verify: broken, not ok', $r['status'] === 'broken');
$ok('M3-11 ...with a reason that points at the anchor file', str_contains($r['reason'], 'anchor file'));
// cut + same trick
$fresh(6); $db->query('DELETE FROM audit_events ORDER BY audit_id DESC LIMIT 2'); @unlink($anchor);
$db->query("DELETE FROM platform_settings WHERE setting_key IN ('audit_chain_head', 'audit_chain_last_verify')");
$ok('M3-11 a cut plus the deletions above is broken', $chain()->verify()['status'] === 'broken');

// brand-new installs
$reset();
$ok('M3-11 a brand-new install with no rows is fine', in_array($chain()->verify()['status'], ['ok', 'empty'], true));
$reset(); $add(3); $c = $chain(); $c->seal();      // setup's genesis, sealed, never verified
$ok('M3-11 a fresh install (genesis written by setup) that only sealed rows (before its first verify) has an anchor', is_file($anchor));
$ok('M3-11 ...and verifies ok', $chain()->verify()['status'] === 'ok');
$c = $chain(); $c->record($c->verify()); $add(2); $chain()->seal();
$ok('M3-11 ...and keeps verifying as rows are added', $chain()->verify()['status'] === 'ok');
$reset();
exit($fail ? 1 : 0);
