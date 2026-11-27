<?php
// MSP-DS-173 (re-review M3-16): every keyed install upgraded from 26.10.12 got "Audit trail tampering suspected" on its first nightly run, training admins to
// press Re-anchor. Now: that state (keyed chain, unsigned legacy head, no anchor file) is reported as "anchor file missing" with the one-time step, and
// the step (reanchor(true), admin password + click, never automatic, M4-6: and no application path writes a genesis from database state) verifies the whole chain as keyed, then writes the signed anchor and a MAC'd head.
// Run: SCRATCH_TABLES="audit_events platform_settings recovery_alerts recovery_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_16.php
require __DIR__ . '/lib/audit_chain_m3.php';
$GLOBALS['config_audit_anchor_file'] = $anchor;

/** An install as 26.10.12 left it: sealed, keyed rows, a head without mac, no anchor file. */
$legacy = function () use ($reset, $add, $chain, $set, $db, $anchor) {
    $reset(); $add(5); $c = $chain(); $c->seal();
    $last = $db->query('SELECT audit_id, row_hash FROM audit_events ORDER BY audit_id DESC LIMIT 1')->fetch_assoc();
    $set('audit_chain_head', json_encode(['id' => (int) $last['audit_id'], 'hash' => $last['row_hash']]));
    $set('audit_chain_last_verify', json_encode(['at' => gmdate('Y-m-d H:i:s'), 'status' => 'ok', 'checked' => 5]));
    @unlink($anchor);
};

$legacy();
$r = $chain()->verify();
$ok('M3-16 the upgraded state is reported with its own cause (anchor_missing), not as generic tampering', $r['status'] === 'broken' && ($r['cause'] ?? '') === 'anchor_missing');
$ok('M3-16 ...and the message names the one-time step', str_contains($r['reason'], 'Set up the anchor'));
\RivetMSP\Platform\Nightly::verifyAuditChain($db);
$msg = (string) $db->query("SELECT alert_last_message FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'")->fetch_row()[0];
$ok('M3-16 the first nightly run raises "anchor file missing", not "tampering suspected"', str_contains($msg, 'anchor file missing') && !str_contains($msg, 'tampering'));
$last = json_decode($get('audit_chain_last_verify'), true);
$ok('M3-16 the page can tell (last verify carries the cause)', ($last['cause'] ?? '') === 'anchor_missing');

// the one-time step
$res = $chain()->reanchor(true);
$ok('M3-16 the one-time step succeeds on the keyed chain', $res['ok'] && $res['checked'] === 5 && is_file($anchor));
$ok('M3-16 the anchor file and the database head are signed', !empty(json_decode(file_get_contents($anchor), true)['mac']) && !empty(json_decode($get('audit_chain_head'), true)['mac']));
$ok('M3-16 verify is ok afterwards', $chain()->verify()['status'] === 'ok');
$ok('M3-16 the step refuses to run again while an anchor file exists', !$chain()->reanchor(true)['ok']);
@unlink($anchor);
$ok('M3-16 after the step a missing anchor file is broken whatever the database says', $chain()->verify()['status'] === 'broken');

// strictness: the legacy step does not accept rows hashed without the key
$legacy(); $rehashPlain();
$res = $chain()->reanchor(true);
$ok('M3-16 the one-time step refuses a chain that was rehashed without the key', !$res['ok'] && !is_file($anchor));
$legacy(); $db->query("UPDATE audit_events SET summary = 'edited' WHERE audit_id = (SELECT m FROM (SELECT MIN(audit_id) + 1 AS m FROM audit_events) t)");
$ok('M3-16 ...and an edited row', !$chain()->reanchor(true)['ok']);
$reset();
exit($fail ? 1 : 0);
