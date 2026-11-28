<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

// Filter params (all GET, all optional)
$filter_q         = trim((string) ($_GET['q'] ?? ''));
$filter_status    = in_array($_GET['status'] ?? '', ['online', 'offline', 'unknown'], true) ? $_GET['status'] : '';
$filter_client_id = intval($_GET['client_id'] ?? 0);
$filter_intg_id   = intval($_GET['integration_id'] ?? 0);
// Source: our own endpoint agent vs. a third-party RMM integration (integration_id picks one specific third-party RMM)
$filter_source    = in_array($_GET['source'] ?? '', ['agent', 'third'], true) ? $_GET['source'] : '';
$filter_os        = in_array($_GET['os'] ?? '', ['windows', 'server', 'linux', 'macos', 'other'], true) ? $_GET['os'] : '';
$filter_seen      = in_array($_GET['seen'] ?? '', ['1h', '24h', '7d', '30d', 'stale7', 'stale30'], true) ? $_GET['seen'] : '';

$per_page = in_array(intval($_GET['per_page'] ?? 50), [25, 50, 100, 250], true) ? intval($_GET['per_page'] ?? 50) : 50;
$page     = max(1, intval($_GET['page'] ?? 1));

$os_labels   = ['windows' => 'Windows', 'server' => 'Windows Server', 'linux' => 'Linux', 'macos' => 'macOS', 'other' => 'Other'];
$seen_labels = ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'stale7' => 'Not seen for 7+ days', 'stale30' => 'Not seen for 30+ days'];

// Build WHERE clauses. $where_base is everything except the status filter, so the stat tiles stay a map of the filtered fleet.
$esc = static fn (string $v): string => mysqli_real_escape_string($mysqli, $v);
$where_base = "1=1";
if ($filter_client_id) { $where_base .= " AND a.asset_client_id=$filter_client_id"; }
if ($filter_intg_id)   { $where_base .= " AND arl.integration_id=$filter_intg_id"; }
if ($filter_source === 'agent') { $where_base .= " AND i.type='rivetit_agent'"; }
if ($filter_source === 'third') { $where_base .= " AND (i.type IS NULL OR i.type <> 'rivetit_agent')"; }
if ($filter_q !== '') {
    $like = '%' . $esc(addcslashes($filter_q, '%_\\')) . '%';
    $where_base .= " AND (arl.hostname LIKE '$like' OR arl.os_name LIKE '$like' OR arl.logged_in_user LIKE '$like' OR a.asset_name LIKE '$like' OR a.asset_make LIKE '$like' OR a.asset_model LIKE '$like' OR c.client_name LIKE '$like')";
}
if ($filter_os !== '') {
    $os_server = "arl.os_name LIKE '%server%'";
    $where_base .= [
        'windows' => " AND arl.os_name LIKE '%windows%' AND NOT ($os_server)",
        'server'  => " AND $os_server",
        'linux'   => " AND (arl.os_name LIKE '%linux%' OR arl.os_name LIKE '%ubuntu%' OR arl.os_name LIKE '%debian%' OR arl.os_name LIKE '%centos%' OR arl.os_name LIKE '%red hat%' OR arl.os_name LIKE '%rocky%' OR arl.os_name LIKE '%alma%')",
        'macos'   => " AND (arl.os_name LIKE '%mac%' OR arl.os_name LIKE '%darwin%')",
        'other'   => " AND NOT (arl.os_name LIKE '%windows%' OR arl.os_name LIKE '%linux%' OR arl.os_name LIKE '%ubuntu%' OR arl.os_name LIKE '%debian%' OR arl.os_name LIKE '%centos%' OR arl.os_name LIKE '%mac%' OR arl.os_name LIKE '%darwin%')",
    ][$filter_os];
}
if ($filter_seen !== '') {
    $where_base .= [
        '1h'      => " AND arl.last_seen >= NOW() - INTERVAL 1 HOUR",
        '24h'     => " AND arl.last_seen >= NOW() - INTERVAL 1 DAY",
        '7d'      => " AND arl.last_seen >= NOW() - INTERVAL 7 DAY",
        '30d'     => " AND arl.last_seen >= NOW() - INTERVAL 30 DAY",
        'stale7'  => " AND (arl.last_seen IS NULL OR arl.last_seen < NOW() - INTERVAL 7 DAY)",
        'stale30' => " AND (arl.last_seen IS NULL OR arl.last_seen < NOW() - INTERVAL 30 DAY)",
    ][$filter_seen];
}
// Client-restricted techs should only see assets for clients they have access to
if ($client_access_string && !$session_is_admin) { $where_base .= " AND a.asset_client_id IN ($client_access_string)"; }
$where = $where_base . ($filter_status ? " AND arl.rmm_status='" . $esc($filter_status) . "'" : '');

