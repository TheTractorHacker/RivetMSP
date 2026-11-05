<?php
/*
 * Group 4 (Clients), seed 12: per-client compliance for the user-guide demo (Organization > Client compliance and each
 * client's Compliance page): chosen standards, checklist reviews with reviewer / date / note, saved snapshots (one taken
 * months ago, so the history shows progress) and one snapshot shared with the client's portal users.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/12-client-compliance.php
 *
 * Everything is invented. Depends on 00-core.sql (clients, users by name / e-mail) and on the migrated compliance tables
 * (compliance_subjects, compliance_attestations, compliance_snapshots). A client that already has reviews or snapshots is
 * skipped, so the script is safe to run again. Snapshots are built with a fixed clock so their scores and dates agree.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "12-client-compliance: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../vendor/autoload.php';

use RivetCore\Compliance\Framework;
use RivetCore\Compliance\SubjectCompliance;
use RivetCore\Contracts\ClockInterface;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;

function q12($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "12-client-compliance: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one12($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }

$tz = new DateTimeZone('America/Chicago');
$today = new DateTimeImmutable('today', $tz);
$ago = static fn (int $days): string => $today->modify("-{$days} days")->format('Y-m-d');

/** A clock frozen at a moment, so an old snapshot is scored as it would have been then. */
function frozenClock(DateTimeImmutable $at): ClockInterface
{
    return new class($at) implements ClockInterface {
        public function __construct(private DateTimeImmutable $at) {}
        public function now(): DateTimeImmutable { return $this->at; }
    };
}
function subjectsAt(DateTimeImmutable $at): SubjectCompliance
{
    global $mysqli;
    return new SubjectCompliance(new MysqliDatabaseAdapter($mysqli), frozenClock($at));
}

$users = [];
foreach (['morgan.reyes' => 'Morgan Reyes', 'devon.park' => 'Devon Park', 'jules.whitaker' => 'Jules Whitaker', 'sam.ortiz' => 'Sam Ortiz'] as $local => $name) {
    $id = one12("SELECT user_id FROM users WHERE user_email = '" . mysqli_real_escape_string($mysqli, "$local@lakeshoremsp.example") . "'");
    $users[$local] = ['id' => $id === null ? null : (int) $id, 'name' => $name];
}

// item => [days ago the review was done, reviewer key, note or null]
// "older" is recorded first and snapshotted (at $snapAgo days ago); "newer" is recorded afterwards.
$plans = [
    'Northshore Dental Group' => [
        'frameworks' => [Framework::HIPAA],
        'older' => [
            'policy_review' => [210, 'morgan.reyes', 'Policy approved by Dr. Raman, filed under Documents.'],
            'risk_assessment' => [200, 'morgan.reyes', 'Annual risk analysis workbook completed with the practice.'],
            'asset_inventory' => [150, 'devon.park', null],
            'access_review' => [140, 'devon.park', 'Reviewed with Hannah Brooks; two stale accounts removed.'],
            'mfa_enforced' => [135, 'devon.park', null],
            'baa_in_place' => [140, 'morgan.reyes', 'BAAs on file for the imaging vendor and the billing service.'],
        ],
        'snap_ago' => 125,
        'newer' => [
            'access_review' => [70, 'devon.park', 'Quarterly review done in the office.'],
            'offboarding_check' => [120, 'devon.park', 'Checked the last two leavers; both accounts disabled the same day.'],
            'endpoint_protection' => [190, 'devon.park', null],
            'vuln_scan_pentest' => [300, 'morgan.reyes', 'External scan by a third party; report stored with the risk assessment.'],
            'logging_retention' => [20, 'devon.park', 'Windows security logs kept 12 months.'],
            'encryption_in_place' => [20, 'devon.park', 'All laptops BitLocker; imaging PCs are on an isolated VLAN.'],
            'backup_restore_test' => [95, 'devon.park', 'Restored the practice database to a test server; 40 minutes.'],
            'dr_bcp_test' => [400, 'morgan.reyes', null],
            'security_awareness' => [340, 'jules.whitaker', 'Annual training, 100 percent completion.'],
            'supplier_review' => [60, 'morgan.reyes', null],
            'physical_security' => [60, 'devon.park', 'Server closet locked; key log kept at the front desk.'],
            'policy_review' => [100, 'morgan.reyes', 'Policy re-approved after the Sun Prairie expansion.'],
        ],
        'share_note' => 'Your quarterly HIPAA security review. Items marked Due soon are on our list for next month; we will talk through the rest at the business review.',
    ],
    'Lakeview Family Credit Union' => [
        'frameworks' => [Framework::SOC2, Framework::PCI, Framework::ISO27001],
        'older' => [],
        'snap_ago' => 0,
        'newer' => [
            'policy_review' => [60, 'morgan.reyes', 'Board-approved information security policy.'],
            'risk_assessment' => [90, 'morgan.reyes', null],
            'asset_inventory' => [45, 'devon.park', null],
            'access_review' => [30, 'devon.park', 'Quarterly review with Gordon Pike.'],
            'mfa_enforced' => [30, 'devon.park', null],
            'offboarding_check' => [50, 'devon.park', null],
            'endpoint_protection' => [25, 'devon.park', null],
            'vuln_scan_pentest' => [200, 'morgan.reyes', 'Penetration test by an outside firm in the spring.'],
            'logging_retention' => [25, 'devon.park', null],
            'encryption_in_place' => [25, 'devon.park', null],
            'backup_restore_test' => [200, 'devon.park', 'Restore test is overdue; booked for next month.'],
            'incident_response_test' => [120, 'morgan.reyes', 'Tabletop exercise with the branch managers.'],
            'security_awareness' => [80, 'jules.whitaker', null],
            'supplier_review' => [150, 'morgan.reyes', null],
            'physical_security' => [90, 'devon.park', null],
            'pci_scope_validation' => [110, 'morgan.reyes', 'SAQ completed for the card-issuing processor.'],
            'isms_scope_soa' => [300, 'morgan.reyes', null],
        ],
        'snap_ago' => 20,
        'share_note' => null,
    ],
    'Granite Peak Manufacturing' => [
        'frameworks' => [Framework::NIST171],
        'older' => [],
        'newer' => [
            'policy_review' => [180, 'morgan.reyes', null],
            'asset_inventory' => [75, 'devon.park', 'Plant 1 inventory done; the Administration Building is still to do.'],
            'cui_scope' => [40, 'morgan.reyes', 'CUI limited to the engineering file server.'],
        ],
        'snap_ago' => 0,
        'share_note' => null,
    ],
    'Cedar & Pine Architects' => [
        'frameworks' => [Framework::ISO27001],
        'older' => [],
        'newer' => [
            'asset_inventory' => [30, 'devon.park', null],
            'mfa_enforced' => [14, 'devon.park', 'Enforced for email and the CAD licence server.'],
        ],
        'snap_ago' => 0,
        'share_note' => null,
    ],
];

