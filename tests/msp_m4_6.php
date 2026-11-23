<?php
// MSP-DS-173 (re-review M4-6, M3-11, M3-16): in the no-anchor window "never sealed" was decided from DB state. A DB-only writer edited a row, NULLed the hashes
// and deleted the head / mode / last-verify settings: verify() said 'empty', Nightly cleared the alert, ensureMode() wrote a genesis anchor and seal() re-keyed the
// edited rows (sim B); even without the settings reset seal() was a keyed-hash oracle and the one-time step accepted the result (sim C). Real AuditChain, scratch DB.
// Run: SCRATCH_TABLES="audit_events platform_settings recovery_alerts recovery_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m4_6.php
require __DIR__ . '/lib/audit_chain_m3.php';
$GLOBALS['config_audit_anchor_file'] = $anchor;

/** A keyed install as 26.10.12 left it: 5 sealed rows, a head without mac, no anchor file. */
$legacy = function () use ($reset, $add, $chain, $set, $db, $anchor) {
    $reset(); $add(5);
    $c = $chain(); $c->seal();
    $last = $db->query('SELECT audit_id, row_hash FROM audit_events ORDER BY audit_id DESC LIMIT 1')->fetch_assoc();
    $set('audit_chain_head', json_encode(['id' => (int) $last['audit_id'], 'hash' => $last['row_hash']]));
    $set('audit_chain_last_verify', json_encode(['at' => gmdate('Y-m-d H:i:s'), 'status' => 'ok', 'checked' => 5]));
    @unlink($anchor);
};
$row3 = fn () => (int) $db->query('SELECT audit_id FROM audit_events ORDER BY audit_id LIMIT 1 OFFSET 2')->fetch_row()[0];
$unsealed = fn () => (int) $db->query('SELECT COUNT(*) FROM audit_events WHERE row_hash IS NULL')->fetch_row()[0];
$attack = function () use ($db, $row3) {
    $id = $row3();
    $db->query("UPDATE audit_events SET summary = 'EDITED BY ATTACKER' WHERE audit_id = $id");
    $db->query('UPDATE audit_events SET row_hash = NULL, prev_hash = NULL');
    $db->query("DELETE FROM platform_settings WHERE setting_key IN ('audit_chain_head', 'audit_chain_mode', 'audit_chain_last_verify')");
};

// ---- sim B: edit + NULL + delete the settings, then the app runs (verify, nightly, the next audited event) ----
$legacy(); $attack();
$r = $chain()->verify();
$ok('B verify() on the attacked install is anchor_missing, not empty/ok', $r['status'] === 'broken' && ($r['cause'] ?? '') === 'anchor_missing');
\RivetMSP\Platform\Nightly::verifyAuditChain($db);
$open = (int) $db->query("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'")->fetch_row()[0];
$ok('B the nightly run raises (and keeps) the anchor-missing alert', $open >= 1);
$db->query("INSERT INTO audit_events (event_type, action, summary) VALUES ('test', 'x', 'new event')");
$c = $chain(); $sealed = $c->seal();
$ok('B seal() with no anchor file seals nothing (no keyed-hash oracle)', $sealed === [] && $unsealed() === 6);
$ok('B ...and says why', method_exists($c, 'sealBlockedReason') && $c->sealBlockedReason() !== null);
$ok('B ...and writes no genesis anchor from database state', !is_file($anchor));
$r = $chain()->verify();
$ok('B verify() afterwards is still anchor_missing (edited row NOT re-keyed)', $r['status'] === 'broken' && ($r['cause'] ?? '') === 'anchor_missing');
\RivetMSP\Platform\Nightly::verifyAuditChain($db);
$open = (int) $db->query("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key = 'audit_chain.broken'")->fetch_row()[0];
$ok('B the alert is not cleared by the second nightly run', $open >= 1);

// wiped trail + settings: nothing to see yet, but a new event must not be sealed into a chain by itself
$legacy(); $db->query('DELETE FROM audit_events'); $db->query('DELETE FROM platform_settings'); @unlink($anchor);
$add(3); $c = $chain(); $c->seal();
$ok('B after a wipe, new events are not sealed without an anchor and no genesis is written', $unsealed() === 3 && !is_file($anchor));
$ok('B ...and verify() reports anchor_missing for them (rows exist, no anchor)', ($chain()->verify()['cause'] ?? '') === 'anchor_missing');

