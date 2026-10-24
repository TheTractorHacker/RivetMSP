<?php

/*
 * RivetPanel deploy page POST handler (GitHub roadmap "Prompt 2") - agent/panel_deploy.php's four actions.
 * Auto-loaded by agent/post.php's glob, same convention as every other agent/post/*.php handler.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['create_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id']);
    enforceClientAccess();

    require_once "../includes/panel_bootstrap.php";
    if (!\RivetMSP\Core\CoreBridge::enabled('core.panel.enabled')) {
        flash_alert('RivetPanel is turned off', 'error');
        redirect();
    }

    $contact_id = !empty($_POST['contact_id']) ? intval($_POST['contact_id']) : null;
    $label = sanitizeInput($_POST['label'] ?? '');
    $ttl_hours = max(1, min(72, intval($_POST['ttl_hours'] ?? 24)));
    // "Number of PCs": 1 = single-use (one PC), >1 mints a capped multi-use fleet token for an RMM mass
    // deployment - PanelEnrollment::createToken() clamps it to PanelLimits::$enrollMaxUses itself.
    $max_uses = max(1, intval($_POST['max_uses'] ?? 1));

    try {
        $result = rivetPanelModule($mysqli)->enrollment()->createToken($client_id, $contact_id, $ttl_hours, $label, (int) $session_user_id, $max_uses);
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect();
    }

    $_SESSION['panel_new_token'] = $result;
    flash_alert($max_uses > 1 ? "Fleet token created (up to $max_uses devices)" : 'Enrollment token created');
    redirect();
}

if (isset($_POST['set_client_level'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id']);
    enforceClientAccess();

    require_once "../includes/panel_bootstrap.php";
    $allow = !empty($_POST['allow_client_level']);
    rivetPanelModule($mysqli)->settings()->setAllowClientLevelTickets($client_id, $allow, (int) $session_user_id);

    flash_alert($allow ? 'Client-level devices may now open tickets' : 'Client-level devices can no longer open tickets');
    redirect();
}

if (isset($_GET['revoke_token'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $token_id = intval($_GET['revoke_token']);
    // Re-derive client_id from the token row itself before checking access - a posted client_id could name any
    // client; this is the only value that matters (same IDOR guard RivetIT's reference page uses).
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM endpoint_agent_enrollment_tokens WHERE token_id = $token_id AND kind = 'panel'"));
    if (!$row) {
        flash_alert('Token not found', 'error');
        redirect();
    }
    $client_id = (int) $row['client_id'];
    enforceClientAccess($client_id);

    require_once "../includes/panel_bootstrap.php";
    rivetPanelModule($mysqli)->enrollment()->revokeToken($token_id, (int) $session_user_id);

    flash_alert('Token revoked');
    redirect();
}

if (isset($_GET['revoke_panel_device'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $device_id = intval($_GET['revoke_panel_device']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM endpoint_agent_devices WHERE device_id = $device_id AND kind = 'panel'"));
    if (!$row) {
        flash_alert('Device not found', 'error');
        redirect();
    }
    $client_id = (int) $row['client_id'];
    enforceClientAccess($client_id);

    require_once "../includes/panel_bootstrap.php";
    rivetPanelModule($mysqli)->admin()->revokeDevice($device_id, "Revoked from RivetPanel deploy page by $session_name", (int) $session_user_id);

    flash_alert('Device revoked');
    redirect();
}
