<?php

require_once "includes/inc_all.php";

use RivetCore\Compliance\Framework;
use RivetMSP\Compliance\ComplianceService;

// Perms
enforceUserPermission('module_client');

$ready = ComplianceService::subjectsReady($mysqli);

// Every client this user may see, with whatever compliance work exists for it.
$clients = [];
$res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_lead = 0 $access_permission_query ORDER BY client_name");
while ($res && ($c = mysqli_fetch_assoc($res))) {
    $clients[(int) $c['client_id']] = $c['client_name'];
}
$rows = $ready ? ComplianceService::subjects($mysqli)->overview() : [];
$rows = array_values(array_filter($rows, static fn (array $r) => isset($clients[$r['subject_id']])));
$tracked = array_column($rows, 'subject_id');
$untracked = array_diff_key($clients, array_flip($tracked));
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-clipboard-list me-2"></i>Client compliance</h3>
    </div>
    <div class="card-body">
        <?php if (!$ready) { ?>
            <div class="alert alert-warning mb-0">Run the database update first (Administration &rarr; Update) to turn on customer compliance.</div>
        <?php } else { ?>
        <p class="text-muted small">Clients with compliance standards chosen. Open a client's Compliance tab to choose standards and record reviews. Scores measure how much of the listed checklist is evidenced; they are not certifications.</p>
        <?php if (!$rows) { ?>
            <p class="text-muted mb-0">No client has standards selected yet.</p>
        <?php } else { ?>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle">
            <thead><tr><th>Client</th><th>Standards</th><th>Score</th><th>Current</th><th>Due soon</th><th>Overdue / never</th><th>Last snapshot</th><th>Shared</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r) { $tone = $r['score'] === null ? 'secondary' : ($r['score'] >= 80 ? 'success' : ($r['score'] >= 50 ? 'warning' : 'danger')); ?>
                <tr>
                    <td><a href="client_compliance.php?client_id=<?= (int) $r['subject_id'] ?>"><?= nullable_htmlentities($clients[$r['subject_id']]) ?></a></td>
                    <td class="small"><?= nullable_htmlentities(implode(', ', array_map(static fn ($f) => explode(' ', Framework::LABELS[$f])[0] . (str_starts_with(Framework::LABELS[$f], 'SOC') ? ' 2' : ''), $r['frameworks']))) ?></td>
                    <td><span class="badge text-bg-<?= $tone ?>"><?= $r['score'] === null ? '&mdash;' : nullable_htmlentities((string) $r['score']) . '%' ?></span></td>
                    <td><?= (int) $r['current'] ?></td>
                    <td><?= (int) $r['due_soon'] ?></td>
                    <td><?= (int) $r['overdue'] + (int) $r['never'] ?></td>
                    <td class="small"><?= $r['last_snapshot'] ? nullable_htmlentities($r['last_snapshot']) : '&mdash;' ?></td>
                    <td><?= $r['shared'] ? '<span class="badge text-bg-info">Shared</span>' : '&mdash;' ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
        <?php if ($untracked) { ?>
            <details class="mt-3"><summary class="small text-muted"><?= count($untracked) ?> clients with no standards yet</summary>
                <ul class="small mt-2 mb-0">
                    <?php foreach ($untracked as $id => $name) { ?><li><a href="client_compliance.php?client_id=<?= (int) $id ?>"><?= nullable_htmlentities($name) ?></a></li><?php } ?>
                </ul>
            </details>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";
