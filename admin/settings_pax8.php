<?php

/*
 * ITFlow - Pax8 cost sync + billing/COGS reconciliation settings (roadmap #25).
 *
 * Self-contained admin page (POST/AJAX handlers run before any output, then the tabbed UI
 * renders), mirroring admin/settings_accounting.php's own structure. Three tabs:
 * Connection, Client Mapping, Reconciliation (?tab=connection|client_mapping|reconciliation).
 *
 * Pull-only / COGS-reconciliation scope (per the agreed v1): this pulls Pax8 subscription
 * costs and compares the CLIENT-level total against RivetMSP's own recurring-invoice billing
 * for that client. It never pushes anything to Pax8, and it does not reconcile at the
 * individual product/SKU level in this version - see includes/pax8_functions.php's
 * pax8ReconciliationRows() header for that scope note.
 *
 * Roadmap #25 part 2 adds the OTHER direction - billing-back: a Billing Rules tab (price-per-
 * seat mapping by Pax8 product name), a global + per-client "Automated Billing" switch (both
 * default off - see includes/pax8_functions.php's header), and a Sync Billing Now button for an
 * immediate run outside the daily cron cadence.
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";
require_once __DIR__ . "/../includes/check_login.php";
require_once __DIR__ . "/../includes/pax8_functions.php";
require_once __DIR__ . "/../includes/class_pax8_client.php";

if (!isset($session_is_admin) || !$session_is_admin) {
    flash_alert("Admin access required.", 'error');
    redirect('/admin/settings_integrations.php');
}

$connection_path      = '/admin/settings_pax8.php?tab=connection';
$client_mapping_path  = '/admin/settings_pax8.php?tab=client_mapping';
$reconciliation_path  = '/admin/settings_pax8.php?tab=reconciliation';
$billing_rules_path   = '/admin/settings_pax8.php?tab=billing_rules';

$integration    = getPax8Integration($mysqli);
$integration_id = $integration ? intval($integration['id']) : 0;
$is_connected   = $integration && !empty($integration['access_token_enc']) && intval($integration['enabled']) === 1;

// ═══════════════════════════════════════════════════════════════════════════
// CONNECTION TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

if (isset($_POST['save_pax8_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $client_id = sanitizeInput(trim($_POST['pax8_client_id'] ?? ''));
    $secret_raw = (string) ($_POST['pax8_client_secret'] ?? '');
    $enabled = isset($_POST['pax8_enabled']) ? 1 : 0;
    // Global billing-sync switch - off by default; a client is still gated separately (see
    // pax8_client_map.billing_sync_enabled below), so flipping this alone changes no invoice.
    $billing_sync_enabled = isset($_POST['pax8_billing_sync_enabled']) ? 1 : 0;
    $client_id_esc = mysqli_real_escape_string($mysqli, $client_id);

    if (!$integration) {
        $secret_esc = mysqli_real_escape_string($mysqli, encryptSetting($secret_raw));
        mysqli_query($mysqli, "INSERT INTO pax8_integrations SET client_id = '$client_id_esc', client_secret_enc = '$secret_esc', enabled = $enabled, billing_sync_enabled = $billing_sync_enabled");
    } else {
        $secret_sql = '';
        if ($secret_raw !== '') {
            $secret_esc = mysqli_real_escape_string($mysqli, encryptSetting($secret_raw));
            $secret_sql = "client_secret_enc = '$secret_esc', ";
        }
        mysqli_query($mysqli, "UPDATE pax8_integrations SET client_id = '$client_id_esc', {$secret_sql} enabled = $enabled, billing_sync_enabled = $billing_sync_enabled WHERE id = $integration_id");
    }

    logAction("Settings", "Edit", "$session_name edited Pax8 integration settings");
    flash_alert("Pax8 settings saved");
    redirect($connection_path);
}

if (isset($_POST['test_pax8_connection'])) {
    validateCSRFToken($_POST['csrf_token']);
    header('Content-Type: application/json');
    try {
        if (!$integration_id) {
            throw new Pax8Exception('Save your Pax8 Client ID and Client Secret first.');
        }
        $pax8 = makePax8Client($mysqli, $integration_id);
        $pax8->testConnection();
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_POST['sync_pax8_now'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        if (!$integration_id) {
            throw new Pax8Exception('Pax8 is not configured.');
        }
        $pax8 = makePax8Client($mysqli, $integration_id);
        $result = pax8SyncAll($mysqli, $pax8, $integration_id);
        logAction("Pax8", "Sync", "$session_name ran a Pax8 sync: {$result['companies']} companies seen, {$result['subscriptions']} subscriptions pulled across {$result['mapped_companies']} mapped companies");
        flash_alert("Pax8 sync complete: {$result['companies']} companies seen, {$result['subscriptions']} subscriptions pulled for {$result['mapped_companies']} mapped client(s).");
    } catch (Throwable $e) {
        flash_alert("Pax8 sync failed: " . $e->getMessage(), 'error');
    }
    redirect($connection_path);
}

if (isset($_POST['sync_pax8_billing_now'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        if (!$integration_id || intval($integration['billing_sync_enabled'] ?? 0) !== 1) {
            throw new Pax8Exception('Enable Automated Billing (Connection tab) first.');
        }
        $result = pax8BillingSyncAll($mysqli);
        if (!empty($result['locked'])) {
            throw new Pax8Exception('A billing sync is already running. Try again in a minute.');
        }
        logAction("Pax8", "Sync", "$session_name ran a Pax8 billing sync: {$result['added']} line item(s) added, {$result['updated']} updated, {$result['archived']} archived across {$result['clients']} client(s)");
        flash_alert("Pax8 billing sync complete: {$result['added']} added, {$result['updated']} updated, {$result['archived']} archived across {$result['clients']} client(s). {$result['skipped_no_rule']} subscription(s) skipped (no billing rule).");
    } catch (Throwable $e) {
        flash_alert("Pax8 billing sync failed: " . $e->getMessage(), 'error');
    }
    redirect($connection_path);
}

if (isset($_GET['disconnect_pax8'])) {
    validateCSRFToken($_GET['csrf_token']);
    if ($integration_id) {
        mysqli_query($mysqli, "UPDATE pax8_integrations SET access_token_enc = NULL, token_expires_at = NULL, enabled = 0 WHERE id = $integration_id");
        logAction("Settings", "Edit", "$session_name disconnected Pax8");
    }
    flash_alert("Pax8 disconnected");
    redirect($connection_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// CLIENT MAPPING TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

if (isset($_POST['save_pax8_client_mapping'])) {
    validateCSRFToken($_POST['csrf_token']);
    $map_id = intval($_POST['map_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    $client_sql = $client_id > 0 ? $client_id : 'NULL';
    // Per-client billing-sync switch - unmapping a client (client_id = NULL) always forces this
    // off too, so a company that gets unmapped can never keep auto-updating a stale invoice.
    $billing_sync_enabled = ($client_id > 0 && isset($_POST['billing_sync_enabled'])) ? 1 : 0;
    // Re-pointing a company at a different client drops the stored recurring invoice (it belongs to the old client); the
    // next sync then creates a fresh one. MySQL evaluates SET left to right, so this must come before client_id.
    mysqli_query($mysqli, "UPDATE pax8_client_map SET recurring_invoice_id = IF(client_id <=> $client_sql, recurring_invoice_id, NULL), client_id = $client_sql, billing_sync_enabled = $billing_sync_enabled WHERE map_id = $map_id");
    logAction("Pax8", "Edit", "$session_name updated a Pax8 company -> client mapping" . ($billing_sync_enabled ? " (automated billing enabled)" : ""), $client_id);
    flash_alert("Mapping saved");
    redirect($client_mapping_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// BILLING RULES TAB - handlers
// ═══════════════════════════════════════════════════════════════════════════

if (isset($_POST['save_pax8_billing_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    $product_name = trim((string) ($_POST['product_name'] ?? ''));
    $pricing_mode = ($_POST['pricing_mode'] ?? '') === 'fixed' ? 'fixed' : 'markup';
    $sell_price = round(floatval($_POST['sell_price'] ?? 0), 2);
    $markup_percent = round(floatval($_POST['markup_percent'] ?? 0), 2);
    $rule_enabled = isset($_POST['rule_enabled']) ? 1 : 0;
    if ($product_name !== '') {
        pax8SaveBillingRule($mysqli, $product_name, $pricing_mode, $sell_price, $markup_percent, (bool) $rule_enabled);
        logAction("Pax8", "Edit", "$session_name saved a Pax8 billing rule for \"$product_name\"");
        flash_alert("Billing rule saved");
    }
    redirect($billing_rules_path);
}

// ═══════════════════════════════════════════════════════════════════════════
// Render
// ═══════════════════════════════════════════════════════════════════════════

require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

$active_tab = in_array($_GET['tab'] ?? '', ['connection', 'client_mapping', 'reconciliation', 'billing_rules'], true) ? $_GET['tab'] : 'connection';

$pax8_client_id = $integration['client_id'] ?? '';
$pax8_enabled   = $integration ? intval($integration['enabled']) : 0;
$pax8_billing_sync_enabled = $integration ? intval($integration['billing_sync_enabled']) : 0;
$pax8_last_sync = $integration['last_synced_at'] ?? '';
$has_secret     = $integration && !empty($integration['client_secret_enc']);

$billing_rules = pax8BillingRules($mysqli);
// Every distinct product name seen from Pax8 so far, so the admin can set a price without
// having to type the exact Pax8 product name from memory.
$known_products = [];
$pres = mysqli_query($mysqli, "SELECT DISTINCT product_name FROM pax8_subscriptions WHERE product_name IS NOT NULL AND product_name != '' ORDER BY product_name ASC");
while ($pres && ($p = mysqli_fetch_assoc($pres))) {
    $known_products[] = $p['product_name'];
}
foreach (array_keys($billing_rules) as $name) {
    if (!in_array($name, $known_products, true)) {
        $known_products[] = $name;
    }
}
sort($known_products);

$mappings = [];
$mres = mysqli_query($mysqli, "SELECT * FROM pax8_client_map ORDER BY pax8_company_name ASC");
while ($mres && ($m = mysqli_fetch_assoc($mres))) {
    $mappings[] = $m;
}

$clients_list = [];
$cres = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
while ($cres && ($c = mysqli_fetch_assoc($cres))) {
    $clients_list[] = $c;
}

$reconciliation = pax8ReconciliationRows($mysqli);

?>
<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-cloud-download-alt me-2"></i>Pax8</h3>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?= $active_tab === 'connection' ? 'active' : '' ?>" href="?tab=connection">Connection</a></li>
            <li class="nav-item"><a class="nav-link <?= $active_tab === 'client_mapping' ? 'active' : '' ?>" href="?tab=client_mapping">Client Mapping</a></li>
            <li class="nav-item"><a class="nav-link <?= $active_tab === 'reconciliation' ? 'active' : '' ?>" href="?tab=reconciliation">Reconciliation</a></li>
            <li class="nav-item"><a class="nav-link <?= $active_tab === 'billing_rules' ? 'active' : '' ?>" href="?tab=billing_rules">Billing Rules</a></li>
        </ul>

        <?php if ($active_tab === 'connection') { ?>
            <div class="alert alert-info">
                Pulls Pax8 subscription costs for reconciliation against what RivetMSP bills each client. One-way
                PULL only - this never pushes anything to Pax8. Pax8 support has only been verified against a
                local test double in this build, never Pax8's real servers - double-check against Pax8's current
                Partner API reference before relying on it live.
            </div>
            <div class="row mb-3">
                <div class="col-sm-6">
                    <strong>Status:</strong>
                    <?php if ($is_connected) { ?>
                        <span class="badge text-bg-success">Connected</span>
                    <?php } else { ?>
                        <span class="badge text-bg-secondary">Not Connected</span>
                    <?php } ?>
                    <?php if ($pax8_last_sync) { ?>
                        <div class="text-muted small mt-1">Last synced: <?= nullable_htmlentities($pax8_last_sync) ?></div>
                    <?php } ?>
                </div>
                <div class="col-sm-6 text-end">
                    <?php if ($integration_id) { ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="pax8TestConnBtn">
                            <i class="fas fa-plug me-1"></i>Test Connection
                        </button>
                        <form action="settings_pax8.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <button type="submit" name="sync_pax8_now" class="btn btn-success btn-sm">
                                <i class="fas fa-sync me-1"></i>Sync Now
                            </button>
                        </form>
                        <?php if ($pax8_billing_sync_enabled) { ?>
                        <form action="settings_pax8.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <button type="submit" name="sync_pax8_billing_now" class="btn btn-warning btn-sm">
                                <i class="fas fa-file-invoice-dollar me-1"></i>Sync Billing Now
                            </button>
                        </form>
                        <?php } ?>
                        <a href="settings_pax8.php?disconnect_pax8=1&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>" class="btn btn-outline-danger btn-sm confirm-link">
                            <i class="fas fa-unlink me-1"></i>Disconnect
                        </a>
                    <?php } ?>
                </div>
            </div>
            <div id="pax8TestConnResult" class="mb-3"></div>

            <form action="settings_pax8.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group">
                    <label>Pax8 API Client ID</label>
                    <input type="text" class="form-control" name="pax8_client_id" value="<?= nullable_htmlentities($pax8_client_id) ?>" placeholder="Pax8 Partner API Client ID">
                </div>
                <div class="form-group">
                    <label>Pax8 API Client Secret</label>
                    <input type="password" class="form-control" name="pax8_client_secret" placeholder="<?= $has_secret ? 'Stored - leave blank to keep current' : 'Pax8 Partner API Client Secret' ?>">
                    <small class="form-text text-muted">Stored encrypted. Leave blank to keep the existing secret.</small>
                </div>
                <div class="form-group">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="pax8Enabled" name="pax8_enabled" value="1" <?= $pax8_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pax8Enabled">Enabled</label>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="pax8BillingSyncEnabled" name="pax8_billing_sync_enabled" value="1" <?= $pax8_billing_sync_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pax8BillingSyncEnabled">Automated Billing - update client recurring invoices from Pax8 license counts</label>
                    </div>
                    <small class="form-text text-muted">
                        Global switch only. A client's invoice is never touched unless you ALSO enable automated
                        billing for that specific client on the Client Mapping tab, and only for Pax8 products
                        that have a price set on the Billing Rules tab.
                    </small>
                </div>
                <button type="submit" name="save_pax8_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>

            <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
            document.getElementById('pax8TestConnBtn')?.addEventListener('click', function () {
                var btn = this, result = document.getElementById('pax8TestConnResult');
                btn.disabled = true;
                fetch('settings_pax8.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'test_pax8_connection=1&csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>'
                }).then(function (r) { return r.json(); }).then(function (data) {
                    result.innerHTML = data.success
                        ? '<div class="alert alert-success mb-0">Connection OK.</div>'
                        : '<div class="alert alert-danger mb-0">' + (data.error || 'Connection failed.') + '</div>';
                    btn.disabled = false;
                }).catch(function () {
                    result.innerHTML = '<div class="alert alert-danger mb-0">Request failed.</div>';
                    btn.disabled = false;
                });
            });
            </script>

        <?php } elseif ($active_tab === 'client_mapping') { ?>
            <?php if (empty($mappings)) { ?>
                <div class="alert alert-info">No Pax8 companies pulled yet. Run a sync from the Connection tab.</div>
            <?php } else { ?>
                <div class="alert alert-secondary">
                    "Automated Billing" updates/creates license line items on a dedicated recurring invoice for
                    that client from their current Pax8 subscriptions, using the prices set on the Billing Rules
                    tab. It requires the global Automated Billing switch (Connection tab) to also be on.
                </div>
                <table class="table table-striped table-hover">
                    <thead><tr><th>Pax8 Company</th><th>Mapped Client / Automated Billing</th><th>Billing Invoice</th></tr></thead>
                    <tbody>
                        <?php foreach ($mappings as $m) { ?>
                            <tr>
                                <td><?= nullable_htmlentities($m['pax8_company_name']) ?></td>
                                <td>
                                    <form action="settings_pax8.php" method="post" class="d-flex align-items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="map_id" value="<?= intval($m['map_id']) ?>">
                                        <select class="form-control form-control-sm" name="client_id" style="max-width:260px">
                                            <option value="0">- Unmapped -</option>
                                            <?php foreach ($clients_list as $cl) { ?>
                                                <option value="<?= intval($cl['client_id']) ?>" <?= intval($m['client_id']) === intval($cl['client_id']) ? 'selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option>
                                            <?php } ?>
                                        </select>
                                        <div class="form-check form-switch mb-0" title="Automated Billing">
                                            <input type="checkbox" class="form-check-input" name="billing_sync_enabled" value="1" <?= intval($m['billing_sync_enabled']) ? 'checked' : '' ?> <?= intval($m['client_id']) ? '' : 'disabled title="Map to a client first"' ?>>
                                        </div>
                                        <button type="submit" name="save_pax8_client_mapping" class="btn btn-sm btn-primary">Save</button>
                                    </form>
                                </td>
                                <td>
                                    <?php if (!empty($m['recurring_invoice_id'])) { ?>
                                        <a href="../agent/recurring_invoice.php?recurring_invoice_id=<?= intval($m['recurring_invoice_id']) ?>">#<?= intval($m['recurring_invoice_id']) ?></a>
                                    <?php } else { ?>
                                        <span class="text-muted">&mdash; created on first sync</span>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

        <?php } elseif ($active_tab === 'reconciliation') { ?>
            <?php if (empty($reconciliation)) { ?>
                <div class="alert alert-info">No mapped clients with Pax8 subscription data yet.</div>
            <?php } else { ?>
                <div class="alert alert-secondary">
                    Compares each mapped client's current Pax8 subscription cost against their current RivetMSP
                    recurring-invoice total. Client-level totals in this version, not per-product/SKU.
                </div>
                <table class="table table-striped table-hover">
                    <thead><tr><th>Client</th><th class="text-end">Pax8 Cost /mo</th><th class="text-end">Billed /mo</th><th class="text-end">Margin</th><th class="text-end">Margin %</th></tr></thead>
                    <tbody>
                        <?php foreach ($reconciliation as $r) { ?>
                            <tr class="<?= $r['margin'] < 0 ? 'table-danger' : '' ?>">
                                <td><?= nullable_htmlentities($r['client_name']) ?></td>
                                <td class="text-end"><?= number_format($r['pax8_cost'], 2) ?></td>
                                <td class="text-end"><?= number_format($r['billed'], 2) ?></td>
                                <td class="text-end"><?= number_format($r['margin'], 2) ?></td>
                                <td class="text-end"><?= $r['margin_pct'] === null ? '&mdash;' : $r['margin_pct'] . '%' ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>
        <?php } else { ?>
            <div class="alert alert-secondary">
                A subscription for a Pax8 product with no enabled rule here is never billed-back to the client -
                it is skipped, not guessed at with a default price. "Markup" prices off Pax8's own cost for that
                subscription; "Fixed" charges the same price per seat regardless of what Pax8 charges you.
            </div>
            <table class="table table-striped table-hover">
                <thead><tr><th>Pax8 Product</th><th>Mode</th><th class="text-end">Fixed Price /seat</th><th class="text-end">Markup %</th><th>Enabled</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($known_products as $product_name) {
                        $rule = $billing_rules[$product_name] ?? ['pricing_mode' => 'markup', 'sell_price' => 0, 'markup_percent' => 0, 'enabled' => 0];
                    ?>
                        <tr>
                            <td colspan="6">
                                <form action="settings_pax8.php" method="post" class="d-flex align-items-center gap-2 flex-wrap">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="product_name" value="<?= htmlspecialchars($product_name) ?>">
                                    <span style="min-width:220px"><?= nullable_htmlentities($product_name) ?></span>
                                    <select class="form-control form-control-sm" name="pricing_mode" style="max-width:190px">
                                        <option value="markup" <?= $rule['pricing_mode'] === 'markup' ? 'selected' : '' ?>>Markup over Pax8 cost</option>
                                        <option value="fixed" <?= $rule['pricing_mode'] === 'fixed' ? 'selected' : '' ?>>Fixed price</option>
                                    </select>
                                    <span class="text-muted small">Fixed $</span>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="sell_price" value="<?= number_format((float) $rule['sell_price'], 2) ?>" style="max-width:100px">
                                    <span class="text-muted small">Markup %</span>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" name="markup_percent" value="<?= number_format((float) $rule['markup_percent'], 2) ?>" style="max-width:90px">
                                    <div class="form-check form-switch mb-0" title="Enabled">
                                        <input type="checkbox" class="form-check-input" name="rule_enabled" value="1" <?= intval($rule['enabled']) ? 'checked' : '' ?>>
                                    </div>
                                    <button type="submit" name="save_pax8_billing_rule" class="btn btn-sm btn-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php if (empty($known_products)) { ?>
                        <tr><td colspan="6" class="text-muted">No Pax8 products seen yet - run a sync on the Connection tab first.</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
</div>
<?php require_once "includes/footer.php"; ?>
