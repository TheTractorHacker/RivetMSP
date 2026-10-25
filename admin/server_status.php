<?php
require_once "includes/inc_all_admin.php";

use RivetMSP\Ops\ServerStatus;

$app_root = realpath(__DIR__ . '/..');
$status_service = new ServerStatus($mysqli, $app_root);
$groups = $status_service->run();
$sum = ServerStatus::summarize($groups);
$badge = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
$overall = $sum['fail'] ? ['Needs attention', 'danger'] : ($sum['warn'] ? ['Mostly healthy', 'warning'] : ['All healthy', 'success']);
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-server me-2"></i>Server status</h3>
        <span class="badge bg-<?= $overall[1] ?> fs-6"><?= $overall[0] ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-2">CPU, memory and disk on this server, plus the application/database basics. <?= (int) $sum['ok'] ?> OK, <?= (int) $sum['warn'] ?> to review, <?= (int) $sum['fail'] ?> failing. Reload the page to check again. Redis and scheduled jobs have their own checks on the pages below.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/admin/cron.php"><i class="fas fa-clock me-1"></i>Scheduled jobs</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/settings_redis.php"><i class="fas fa-bolt me-1"></i>Redis</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/backup.php"><i class="fas fa-cloud-upload-alt me-1"></i>Backups</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/update.php"><i class="fas fa-download me-1"></i>Update</a>
        </div>
    </div>
</div>

<?php foreach ($groups as $g) { ?>
<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><?= nullable_htmlentities($g['title']) ?></h4></div>
    <div class="card-body pt-2">
        <ul class="list-group list-group-flush">
        <?php foreach ($g['checks'] as $c) { ?>
            <li class="list-group-item px-0 d-flex gap-3">
                <span class="badge bg-<?= $badge[$c['status']] ?? 'secondary' ?> align-self-start" style="min-width:3.2rem"><?= nullable_htmlentities(strtoupper($c['status'])) ?></span>
                <div><strong><?= nullable_htmlentities($c['label']) ?></strong><div class="text-muted small"><?= nullable_htmlentities($c['detail']) ?></div></div>
            </li>
        <?php } ?>
        </ul>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";
