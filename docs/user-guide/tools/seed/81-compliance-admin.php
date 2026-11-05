<?php
/*
 * Group 13 (Access and compliance), seed 81: the provider's OWN compliance status (Administration > Settings > Compliance and
 * Compliance status): the ISO/IEC 27001 retention preset, reviews on the manual checklist, two snapshots (one older) and the
 * newer one shared on the client portal. Per-client compliance is seed 12. Everything is invented.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/81-compliance-admin.php
 *
 * Idempotent: does nothing when the company already has a review or snapshot.
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "81-compliance-admin: no config.php in '$app'.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../vendor/autoload.php';
require_once '../includes/event_bus.php';

use RivetMSP\Compliance\ComplianceService;

function q81($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "81-compliance-admin: " . mysqli_error($mysqli) . "\n"); exit(1); } }
function one81($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }

if (!ComplianceService::ready($mysqli)) { echo "81-compliance-admin: compliance tables missing, skipping.\n"; exit(0); }
if ((int) one81("SELECT (SELECT COUNT(*) FROM compliance_attestations WHERE subject_id = 0) + (SELECT COUNT(*) FROM compliance_snapshots WHERE subject_id = 0)") > 0) {
    echo "81-compliance-admin: already seeded.\n"; exit(0);
}
$morgan = (int) one81("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'");
$devon = (int) one81("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example'");
$tz = new DateTimeZone('America/Chicago');
$today = new DateTimeImmutable('today', $tz);
$ago = static fn (int $d): string => $today->modify("-$d days")->format('Y-m-d');

q81("UPDATE settings SET config_compliance_profile = 'iso27001', config_audit_retention_days = 365, config_log_retention = 365, config_core_audit_enabled = 1 WHERE company_id = 1");
\RivetMSP\Core\CoreBridge::reset();
rivetAudit('compliance.settings_changed', $morgan, 'settings', 'compliance', 'update', 'Compliance settings changed', ['before' => ['profile' => 'none', 'audit_days' => 365, 'log_days' => 90, 'audit_recording' => 1], 'after' => ['profile' => 'iso27001', 'audit_days' => 365, 'log_days' => 365, 'audit_recording' => 1]]);

$att = ComplianceService::attestations($mysqli);
$rec = static function (array $batch) use ($att, $ago, $morgan, $devon): void {
    foreach ($batch as $item => [$days, $who, $note]) {
        $att->record($item, $who === 'morgan' ? $morgan : $devon, $who === 'morgan' ? 'Morgan Reyes' : 'Devon Park', $ago($days), null, $note);
    }
};
$snaps = ComplianceService::snapshots($mysqli);
$snap = static function (int $daysAgo, string $trigger) use ($mysqli, $snaps, $morgan, $tz, $today): int {
    $id = $snaps->save(ComplianceService::assess($mysqli), $morgan, $trigger, defined('APP_VERSION') ? APP_VERSION : '26.10.49');
    $at = $today->modify("-$daysAgo days")->setTime(2, 15)->format('Y-m-d H:i:s');
    q81("UPDATE compliance_snapshots SET taken_at = '$at' WHERE snapshot_id = $id");
    return $id;
};

$rec([
    'security_policy_review' => [210, 'morgan', 'Policy approved at the owners meeting; stored in the Lakeshore SharePoint "Policies" library.'],
    'risk_assessment' => [200, 'morgan', 'Annual risk register reviewed.'],
    'access_review' => [100, 'devon', null],
]);
$snap(62, 'scheduled');
$rec([
    'access_review' => [25, 'devon', 'Quarterly review: Casey Lindgren removed, Riley Chen added as read-only.'],
    'offboarding_check' => [40, 'devon', 'Checked the last two leavers; accounts disabled the same day.'],
    'backup_restore_test' => [60, 'devon', 'Restored the demo database to a scratch server in 11 minutes.'],
    'incident_response_test' => [150, 'morgan', 'Tabletop exercise with all technicians.'],
    'security_awareness' => [90, 'morgan', null],
    'supplier_review' => [75, 'morgan', 'Reviewed the RMM, backup and e-mail suppliers.'],
    'physical_security' => [180, 'morgan', null],
]);
$id = $snap(0, 'manual');
ComplianceService::shared($mysqli)->publish($id, 'Lakeshore Managed IT security overview. Questions are welcome at your next business review.', $morgan);
echo "81-compliance-admin: done\n";