$sql_links = mysqli_query($mysqli,
    "SELECT arl.*, a.asset_id, a.asset_name, a.asset_type, a.asset_client_id,
            c.client_name, i.type AS integration_type, i.name AS integration_name
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE $where
     ORDER BY arl.rmm_status ASC, arl.hostname ASC"
);

// A single physical asset can be linked to more than one RMM integration at
// once (e.g. both Tactical RMM and Level.io tracking the same device). This
// query is per-link, not per-asset, so without deduping it that device would
// be listed twice with no explanation. Collapse to one row per asset_id,
// preferring the Tactical RMM link when config_rmm_prefer_tactical is on,
// then re-sort to match the original status/hostname ordering.
$rmm_link_rows = [];
while ($row = mysqli_fetch_assoc($sql_links)) {
    $aid = intval($row['asset_id']);
    if (!isset($rmm_link_rows[$aid])) {
        $rmm_link_rows[$aid] = $row;
    } elseif ($config_rmm_prefer_tactical
        && $row['integration_type'] === 'tactical_rmm'
        && $rmm_link_rows[$aid]['integration_type'] !== 'tactical_rmm') {
        $rmm_link_rows[$aid] = $row;
    }
}
$rmm_link_rows = array_values($rmm_link_rows);
usort($rmm_link_rows, function ($a, $b) {
    $status_cmp = strcmp($a['rmm_status'] ?? '', $b['rmm_status'] ?? '');
    if ($status_cmp !== 0) return $status_cmp;
    return strcasecmp($a['hostname'] ?? '', $b['hostname'] ?? '');
});

// Pagination (the list is deduped per asset above, so the total is the deduped count)
$total_devices = count($rmm_link_rows);
$total_pages   = max(1, (int) ceil($total_devices / $per_page));
$page          = min($page, $total_pages);
$page_offset   = ($page - 1) * $per_page;
$rmm_link_rows = array_slice($rmm_link_rows, $page_offset, $per_page);

// Counts for stat cards (every filter except status, so the tiles stay a map of what you filtered to)
$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       COALESCE(SUM(arl.rmm_status='online'),0) as online,
       COALESCE(SUM(arl.rmm_status='offline'),0) as offline,
       COALESCE(SUM(arl.rmm_status='unknown'),0) as unknown,
       COUNT(*) as total
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE $where_base"
));

$sql_integrations = mysqli_query($mysqli, "SELECT id, name FROM rmm_integrations WHERE enabled=1 AND type <> 'rivetit_agent' ORDER BY name");
$integrations_list = [];
while ($ii = mysqli_fetch_assoc($sql_integrations)) { $integrations_list[] = $ii; }
$has_own_agent = (bool) mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM rmm_integrations WHERE type='rivetit_agent' LIMIT 1"));

// Tile / reset links keep the other filters
$keep = array_filter(['q' => $filter_q, 'client_id' => $filter_client_id ?: null, 'integration_id' => $filter_intg_id ?: null, 'source' => $filter_source, 'os' => $filter_os, 'seen' => $filter_seen, 'per_page' => $per_page !== 50 ? $per_page : null], static fn ($v) => $v !== null && $v !== '');
$tile_url = static fn (array $over = []): string => '?' . http_build_query(array_merge($keep, $over));
$active_filters = count(array_diff_key($keep, ['per_page' => 1])) + ($filter_status !== '' ? 1 : 0);
$page_url = static fn (int $pg, ?int $pp = null): string => '?' . http_build_query(array_filter(array_merge($keep, ['status' => $filter_status], ['per_page' => ($pp ?? $per_page) !== 50 ? ($pp ?? $per_page) : null, 'page' => $pg > 1 ? $pg : null]), static fn ($v) => $v !== null && $v !== ''));
$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . ($client_access_string && !$session_is_admin ? " AND client_id IN ($client_access_string)" : '') . " ORDER BY client_name ASC");
$clients_list = [];
while ($c = mysqli_fetch_assoc($sql_clients)) $clients_list[] = $c;