foreach ($plans as $clientName => $plan) {
    $cid = one12("SELECT client_id FROM clients WHERE client_name = '" . mysqli_real_escape_string($mysqli, $clientName) . "'");
    if ($cid === null) {
        fwrite(STDERR, "12-client-compliance: client '$clientName' not found, skipping.\n");
        continue;
    }
    $cid = (int) $cid;
    $existing = (int) one12("SELECT (SELECT COUNT(*) FROM compliance_attestations WHERE subject_id = $cid) + (SELECT COUNT(*) FROM compliance_snapshots WHERE subject_id = $cid)");
    if ($existing > 0) {
        echo "12-client-compliance: $clientName already has compliance data, skipping.\n";
        continue;
    }
    $now = subjectsAt(new DateTimeImmutable('now', $tz));
    $now->setFrameworks($cid, $plan['frameworks']);

    $record = static function (array $batch) use ($now, $cid, $ago, $users): void {
        foreach ($batch as $item => [$days, $who, $note]) {
            $u = $users[$who];
            $now->record($cid, $item, $u['id'], $u['name'], $ago($days), null, $note);
        }
    };

    // Older reviews, then a snapshot scored as of that day, with its taken_at moved back.
    if ($plan['older']) {
        $record($plan['older']);
        $at = $today->modify('-' . $plan['snap_ago'] . ' days')->setTime(9, 30);
        $sid = subjectsAt($at)->snapshot($cid, $users['morgan.reyes']['id']);
        q12("UPDATE compliance_snapshots SET taken_at = '" . $at->format('Y-m-d H:i:s') . "' WHERE snapshot_id = $sid");
    }
    $record($plan['newer']);

    // A current snapshot (Northshore: today; the others: the number of days given in snap_ago for the newer set).
    $days = $plan['older'] ? 0 : (int) $plan['snap_ago'];
    $at = $today->modify("-{$days} days")->setTime(16, 5);
    $sid = subjectsAt($at)->snapshot($cid, $users['morgan.reyes']['id']);
    q12("UPDATE compliance_snapshots SET taken_at = '" . $at->format('Y-m-d H:i:s') . "' WHERE snapshot_id = $sid");

    if ($plan['share_note'] !== null) {
        $now->share($cid, $sid, $plan['share_note'], $users['morgan.reyes']['id']);
        q12("UPDATE compliance_subjects SET shared_at = '" . $at->format('Y-m-d H:i:s') . "' WHERE subject_id = $cid");
    }
    echo "12-client-compliance: $clientName done.\n";
}
