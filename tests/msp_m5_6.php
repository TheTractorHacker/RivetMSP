<?php
// MSP-DS-173 (re-review M5-6): while sealing is blocked (no signed anchor: after an upgrade, a restore, a host move) seal() returns [] so the audit copy (syslog /
// file) got nothing, and events only reached it after Re-anchor, or never if retention purged them first. Now unsealed rows are forwarded as they appear, marked
// "sealed":false, remembered by audit id, and not sent again once they are sealed. Real ChainedAudit -> AuditChain -> AuditSink on a scratch DB, sink = a temp file.
// Run: SCRATCH_TABLES="audit_events platform_settings recovery_alerts recovery_settings" bash tests/lib/with_scratch_db.sh php tests/msp_m5_6.php
require __DIR__ . '/lib/audit_chain_m3.php';
use RivetMSP\Audit\AuditChain;
use RivetMSP\Audit\AuditSink;

$sinkDir = sys_get_temp_dir() . '/m56_' . bin2hex(random_bytes(3)); mkdir($sinkDir);
$sink = $sinkDir . '/audit.jsonl';
$GLOBALS['config_audit_anchor_file'] = $anchor;
$lines = function () use ($sink): array {
    $o = [];
    foreach (is_file($sink) ? file($sink, FILE_IGNORE_NEW_LINES) : [] as $l) { $o[] = json_decode($l, true); }
    return $o;
};
$ids = fn (array $l) => array_map(fn ($r) => $r['audit_id'], $l);

// an upgraded / restored keyed install: rows exist, no anchor file, sealing blocked
$reset();
@unlink($anchor);
$set('audit_sink_path', $sink);
$add(3);
$c = $chain();
$sent = AuditSink::class && method_exists($c, 'sealForSink') ? $c->sealForSink() : [];
$ok('sealForSink exists', method_exists($c, 'sealForSink'));
if (method_exists($c, 'sealForSink')) { AuditSink::write($db, $sent, dirname(__DIR__)); }
$ok('blocked: seal() still seals nothing and says why', $chain()->seal() === [] && $c->sealBlockedReason() !== null);
$l = $lines();
$ok('blocked: the 3 new events reached the sink', count($l) === 3);
$ok('blocked: each is marked sealed:false with no hashes', count($l) === 3 && array_reduce($l, fn ($a, $r) => $a && $r['sealed'] === false && $r['row_hash'] === null && $r['prev_hash'] === null, true));

// more events while still blocked: only the new ones are forwarded
$add(2);
$c = $chain(); $x = method_exists($c, 'sealForSink') ? $c->sealForSink() : []; AuditSink::write($db, $x, dirname(__DIR__));
$l = $lines();
$ok('blocked: 2 more events add exactly 2 lines (no repeats)', count($l) === 5 && count(array_unique($ids($l))) === 5);
$c = $chain(); $x = method_exists($c, 'sealForSink') ? $c->sealForSink() : []; AuditSink::write($db, $x, dirname(__DIR__));
$ok('blocked: a run with nothing new writes nothing', count($lines()) === 5);

// Re-anchor (the administrator's step), then seal: the five rows are sealed, none is sent again; a new event is sent sealed
$c = $chain(); $re = $c->reanchor(false, true);   // "start a new chain from here": rows exist but none is sealed
$ok('re-anchor accepted', $re['ok']);
$add(1);
$c = $chain(); $x = method_exists($c, 'sealForSink') ? $c->sealForSink() : []; AuditSink::write($db, $x, dirname(__DIR__));
$l = $lines();
$ok('after Re-anchor: the earlier rows are not sent a second time', count($l) === 6 && count(array_unique($ids($l))) === 6);
$last = end($l);
$ok('after Re-anchor: the new event is sent sealed:true with its hash', $last && ($last['sealed'] ?? null) === true && is_string($last['row_hash']));
$ok('after Re-anchor: the chain verifies and nothing is left unsealed', $chain()->verify()['status'] === 'ok' && $chain()->verify()['unsealed'] === 0);

// without a configured sink nothing is remembered, so switching one on later still forwards what is unsealed
$reset(); @unlink($anchor); $add(2);
$c = $chain(); $x = method_exists($c, 'sealForSink') ? $c->sealForSink() : []; AuditSink::write($db, $x, dirname(__DIR__));
$ok('no sink configured: nothing forwarded and nothing remembered', $x === [] && $get('audit_sink_forwarded_id') === '');

// the real hook: ChainedAudit (what every audit writer uses) feeds the sink the same way
@unlink($sink); $reset(); @unlink($anchor); $set('audit_sink_path', $sink); $set('audit_chain_seal_enabled', '1');
$class = 'RivetMSP\\Audit\\ChainedAudit';
$src = (string) file_get_contents(dirname(__DIR__) . '/src/Audit/ChainedAudit.php');
$ok('ChainedAudit and Nightly use sealForSink', str_contains($src, 'sealForSink') && str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Platform/Nightly.php'), 'sealForSink'));
$db->query("INSERT INTO audit_events (event_type, action, summary) VALUES ('test', 'x', 'one')");
\RivetMSP\Platform\Nightly::run($db, dirname(__DIR__), true);
$l = $lines();
$ok('Nightly with sealing blocked forwards the unsealed event (sealed:false) once', count($l) === 1 && ($l[0]['sealed'] ?? null) === false);
\RivetMSP\Platform\Nightly::run($db, dirname(__DIR__), true);
$ok('...and a second Nightly run does not repeat it', count($lines()) === 1);

exec('rm -rf ' . escapeshellarg($sinkDir)); @unlink($anchor);
exit($fail ? 1 : 0);