// Button label should reflect whichever integration is actually selected
// (Tactical, Sophos, etc.) rather than a hardcoded provider name.
$sync_target_name = 'RMM';
if ($filter_intg_id) {
    $sync_target_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name FROM rmm_integrations WHERE id=$filter_intg_id"));
    if ($sync_target_row) { $sync_target_name = $sync_target_row['name']; }
}
$sync_target_name_js = json_encode($sync_target_name, JSON_HEX_TAG);
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-desktop me-2"></i>RMM Assets</h4>
    <?php if (lookupUserPermission('module_rmm_sync') >= 1 && $filter_intg_id): ?>
    <button class="btn btn-success btn-sm me-2 js-trigger-sync" id="syncBtn">
        <i class="fas fa-sync me-1"></i>Sync from <?= nullable_htmlentities($sync_target_name) ?>
    </button>
    <?php endif; ?>
    <a href="/admin/settings_integrations.php?tab=rmm" class="btn btn-secondary btn-sm">
        <i class="fas fa-cog me-1"></i>Settings
    </a>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="small-box bg-success">
            <div class="inner"><h3><?= intval($cnt['online']) ?></h3><p>Online</p></div>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <a href="<?= nullable_htmlentities($tile_url(['status' => 'online'])) ?>" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-danger">
            <div class="inner"><h3><?= intval($cnt['offline']) ?></h3><p>Offline</p></div>
            <div class="icon"><i class="fas fa-times-circle"></i></div>
            <a href="<?= nullable_htmlentities($tile_url(['status' => 'offline'])) ?>" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-secondary">
            <div class="inner"><h3><?= intval($cnt['unknown']) ?></h3><p>Unknown</p></div>
            <div class="icon"><i class="fas fa-question-circle"></i></div>
            <a href="<?= nullable_htmlentities($tile_url(['status' => 'unknown'])) ?>" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-info">
            <div class="inner"><h3><?= intval($cnt['total']) ?></h3><p>Total Managed</p></div>
            <div class="icon"><i class="fas fa-desktop"></i></div>
            <a href="<?= nullable_htmlentities($tile_url()) ?>" class="small-box-footer">Show All <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
</div>