// ---- sim C: settings left alone, only the hashes NULLed and a row edited ----
$legacy();
$id = $row3(); $db->query("UPDATE audit_events SET summary = 'EDITED BY ATTACKER' WHERE audit_id = $id"); $db->query('UPDATE audit_events SET row_hash = NULL, prev_hash = NULL');
$c = $chain(); $ok('C seal() re-keys nothing', $c->seal() === [] && $unsealed() === 5);
$res = $chain()->reanchor(true);
$ok('C the one-time step refuses (entries exist, none sealed) without the explicit new-chain confirmation', !$res['ok'] && !is_file($anchor));
$ok('C ...and the reason says so', str_contains($res['reason'], 'none of them is sealed'));
$res = $chain()->reanchor(false);
$ok('C Re-anchor refuses the same way', !$res['ok'] && !is_file($anchor));
$res = $chain()->reanchor(true, true);
$ok('C with the confirmation it starts a NEW chain, reported as such (and the edit is not vouched for as old)', $res['ok'] && !empty($res['new_chain']) && is_file($anchor) && $res['checked'] === 0);
$s = $chain()->seal();
$ok('C after that the entries are sealed from the genesis on, and verify is ok', count($s) === 5 && $chain()->verify()['status'] === 'ok');

// a sealed row turned back into an unsealed one below a sealed row
$legacy(); $id = $row3();
$db->query("UPDATE audit_events SET summary = 'EDITED BY ATTACKER', row_hash = NULL, prev_hash = NULL WHERE audit_id = $id");
$res = $chain()->reanchor(true);
$ok('C one-time step refuses when an unsealed row sits below a sealed one (even with the confirmation)', !$res['ok'] && !is_file($anchor) && !($chain()->reanchor(true, true)['ok']));
// the tail un-sealed (head in the database is newer than the sealed rows)
$legacy(); $id = $row3();
$db->query("UPDATE audit_events SET summary = 'EDITED BY ATTACKER' WHERE audit_id >= $id"); $db->query("UPDATE audit_events SET row_hash = NULL, prev_hash = NULL WHERE audit_id >= $id");
$res = $chain()->reanchor(true);
$ok('C one-time step refuses when the database head is newer than the sealed rows', !$res['ok'] && !is_file($anchor));

// ---- normal operation still works ----
// legitimate 26.10.12 upgrade: the one-time step still works on an honest chain
$legacy(); $res = $chain()->reanchor(true);
$ok('N honest upgrade: the one-time step succeeds, verify ok, a new event is sealed', $res['ok'] && $res['checked'] === 5 && $chain()->verify()['status'] === 'ok' && ($add(1) || true) && count($chain()->seal()) === 1 && $chain()->verify()['status'] === 'ok');
// fresh install: genesis from setup only
$reset();
$ok('N fresh install: setup wrote the genesis anchor', is_file($anchor));
$add(3); $s = $chain()->seal();
$ok('N ...events are sealed and verify ok', count($s) === 3 && $chain()->verify()['status'] === 'ok');
$c = $chain(); $c->record($c->verify()); $add(2); $chain()->seal();
$ok('N ...and it keeps working with the nightly record', $chain()->verify()['status'] === 'ok');
// setup's genesis only on a truly empty, anchor-less install
$res = $chain()->startFreshChain();
$ok('N the setup genesis refuses on an install that has entries or an anchor', !$res['ok']);
$reset(); @unlink($anchor); $add(2);
$ok('N the setup genesis refuses when audit entries exist', !$chain()->startFreshChain()['ok'] && !is_file($anchor));
// a brand-new empty install without genesis (setup not run) is not an alarm
$reset(); @unlink($anchor);
$ok('N a brand-new empty install without an anchor yet is not broken', in_array($chain()->verify()['status'], ['ok', 'empty'], true));
// restore flow: lost anchor file on a chain that verifies -> Re-anchor (documented) still works
$fresh(5); @unlink($anchor);
$ok('N anchor file lost: verify says anchor_missing', ($chain()->verify()['cause'] ?? '') === 'anchor_missing');
$res = $chain()->reanchor();
$ok('N ...Re-anchor accepts the verifying chain and verify is ok', $res['ok'] && $chain()->verify()['status'] === 'ok');
$reset();
exit($fail ? 1 : 0);
