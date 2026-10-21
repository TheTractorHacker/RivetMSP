<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Policy starter pack actions from the Policies page (includes/rmm_policy_pack_ui.php): preview (plan), install, update, reset, uninstall. A direct JSON
 * endpoint for the AJAX preview (action=plan) and a plain form handler for the real operation (pp_op), so the card works with or without JavaScript -
 * same dual pattern as agent/post/rmm_installer.php. Every write is authorized server-side by RivetCore\Rmm\Technician\PolicyPackActions (rmm.admin);
 * hiding a button here is cosmetic only.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_policy_pack_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'code' => 'csrf']);
    exit;
}
if (!rivetRmmEnabled($GLOBALS['mysqli'])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'The RMM module is switched off.', 'code' => 'module_disabled']);
    exit;
}

$pp_rmm = rivetRmmModule();
$pp_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);
$pp_ids = array_values(array_filter(array_map('strval', (array) ($_POST['ids'] ?? [])), static fn (string $s): bool => $s !== ''));
$pp_selection = ['ids' => $pp_ids === [] ? null : $pp_ids];
if (!empty($_POST['confirm_instance_wide'])) {
    $pp_selection['confirm_instance_wide'] = true;
}
if (!empty($_POST['include_live'])) {
    $pp_selection['include_live'] = true;
}

/** action=plan: AJAX preview, never redirects. Body: {op, ids?, confirm_instance_wide?, include_live?} -> JSON. */
if (($_POST['action'] ?? '') === 'plan') {
    $pp_op = (string) ($_POST['op'] ?? 'install');
    if (!in_array($pp_op, \RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller::OPS, true)) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'op must be install, update, reset or uninstall.', 'code' => 'invalid']);
        exit;
    }
    $r = $pp_rmm->policyPackActions()->run($pp_who, $pp_op, $pp_selection, true);
    http_response_code($r->ok ? 200 : $r->http);
    header('Content-Type: application/json');
    echo json_encode($r->ok ? ['success' => true] + $r->data : ['success' => false, 'error' => $r->message, 'code' => $r->code]);
    exit;
}

// Plain-form submit: pp_op is "install" (the main form, acting on ids[] ticked) or "update:<id>" / "reset:<id>" / "uninstall:<id>" (a per-row button).
$pp_raw = (string) ($_POST['pp_op'] ?? '');
if ($pp_raw === '') {
    flash_alert('Choose an action.', 'error');
    redirect();
}
if (str_contains($pp_raw, ':')) {
    [$pp_op, $pp_id] = explode(':', $pp_raw, 2);
    $pp_selection['ids'] = [$pp_id];
} else {
    $pp_op = $pp_raw;
}
if (!in_array($pp_op, \RivetCore\Rmm\Policy\StarterPack\PolicyPackInstaller::OPS, true)) {
    flash_alert('Unknown action.', 'error');
    redirect();
}
$pp_result = $pp_rmm->policyPackActions()->run($pp_who, $pp_op, $pp_selection, false);
if (!$pp_result->ok) {
    flash_alert(nullable_htmlentities($pp_result->message), 'error');
    redirect();
}
$_SESSION[RIVET_RMM_PP_SESSION] = $pp_result->data;
$pp_counts = (array) ($pp_result->data['counts'] ?? []);
$pp_bad = ((int) ($pp_counts['failed'] ?? 0)) + ((int) ($pp_counts['blocked'] ?? 0));
flash_alert(($pp_bad > 0 ? 'Done, with ' . $pp_bad . ' item(s) that could not be applied - see the list below.' : 'Done.'), $pp_bad > 0 ? 'warning' : 'success');
redirect();