<!-- Sync status bar -->
<div id="syncStatus" class="alert alert-info d-none mb-3">
    <i class="fas fa-spinner fa-spin me-2"></i><span id="syncStatusText">Syncing...</span>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-3">
        <form method="get" role="search" aria-label="Filter RMM assets" class="rmm-assets-filter">
            <div class="rmm-assets-filter-grid">
                <div class="rmm-assets-filter-search">
                    <label class="rmm-assets-filter-label" for="rmmq">Search</label>
                    <input type="search" id="rmmq" name="q" class="form-control" value="<?= nullable_htmlentities($filter_q) ?>" placeholder="Hostname, user, OS, model or client">
                </div>
                <div>
                    <label class="rmm-assets-filter-label" for="rmmsrc">Source</label>
                    <select id="rmmsrc" class="form-select" data-src-select aria-label="Source">
                        <option value="">All sources</option>
                        <?php if ($has_own_agent): ?><option value="source:agent" <?= $filter_source === 'agent' ? 'selected' : '' ?>><?= nullable_htmlentities(defined('APP_NAME') ? APP_NAME : 'RivetMSP') ?> endpoint agent</option><?php endif; ?>
                        <option value="source:third" <?= $filter_source === 'third' ? 'selected' : '' ?>>Any third-party RMM</option>
                        <?php foreach ($integrations_list as $i): ?>
                        <option value="integration:<?= intval($i['id']) ?>" <?= $filter_intg_id == $i['id'] ? 'selected' : '' ?>><?= nullable_htmlentities($i['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="source" value="<?= nullable_htmlentities($filter_source) ?>" data-src-source>
                    <input type="hidden" name="integration_id" value="<?= $filter_intg_id ?: '' ?>" data-src-intg>
                </div>
                <div>
                    <label class="rmm-assets-filter-label" for="rmmst">Status</label>
                    <select id="rmmst" name="status" class="form-select auto-submit-select">
                        <option value="">Any status</option>
                        <option value="online"  <?= $filter_status === 'online'  ? 'selected' : '' ?>>Online</option>
                        <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>Offline</option>
                        <option value="unknown" <?= $filter_status === 'unknown' ? 'selected' : '' ?>>Unknown</option>
                    </select>
                </div>
                <div>
                    <label class="rmm-assets-filter-label" for="rmmcl">Client</label>
                    <select id="rmmcl" name="client_id" class="form-select auto-submit-select">
                        <option value="">Any client</option>
                        <?php foreach ($clients_list as $cl): ?>
                        <option value="<?= intval($cl['client_id']) ?>" <?= $filter_client_id == $cl['client_id'] ? 'selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rmm-assets-filter-label" for="rmmos">Operating system</label>
                    <select id="rmmos" name="os" class="form-select auto-submit-select">
                        <option value="">Any OS</option>
                        <?php foreach ($os_labels as $k => $lbl): ?><option value="<?= $k ?>" <?= $filter_os === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="rmm-assets-filter-label" for="rmmseen">Last seen</label>
                    <select id="rmmseen" name="seen" class="form-select auto-submit-select">
                        <option value="">Any time</option>
                        <?php foreach ($seen_labels as $k => $lbl): ?><option value="<?= $k ?>" <?= $filter_seen === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="rmm-assets-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i>Apply</button>
                    <?php if ($active_filters): ?><a href="?" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>
<!-- Asset table -->
<div class="card card-dark">
    <div class="card-body p-0">
        <?php if (count($rmm_link_rows) === 0): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-desktop fa-3x mb-3"></i>
                <p>No RMM assets found. <a href="#" class="js-trigger-sync">Sync from <?= nullable_htmlentities($sync_target_name) ?></a> to import devices.</p>
            </div>
        <?php else: ?>
        <table class="table table-hover table-sm mb-0" id="rmm-assets-table">
            <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                <tr>
                    <th class="ps-3">Status</th>
                    <th>Hostname</th>
                    <th>Source</th>
                    <th>Client</th>
                    <th>OS</th>
                    <th>Logged In User</th>
                    <th>Last Seen</th>
                    <th>Last Sync</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rmm_link_rows as $row):
                $status    = $row['rmm_status'];
                $badge     = $status === 'online' ? 'text-bg-success' : ($status === 'offline' ? 'text-bg-danger' : 'text-bg-secondary');
                $icon      = $status === 'online' ? 'fa-circle text-success' : ($status === 'offline' ? 'fa-circle text-danger' : 'fa-question-circle text-muted');
            ?>
            <tr>
                <td class="ps-3">
                    <i class="fas <?= $icon ?>" data-bs-toggle="tooltip" title="<?= ucfirst($status) ?>"></i>
                </td>
                <td>
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="fw-bold">
                        <?= nullable_htmlentities($row['hostname']) ?>
                    </a>
                </td>
                <td class="text-muted small"><?= nullable_htmlentities($row['integration_name'] ?? 'RMM') ?></td>
                <td>
                    <?php if ($row['asset_client_id']): ?>
                        <a href="/agent/client_overview.php?client_id=<?= intval($row['asset_client_id']) ?>">
                            <?= nullable_htmlentities($row['client_name']) ?>
                        </a>
                    <?php elseif (lookupUserPermission('module_rmm_sync') >= 1): ?>
                        <select class="form-control form-control-sm assign-client-select" style="width:auto;display:inline-block" data-asset-id="<?= intval($row['asset_id']) ?>">
                            <option value="">Assign client...</option>
                            <?php foreach ($clients_list as $cl): ?>
                            <option value="<?= intval($cl['client_id']) ?>"><?= nullable_htmlentities($cl['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= nullable_htmlentities($row['os_name']) ?></td>
                <td class="text-muted small"><?= nullable_htmlentities($row['logged_in_user']) ?: '—' ?></td>
                <td class="text-muted small">
                    <?= $row['last_seen'] ? nullable_htmlentities($row['last_seen']) : '—' ?>
                </td>
                <td class="text-muted small">
                    <?= $row['last_sync'] ? nullable_htmlentities($row['last_sync']) : '—' ?>
                </td>
                <td class="text-end pe-2">
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="btn btn-xs btn-info" data-bs-toggle="tooltip" title="View Asset">
                        <i class="fas fa-tachometer-alt"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($total_devices > 0): ?>
    <div class="card-footer rmm-assets-pager">
        <div class="rmm-assets-pager-count">Showing <strong><?= $page_offset + 1 ?>–<?= $page_offset + count($rmm_link_rows) ?></strong> of <strong><?= $total_devices ?></strong> device<?= $total_devices === 1 ? '' : 's' ?><?= $active_filters ? ' (filtered)' : '' ?></div>
        <form method="get" class="rmm-assets-pager-size">
            <?php foreach (array_merge($keep, ['status' => $filter_status]) as $k => $v): if ($v === '' || $v === null || $k === 'per_page') continue; ?><input type="hidden" name="<?= nullable_htmlentities($k) ?>" value="<?= nullable_htmlentities((string) $v) ?>"><?php endforeach; ?>
            <label for="rmmpp" class="mb-0">Per page</label>
            <select id="rmmpp" name="per_page" class="form-select form-select-sm auto-submit-select">
                <?php foreach ([25, 50, 100, 250] as $n): ?><option value="<?= $n ?>" <?= $per_page === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Pages" class="rmm-assets-pager-nav">
            <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= nullable_htmlentities($page_url(max(1, $page - 1))) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>><i class="fas fa-chevron-left"></i> Prev</a>
            <span class="rmm-assets-pager-page">Page <strong><?= $page ?></strong> of <?= $total_pages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $page >= $total_pages ? 'disabled' : '' ?>" href="<?= nullable_htmlentities($page_url(min($total_pages, $page + 1))) ?>" <?= $page >= $total_pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next <i class="fas fa-chevron-right"></i></a>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Source picker: one visible select drives the two hidden params (source=agent|third, or integration_id=<n>)
document.addEventListener('change', function (e) {
    var sel = e.target.closest('[data-src-select]');
    if (!sel) return;
    var f = sel.form, v = sel.value.split(':');
    f.querySelector('[data-src-source]').value = v[0] === 'source' ? v[1] : '';
    f.querySelector('[data-src-intg]').value = v[0] === 'integration' ? v[1] : '';
    f.submit();
});
// Delegated wiring (CSP forbids inline onclick= attributes).
document.addEventListener('click', function (e) {
    if (e.target.closest('.js-trigger-sync')) { e.preventDefault(); triggerSync(); }
});

function triggerSync() {
    const btn = document.getElementById('syncBtn');
    const bar = document.getElementById('syncStatus');
    const txt = document.getElementById('syncStatusText');
    const syncTargetName = <?= $sync_target_name_js ?>;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...'; }
    bar.classList.remove('d-none');
    txt.textContent = `Syncing assets from ${syncTargetName}...`;

    fetch('/agent/post/rmm_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=sync&integration_id=<?= $filter_intg_id ?>'
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            txt.textContent = `Sync complete: ${d.created} created, ${d.updated} updated, ${d.matched} matched, ${d.skipped} skipped.`;
            bar.classList.replace('alert-info', 'alert-success');
            setTimeout(() => location.reload(), 2000);
        } else {
            txt.textContent = 'Sync failed: ' + (d.error || 'Unknown error');
            bar.classList.replace('alert-info', 'alert-danger');
            if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-sync me-1"></i>Sync from ${syncTargetName}`; }
        }
    })
    .catch(() => {
        txt.textContent = 'Network error during sync.';
        bar.classList.replace('alert-info', 'alert-danger');
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-sync me-1"></i>Sync from ${syncTargetName}`; }
    });
}

document.querySelectorAll('.assign-client-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
        const assetId = this.dataset.assetId;
        const clientId = this.value;
        if (!clientId) return;
        this.disabled = true;
        fetch('/agent/post/rmm_sync.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=assign_client&asset_id=' + encodeURIComponent(assetId) + '&client_id=' + encodeURIComponent(clientId)
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                location.reload();
            } else {
                RivetDialog.alert('Failed to assign client: ' + (d.error || 'Unknown error'));
                this.disabled = false;
            }
        })
        .catch(() => {
            RivetDialog.alert('Network error assigning client.');
            this.disabled = false;
        });
    });
});
</script>

<?php require_once "../includes/footer.php"; ?>
