<?php
/*
 * Client Portal
 * Security and compliance: the reduced views an administrator or technician chose to publish
 * (the client's own compliance work, and the provider's own security posture). Never the live admin view.
 */

ob_start();
require_once "includes/inc_all.php";

use RivetCore\Compliance\Framework;
use RivetMSP\Compliance\ComplianceService;

$own = null;   // this client's own compliance report
$msp = null;   // the provider's posture
try {
    if (ComplianceService::subjectsReady($mysqli)) {
        $own = ComplianceService::subjects($mysqli)->shared((int) $session_client_id);
    }
    if (ComplianceService::sharedReady($mysqli)) {
        $msp = ComplianceService::shared($mysqli)->current();
    }
} catch (\Throwable $e) {
    $own = $own ?? null;
    $msp = $msp ?? null;
}
if ($own === null && $msp === null) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

$fw = (string) ($_GET['framework'] ?? '');
$fw = Framework::isValid($fw) ? $fw : '';
$keep = static fn (array $row): bool => $fw === '' || in_array($fw, $row['frameworks'], true);
$auto_badge = ['pass' => 'success', 'warn' => 'warning text-dark', 'fail' => 'danger', 'na' => 'secondary', 'error' => 'secondary'];
$state = ['current' => ['Current', 'success'], 'due_soon' => ['Due soon', 'warning text-dark'], 'overdue' => ['Overdue', 'danger'], 'never' => ['Not yet reviewed', 'secondary']];

$render = static function (array $shared, string $heading) use ($fw, $keep, $auto_badge, $state): void {
    $view = $shared['view'];
    ?>
<div class="card mb-4">
    <div class="card-body">
        <h3 class="mb-1"><?= nullable_htmlentities($heading) ?></h3>
        <p class="text-muted mb-2">Published <?= nullable_htmlentities(date('M j, Y', strtotime($shared['published_at']))) ?>, based on an assessment taken <?= nullable_htmlentities(date('M j, Y', strtotime($shared['taken_at']))) ?>.</p>
        <?php if ($shared['note']) { ?><p class="mb-2"><?= nl2br(nullable_htmlentities($shared['note'])) ?></p><?php } ?>
        <p class="small text-muted mb-0">This is a self-assessment against common security controls. It is not a certification or an audit opinion.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($view['scores'] as $s) {
        if ($s['key'] !== 'all' && $s['score'] === null) { continue; }
        $tone = $s['score'] === null ? 'secondary' : ($s['score'] >= 80 ? 'success' : ($s['score'] >= 50 ? 'warning' : 'danger')); ?>
        <div class="col-6 col-md-4 col-xl">
            <a class="text-decoration-none" href="?<?= $s['key'] === 'all' ? '' : 'framework=' . urlencode($s['key']) ?>">
                <div class="card h-100 <?= ($s['key'] === 'all' ? $fw === '' : $fw === $s['key']) ? 'border-primary' : '' ?>">
                    <div class="card-body py-3">
                        <div class="small text-muted"><?= nullable_htmlentities($s['label']) ?></div>
                        <div class="fs-3 fw-semibold text-<?= $tone ?>"><?= $s['score'] === null ? '&mdash;' : nullable_htmlentities((string) $s['score']) . '%' ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php } ?>
</div>

<?php if ($view['automatic']) { ?>
<div class="card mb-4">
    <div class="card-header"><strong>Technical controls</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Control</th><th>Area</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($view['automatic'] as $r) { if (!$keep($r)) { continue; } ?>
            <tr><td><?= nullable_htmlentities($r['title']) ?></td><td class="text-muted"><?= nullable_htmlentities($r['category']) ?></td><td><span class="badge text-bg-<?= $auto_badge[$r['status']] ?? 'secondary' ?>"><?= nullable_htmlentities($r['status_label']) ?></span></td></tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>
<?php } ?>

<div class="card mb-4">
    <div class="card-header"><strong>Policies and reviews</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Item</th><th>Area</th><th>Status</th><th>Last reviewed</th></tr></thead>
        <tbody>
        <?php foreach ($view['manual'] as $r) { if (!$keep($r)) { continue; } $st = $state[$r['state']] ?? [$r['state'], 'secondary']; ?>
            <tr><td><?= nullable_htmlentities($r['title']) ?></td><td class="text-muted"><?= nullable_htmlentities($r['category']) ?></td><td><span class="badge text-bg-<?= $st[1] ?>"><?= nullable_htmlentities($st[0]) ?></span></td><td><?= $r['reviewed_on'] ? nullable_htmlentities($r['reviewed_on']) : '&mdash;' ?></td></tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>
<?php
};

if ($own !== null) {
    $render($own, 'Your compliance');
}
if ($msp !== null) {
    $render($msp, 'Our security posture');
}

require_once "includes/footer.php";
