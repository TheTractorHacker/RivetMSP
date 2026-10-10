<?php
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_events.php";
require_once "../includes/event_bus.php";
require_once "includes/webhook_form_lib.php";
?>

<link rel="stylesheet" href="/css/webhook_form.css?v=<?= @filemtime(__DIR__ . '/../css/webhook_form.css') ?: time() ?>">
<?php
$sql_wh = mysqli_query($mysqli, "SELECT w.*,
        COALESCE(d.delivered, 0) + COALESCE(q.delivered, 0) AS delivered,
        COALESCE(d.failed, 0) + COALESCE(q.failed, 0) AS failed,
        COALESCE(j.pending, 0) + COALESCE(q.pending, 0) AS pending,
        ld.http_status AS last_http, ld.created_at AS last_at
    FROM webhooks w
    LEFT JOIN (
        SELECT webhook_id,
            SUM(http_status BETWEEN 200 AND 299) AS delivered,
            SUM(http_status IS NULL OR http_status NOT BETWEEN 200 AND 299) AS failed
        FROM webhook_deliveries
        WHERE created_at > NOW() - INTERVAL 7 DAY
        GROUP BY webhook_id
    ) d ON d.webhook_id = w.webhook_id
    LEFT JOIN (
        SELECT CAST(JSON_VALUE(payload, '$.webhook_id') AS UNSIGNED) AS wid, COUNT(*) AS pending
        FROM integration_jobs
        WHERE job_type = 'webhook.deliver' AND status IN ('pending', 'running')
        GROUP BY wid
    ) j ON j.wid = w.webhook_id
    LEFT JOIN (
        SELECT queue_webhook_id,
            SUM(queue_status = 'delivered') AS delivered,
            SUM(queue_status = 'failed') AS failed,
            SUM(queue_status = 'pending') AS pending
        FROM webhook_queue
        WHERE queue_created_at > NOW() - INTERVAL 7 DAY
        GROUP BY queue_webhook_id
    ) q ON q.queue_webhook_id = w.webhook_id
    LEFT JOIN webhook_deliveries ld ON ld.delivery_id = (SELECT MAX(x.delivery_id) FROM webhook_deliveries x WHERE x.webhook_id = w.webhook_id)
    ORDER BY w.webhook_id ASC");
$wh_count = mysqli_num_rows($sql_wh);
?>

<div class="card whf-list" data-whf-list>
    <div class="card-header py-3 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-satellite-dish me-2"></i>Webhooks</h3>
        <?php if ($wh_count > 0) { ?>
            <div class="wh-search whf-list-search">
                <i class="fas fa-search wh-search-icon" aria-hidden="true"></i>
                <input type="search" class="form-control form-control-sm" data-whf-list-search placeholder="Search webhooks" aria-label="Search webhooks" autocomplete="off">
            </div>
        <?php } ?>
        <a href="webhook_guides.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book me-1"></i>Guides</a>
        <a class="btn btn-primary btn-sm" href="webhook_form.php">
            <i class="fas fa-plus me-1"></i>Add Webhook
        </a>
    </div>
    <?php if ($wh_count == 0) {
        $wh_pop = array_values(array_filter(array_map(static fn ($pid) => \RivetCore\Webhooks\Destinations::get($pid), RIVET_WEBHOOK_POPULAR)));
        ?>
        <div class="card-body">
            <div class="whf-empty">
                <div class="whf-empty-mark" aria-hidden="true"><i class="fas fa-satellite-dish"></i></div>
                <h4>No webhooks yet</h4>
                <p class="text-muted mb-3">Send tickets, alerts and other events to n8n, Slack, Discord, Teams, ntfy, Home Assistant and more. It takes about a minute: pick a platform, paste its address, choose the events.</p>
                <a class="btn btn-primary btn-lg mb-3" href="webhook_form.php"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add your first webhook</a>
                <div class="whf-shortcuts" aria-label="Popular platforms">
                    <?php foreach ($wh_pop as $pd) { [$pc, $pcol] = rivetWebhookBrand($pd->id, $pd->category); ?>
                        <a class="whf-shortcut" href="webhook_form.php?dest=<?= nullable_htmlentities(urlencode($pd->id)) ?>"><span class="whf-icon" style="--whf-brand:<?= nullable_htmlentities($pcol) ?>" aria-hidden="true"><i class="<?= nullable_htmlentities($pc) ?>"></i></span><?= nullable_htmlentities($pd->name) ?></a>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } else { ?>
    <div class="card-body pb-0">
        <p class="text-muted mb-3">Send selected events to another service, each with a preset and a setup guide (<a href="webhook_guides.php">see the guides</a>). Delivery counts show the last seven days.</p>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-striped table-borderless table-hover align-middle mb-0 whf-table">
            <thead class="text-dark">
                <tr>
                    <th>Webhook</th>
                    <th>Address</th>
                    <th>Events</th>
                    <th>Last delivery</th>
                    <th>7 days</th>
                    <th>On</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            while ($wh = mysqli_fetch_assoc($sql_wh)) {
                $wid     = intval($wh['webhook_id']);
                $wname   = nullable_htmlentities($wh['webhook_name']);
                $wurl_plain = decryptSetting((string) $wh['webhook_url']);
                $wurl    = nullable_htmlentities((str_starts_with((string) $wh['webhook_url'], 'ENC2:') || str_starts_with((string) $wh['webhook_url'], 'ENC:')) ? rivetWebhookUrlMasked($wurl_plain) : $wurl_plain);
                $wdest   = (string) $wh['webhook_destination'] !== '' ? \RivetCore\Webhooks\Destinations::get((string) $wh['webhook_destination']) : null;
                $wenabled = intval($wh['webhook_enabled']);
                $wevents = array_filter(array_map('trim', explode(',', $wh['webhook_events'])));
                $wsum    = rivetWebhookEventsSummary((string) $wh['webhook_events']);
                [$wicon, $wcolor] = $wdest ? rivetWebhookBrand($wdest->id, $wdest->category) : ['fas fa-code', '#64748B'];

                $delivered = intval($wh['delivered']);
                $failed    = intval($wh['failed']);
                $pending   = intval($wh['pending']);
                $has_last  = $wh['last_at'] !== null;
                $last_ok   = $has_last && intval($wh['last_http']) >= 200 && intval($wh['last_http']) < 300;
                ?>
                <tr data-whf-row class="<?= $wenabled ? '' : 'whf-row-off' ?>" data-search="<?= nullable_htmlentities(strtolower($wh['webhook_name'] . ' ' . ($wdest->name ?? 'generic') . ' ' . rivetWebhookUrlMasked($wurl_plain) . ' ' . implode(' ', $wevents))) ?>">
                    <td>
                        <div class="whf-rowname">
                            <span class="whf-icon" style="--whf-brand:<?= nullable_htmlentities($wcolor) ?>" aria-hidden="true"><i class="<?= nullable_htmlentities($wicon) ?>"></i></span>
                            <div>
                                <a href="webhook_form.php?id=<?= $wid ?>" class="fw-bold text-reset text-decoration-none"><?= $wname ?></a>
                                <div class="small text-muted"><?= $wdest ? nullable_htmlentities($wdest->name) : 'Generic JSON (older)' ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="webhook-url" title="<?= $wurl ?>"><?= $wurl ?></td>
                    <td class="webhook-events">
                        <span class="whf-evchip" title="<?= nullable_htmlentities(implode(', ', $wevents)) ?>"><?= nullable_htmlentities($wsum['label']) ?><span class="whf-evcount"><?= (int) $wsum['count'] ?></span></span>
                    </td>
                    <td>
                        <?php if (!$has_last) { ?>
                            <span class="badge text-bg-secondary">None yet</span>
                        <?php } else { ?>
                            <span class="badge <?= $last_ok ? 'text-bg-success' : 'text-bg-danger' ?>"><?= intval($wh['last_http']) ? 'HTTP ' . intval($wh['last_http']) : 'No response' ?></span>
                            <span class="small text-muted text-nowrap" title="<?= nullable_htmlentities($wh['last_at']) ?>"><?= nullable_htmlentities(timeAgo($wh['last_at'])) ?></span>
                        <?php } ?>
                    </td>
                    <td>
                        <span class="badge text-bg-success" title="Delivered (7d)"><?= $delivered ?></span>
                        <?php if ($pending > 0) { ?><span class="badge text-bg-warning" title="Pending"><?= $pending ?></span><?php } ?>
                        <?php if ($failed > 0) { ?><span class="badge text-bg-danger" title="Failed"><?= $failed ?></span><?php } ?>
                    </td>
                    <td>
                        <label class="whf-switch" title="<?= $wenabled ? 'Enabled' : 'Disabled' ?>: switch to change it right away">
                            <input type="checkbox" role="switch" data-whf-toggle="<?= $wid ?>" aria-label="Enabled: <?= $wname ?>" <?= $wenabled ? 'checked' : '' ?>>
                            <span class="whf-switch-track" aria-hidden="true"></span><span class="visually-hidden" data-whf-toggle-label><?= $wenabled ? 'Enabled' : 'Disabled' ?></span>
                        </label>
                    </td>
                    <td class="text-end webhook-actions">
                        <div class="whf-menu">
                            <button type="button" class="btn btn-sm btn-light" data-whf-menu aria-haspopup="true" aria-expanded="false" aria-label="Actions for <?= $wname ?>"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
                            <div class="whf-menu-list" role="menu" hidden>
                                <button type="button" class="whf-menu-item" role="menuitem" data-wh-test-id="<?= $wid ?>" data-wh-name="<?= $wname ?>" data-tools-url="webhook_tools.php"><i class="fas fa-fw fa-paper-plane" aria-hidden="true"></i> Send test</button>
                                <a class="whf-menu-item" role="menuitem" href="webhook_form.php?id=<?= $wid ?>"><i class="fas fa-fw fa-edit" aria-hidden="true"></i> Edit</a>
                                <button type="button" class="whf-menu-item" role="menuitem" data-whf-duplicate="<?= $wid ?>"><i class="fas fa-fw fa-clone" aria-hidden="true"></i> Duplicate</button>
                                <a class="whf-menu-item whf-menu-danger confirm-link" role="menuitem" href="post.php?delete_webhook=<?= $wid ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash" aria-hidden="true"></i> Delete</a>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php } ?>
                <tr data-whf-list-none hidden><td colspan="7" class="text-center text-muted py-4">No webhook matches your search.</td></tr>
            </tbody>
        </table>
        </div>
    </div>
    <?php } ?>
</div>

<?php
$wh_nets = rivetWebhookAllowedNetworks($mysqli);
$wh_nets_text = $_SESSION['webhook_networks_draft'] ?? implode("\n", $wh_nets);
unset($_SESSION['webhook_networks_draft']);
$wh_detect = isset($_GET['detect']);
$wh_detected = [];
if ($wh_detect) {
    foreach (\RivetCore\Support\LocalNetworks::detect() as $d) {
        $wh_detected[$d['cidr']] = $d;
    }
}
?>
<div class="card mt-3" id="internal-networks">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-network-wired me-2"></i>Internal network access</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">Webhooks may only call public addresses plus the internal networks listed here. Loopback (127.0.0.0/8), link-local (169.254.0.0/16) and cloud-metadata addresses are never allowed, and only private ranges (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) can be listed.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <label class="form-label" for="webhook_allowed_networks">Allowed internal networks (one per line, CIDR)</label>
            <textarea class="form-control font-monospace mb-2" id="webhook_allowed_networks" name="webhook_allowed_networks" rows="4" placeholder="192.168.1.0/24"><?= nullable_htmlentities($wh_nets_text) ?></textarea>
            <button type="submit" name="save_webhook_networks" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save networks</button>
            <a href="settings_webhooks.php?detect=1#internal-networks" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-search me-1"></i>Use this server's network</a>
        </form>
        <?php if ($wh_detect) { ?>
            <div class="mt-3" id="detected-networks">
                <?php if (!$wh_detected) { ?>
                    <p class="text-muted mb-0">No private network was detected on this server.</p>
                <?php } else { ?>
                    <p class="mb-1">Detected on this server:</p>
                    <?php foreach ($wh_detected as $cidr => $d) { ?>
                        <form action="post.php" method="post" class="d-flex align-items-center gap-2 mb-1">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="network" value="<?= nullable_htmlentities($cidr) ?>">
                            <span class="text-muted"><?= nullable_htmlentities($d['interface']) ?></span>
                            <code><?= nullable_htmlentities($cidr) ?></code>
                            <?php if (in_array($cidr, $wh_nets, true)) { ?>
                                <span class="badge text-bg-success">Allowed</span>
                            <?php } else { ?>
                                <button type="submit" name="add_webhook_network" class="btn btn-sm btn-outline-primary">Add</button>
                            <?php } ?>
                        </form>
                    <?php } ?>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<?php
$legacy_queue_rows = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM webhook_queue"))[0] ?? 0);
if (isset($sql_wh) && mysqli_num_rows($sql_wh) > 0 && $legacy_queue_rows > 0) { ?>
<div class="card mt-3">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-list me-2"></i>Earlier queued deliveries</h3>
    </div>
    <p class="text-muted small px-3 pt-3 mb-2">Ticket events queued before deliveries moved to the job queue; they finish sending and then this list stops growing. Showing the latest 100.</p>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-striped table-borderless mb-0">
            <thead class="text-dark">
                <tr><th>When</th><th>Webhook</th><th>Event</th><th>Status</th><th>HTTP</th><th>Attempts</th></tr>
            </thead>
            <tbody>
            <?php
            $sql_log = mysqli_query($mysqli,
                "SELECT wq.*, w.webhook_name FROM webhook_queue wq
                 JOIN webhooks w ON wq.queue_webhook_id = w.webhook_id
                 ORDER BY wq.queue_id DESC LIMIT 100");
            if (mysqli_num_rows($sql_log) == 0) { ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No queued deliveries yet.</td></tr>
            <?php }
            while ($lrow = mysqli_fetch_assoc($sql_log)) {
                $status_badge = match($lrow['queue_status']) {
                    'delivered' => '<span class="badge text-bg-success">delivered</span>',
                    'failed'    => '<span class="badge text-bg-danger">failed</span>',
                    default     => '<span class="badge text-bg-warning">pending</span>',
                };
                ?>
                <tr>
                    <td class="text-nowrap text-secondary" title="<?= nullable_htmlentities($lrow['queue_created_at']) ?>"><?= timeAgo($lrow['queue_created_at']) ?></td>
                    <td><?= nullable_htmlentities($lrow['webhook_name']) ?></td>
                    <td><code><?= nullable_htmlentities($lrow['queue_event']) ?></code></td>
                    <td><?= $status_badge ?></td>
                    <td><?= $lrow['queue_response_code'] ?: '—' ?></td>
                    <td><?= intval($lrow['queue_attempts']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<?php if (isset($sql_wh) && mysqli_num_rows($sql_wh) > 0) { ?>
<div class="card mt-3" id="deliveries">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Deliveries</h3>
    </div>
    <p class="text-muted small px-3 pt-3 mb-2">Every attempt, including retries (a failed delivery is retried after 1, 5, 30 and 120 minutes, then set aside as failed; see the Job queue page). Showing the latest 100.</p>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-striped table-borderless mb-0">
            <thead class="text-dark">
                <tr><th>When</th><th>Webhook</th><th>Event</th><th>Attempt</th><th>HTTP</th><th>Duration</th><th>Response</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $sql_direct = mysqli_query($mysqli,
                "SELECT wd.*, w.webhook_name FROM webhook_deliveries wd
                 LEFT JOIN webhooks w ON wd.webhook_id = w.webhook_id
                 ORDER BY wd.delivery_id DESC LIMIT 100");
            if (mysqli_num_rows($sql_direct) == 0) { ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No deliveries yet.</td></tr>
            <?php } else {
                while ($drow = mysqli_fetch_assoc($sql_direct)) {
                    $http = intval($drow['http_status']);
                    $http_badge = $http >= 200 && $http < 300
                        ? '<span class="badge text-bg-success">' . $http . '</span>'
                        : ($http > 0 ? '<span class="badge text-bg-danger">' . $http . '</span>' : '<span class="badge text-bg-danger">no response</span>');
                    ?>
                    <tr>
                        <td class="text-nowrap text-secondary" title="<?= nullable_htmlentities($drow['created_at']) ?>"><?= timeAgo($drow['created_at']) ?></td>
                        <td><?= $drow['webhook_name'] !== null ? nullable_htmlentities($drow['webhook_name']) : '<span class="text-muted">(unsaved test)</span>' ?></td>
                        <td><code><?= nullable_htmlentities($drow['event_type']) ?></code></td>
                        <td><?= intval($drow['attempt_number']) ?></td>
                        <td><?= $http_badge ?></td>
                        <td><?= intval($drow['duration_ms']) ?> ms</td>
                        <td class="text-truncate" style="max-width:260px;" title="<?= nullable_htmlentities($drow['response_body_snippet']) ?>"><?= nullable_htmlentities($drow['response_body_snippet']) ?></td>
                        <td class="text-end"><?php if ((string) $drow['request_payload_json'] !== '') { ?><button type="button" class="btn btn-sm btn-light" data-wh-payload-id="<?= intval($drow['delivery_id']) ?>" data-tools-url="webhook_tools.php" title="View the request body that was sent (secrets hidden)"><i class="fas fa-eye me-1"></i>Payload</button><?php } ?></td>
                    </tr>
                <?php }
            } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<dialog id="wh_dialog" class="wh-dialog" aria-labelledby="wh_dialog_title">
    <div class="wh-dialog-head">
        <h5 class="mb-0 me-auto" id="wh_dialog_title" data-wh-dialog-title></h5>
        <button type="button" class="btn btn-light btn-sm" data-wh-dialog-close aria-label="Close">Close</button>
    </div>
    <div class="wh-dialog-body" data-wh-dialog-body></div>
</dialog>

<?php require_once "../includes/footer.php"; ?>
