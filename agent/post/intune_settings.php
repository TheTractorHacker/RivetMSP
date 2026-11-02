<?php

// Microsoft 365 / Entra ID tenant credentials, PER CLIENT (roadmap #10 slice 1), plus the Intune
// device "Sync Now" action (roadmap #10 slice 2).
// Every action re-derives client_id from the row being acted on for a delete/sync (never trusted
// from a posted value alone) before calling enforceClientAccess() - the same IDOR guard this
// edition's other per-client integration/deploy pages (e.g. agent/post/panel_deploy.php) already use.

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_microsoft_integration'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id'] ?? 0);
    enforceClientAccess($client_id);

    if ($client_id < 1) {
        flash_alert('A department is required', 'error');
        redirect();
    }

    $tenant_id = sanitizeInput($_POST['tenant_id'] ?? '');
    $azure_client_id = sanitizeInput($_POST['azure_client_id'] ?? '');
    $enabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? 1 : 0;
    $intune_sync_enabled = (isset($_POST['intune_sync_enabled']) && $_POST['intune_sync_enabled'] === '1') ? 1 : 0;
    $directory_sync_enabled = (isset($_POST['directory_sync_enabled']) && $_POST['directory_sync_enabled'] === '1') ? 1 : 0;

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT microsoft_integration_id, tenant_id, azure_client_id FROM microsoft_integrations WHERE client_id = $client_id LIMIT 1"));

    // A blank Client Secret field means "keep the current one" - never overwritten with an empty value, and
    // the plaintext never round-trips back to the browser (the page only ever shows a placeholder, never the
    // stored value). A new secret, or a changed tenant/app, invalidates any cached OAuth token (the columns
    // exist now for slice 2's sync engine; clearing them here costs nothing and avoids a stale-token bug later).
    $secret_sql = '';
    $client_secret_raw = (string) ($_POST['client_secret'] ?? '');
    if ($client_secret_raw !== '') {
        $secret_enc = mysqli_real_escape_string($mysqli, encryptSetting(trim($client_secret_raw)));
        $secret_sql = ", client_secret_enc = '$secret_enc'";
    }
    if ($secret_sql !== '' || ($existing && ((string) $existing['tenant_id'] !== $tenant_id || (string) $existing['azure_client_id'] !== $azure_client_id))) {
        $secret_sql .= ", token_cache_enc = NULL, token_expires_at = NULL";
    }

    if ($existing) {
        $id = intval($existing['microsoft_integration_id']);
        mysqli_query($mysqli, "UPDATE microsoft_integrations SET tenant_id = '$tenant_id', azure_client_id = '$azure_client_id', enabled = $enabled, intune_sync_enabled = $intune_sync_enabled, directory_sync_enabled = $directory_sync_enabled $secret_sql WHERE microsoft_integration_id = $id AND client_id = $client_id");
    } else {
        mysqli_query($mysqli, "INSERT INTO microsoft_integrations SET client_id = $client_id, tenant_id = '$tenant_id', azure_client_id = '$azure_client_id', enabled = $enabled, intune_sync_enabled = $intune_sync_enabled, directory_sync_enabled = $directory_sync_enabled, created_by = $session_user_id $secret_sql");
    }

    $client_name = sanitizeInput(getFieldById('clients', $client_id, 'client_name'));

    logAction("Settings", "Edit", "$session_name updated the Microsoft/Entra tenant for $client_name", $client_id);

    flash_alert("Microsoft 365 / Entra ID tenant saved for <strong>$client_name</strong>");

    redirect("intune_settings.php?client_id=$client_id");
}

if (isset($_GET['delete_microsoft_integration'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $microsoft_integration_id = intval($_GET['delete_microsoft_integration']);

    // Re-derive client_id from the row itself, never trust the posted/URL client_id for the access decision -
    // it is used below only to know where to redirect back to.
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM microsoft_integrations WHERE microsoft_integration_id = $microsoft_integration_id LIMIT 1"));
    if (!$row) {
        flash_alert('Not found', 'error');
        redirect();
    }
    $client_id = intval($row['client_id']);
    enforceClientAccess($client_id);

    mysqli_query($mysqli, "DELETE FROM microsoft_integrations WHERE microsoft_integration_id = $microsoft_integration_id AND client_id = $client_id");

    $client_name = sanitizeInput(getFieldById('clients', $client_id, 'client_name'));

    logAction("Settings", "Delete", "$session_name removed the Microsoft/Entra tenant for $client_name", $client_id);

    flash_alert("Microsoft 365 / Entra ID tenant removed for <strong>$client_name</strong>");

    redirect("intune_settings.php?client_id=$client_id");
}

if (isset($_POST['sync_microsoft_intune'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $microsoft_integration_id = intval($_POST['microsoft_integration_id'] ?? 0);

    // Re-derive client_id from the row itself, same IDOR guard as the delete action above - the posted
    // client_id (used only to know where to redirect back to) is never trusted for the access decision.
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations WHERE microsoft_integration_id = $microsoft_integration_id LIMIT 1"));
    if (!$row) {
        flash_alert('Not found', 'error');
        redirect();
    }
    $client_id = intval($row['client_id']);
    enforceClientAccess($client_id);

    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/GraphException.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/GraphTokenCache.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/InMemoryGraphTokenCache.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/MysqliGraphTokenCache.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/GraphClient.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/GraphClientFactory.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/IntuneAssetMapper.php';
    require_once dirname(__DIR__, 2) . '/src/Integrations/Microsoft/IntuneSyncService.php';

    $result = \RivetMSP\Integrations\Microsoft\IntuneSyncService::run($mysqli, $row, intval($session_user_id), 90);

    $client_name = sanitizeInput(getFieldById('clients', $client_id, 'client_name'));

    if ($result['ok']) {
        logAction("Settings", "Edit", "$session_name ran an Intune device sync for $client_name: {$result['message']}", $client_id);
        flash_alert(nullable_htmlentities($result['message']));
    } else {
        logAction("Settings", "Edit", "$session_name's Intune device sync for $client_name failed: {$result['message']}", $client_id);
        flash_alert(nullable_htmlentities($result['message']), $result['locked'] ? 'warning' : 'error');
    }

    redirect("intune_settings.php?client_id=$client_id");
}
