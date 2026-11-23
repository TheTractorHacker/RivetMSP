<?php
// MSP-DS-173 (re-review M3-19): reanchor() ignored storeHead() returning false and sent the administrator back to its own "use Re-anchor" advice forever.
// It now reports the file permission problem (path and why) and changes nothing else.
// Run: SCRATCH_TABLES="audit_events platform_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_19.php
require __DIR__ . '/lib/audit_chain_m3.php';
use RivetMSP\Audit\AuditChain;

$bad = '/proc/rivetmsp_no_such_dir/audit_chain_head.json';   // cannot be created or written
$reset(); $add(4);
$chain()->seal();   // M4-6: sealing needs the real anchor (genesis); the bad path is only for the re-anchor
$before = [$get('audit_chain_mode'), $get('audit_chain_head'), $get('audit_chain_hmac_from')];
$res = (new AuditChain($db, null, $bad, 0))->reanchor();
$ok('M3-19 re-anchor fails when the anchor file cannot be written', !$res['ok']);
$ok('M3-19 the reason names the file and the permission problem, not "use Re-anchor"', str_contains($res['reason'], $bad) && preg_match('/writable|permission|created/i', $res['reason']) && !str_contains($res['reason'], 'use Re-anchor'));
$ok('M3-19 nothing else was changed in the database', [$get('audit_chain_mode'), $get('audit_chain_head'), $get('audit_chain_hmac_from')] === $before);
$res = (new AuditChain($db, null, $bad, 0))->reanchor(true);
$ok('M3-19 the one-time step reports the same problem', !$res['ok'] && str_contains($res['reason'], $bad));
// with a writable location the same state recovers
$res = $chain()->reanchor();
$ok('M3-19 and succeeds once the location is writable', $res['ok'] && is_file($anchor));
$reset();
exit($fail ? 1 : 0);
