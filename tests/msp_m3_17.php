<?php
// MSP-DS-173 (re-review M3-17): the retention window that explains a gap came from DB-writable config_audit_retention_days with no floor on the web and 0 in cron.
// One function (AuditChain::effectiveRetentionDays / retentionFromSettings, used by cron too) applies the compliance and SecurityBaseline floors.
// Run: SCRATCH_TABLES="audit_events platform_settings settings" bash tests/lib/with_scratch_db.sh php tests/msp_m3_17.php
require __DIR__ . '/lib/audit_chain_m3.php';
use RivetMSP\Audit\AuditChain;

$ok('M3-17 retentionFromSettings floors a tiny value at the baseline (90)', method_exists(AuditChain::class, 'retentionFromSettings') && AuditChain::retentionFromSettings(1, 'none') >= 90);
$ok('M3-17 0 keeps forever (no floor turns it into a window)', AuditChain::retentionFromSettings(0, 'none') === 0);
$ok('M3-17 a normal value is untouched', AuditChain::retentionFromSettings(365, 'none') === 365);
$ok('M3-17 cron.php uses the same function', str_contains((string) file_get_contents(dirname(__DIR__) . '/cron/cron.php'), 'AuditChain::retentionFromSettings('));

$wipe = function (int $storedDays, ?int $globalDays, int $headAgeDays) use ($db, $reset, $add, $chain, $backdate, $set) {
    $reset(); $db->query('DELETE FROM settings'); $db->query("INSERT INTO settings (company_id, config_current_database_version, config_audit_retention_days) VALUES (1, '0', $storedDays)");
    if ($globalDays === null) { unset($GLOBALS['config_audit_retention_days']); } else { $GLOBALS['config_audit_retention_days'] = $globalDays; }
    $add(4); $c = $chain(); $c->seal(); $c->record($c->verify()); $backdate($headAgeDays);
    $db->query('DELETE FROM audit_events');
    return new AuditChain($db, null, $GLOBALS['__anchor'], null);   // default retention, as production callers build it
};
$GLOBALS['__anchor'] = $anchor;
$ins = $db->query('SELECT COUNT(*) FROM settings'); $canSettings = $ins !== false;
$ok('M3-17 setup: settings table is there', $canSettings);

// web: stored 1 day (DB writer lowered it) and the global says 1: a wipe of a 40 day old head must NOT be explained
$c = $wipe(1, 1, 40);
$ok('M3-17 web: a stored retention of 1 day does not explain a wipe (floored)', $c->verify()['status'] === 'broken');
// cron: no globals loaded; a legitimate 365 day retention explains a wipe of a head recorded 400 days ago
$c = $wipe(365, null, 400);
$ok('M3-17 cron: the stored retention is used (not 0), a legitimate old gap is explained', in_array($c->verify()['status'], ['ok', 'empty'], true));
$c = $wipe(0, null, 400);
$rr = $c->verify(); $ok("M3-17 keep-forever (0) explains nothing", $rr["status"] === "broken"); if ($rr["status"] !== "broken") { var_dump($rr["status"], $rr["reason"]); }
$reset(); $db->query('DELETE FROM settings');
exit($fail ? 1 : 0);
