<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the Starter pack page (RMM, RivetCore 1.0.0-rc.13): preview (plan), install, preview an uninstall, uninstall. Browser forms get a flash message and a redirect
 * back, with the per-item result kept for the next page load; a request that asks for JSON gets JSON. Every action is a call into RivetCore's StarterActions as the
 * signed-in user, which decides (rmm.admin, the signing key, idempotence, edit protection) and writes the activity-log lines and the event; this file checks the CSRF token,
 * the module and the `scripts` switch, and turns the form into a selection. An EMPTY selection is refused here: Core reads an empty selection as the whole pack.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_starter_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);
$actions = rivetRmmModule($mysqli)->starterActions();
$who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
$page = RIVET_RMM_STARTER_PAGE;
$action = rivetRmmAutoStr('action', 30);
if (!in_array($action, ['plan', 'install', 'uninstall_preview', 'uninstall'], true)) {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $page);
}
$sel = rivetRmmStarterReadSelection($_POST);
if ($sel['ids'] === []) {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick at least one script or policy draft first. Nothing is chosen for you.'], '', $page);
}
$selection = ['ids' => $sel['ids']];
if ($sel['policy_params'] !== []) {
    $selection['policy_params'] = $sel['policy_params'];
}

switch ($action) {
    case 'plan':
        $r = $actions->plan($who, $selection);
        $kind = 'plan';
        $ok = 'Preview ready. Nothing was changed.';
        break;
    case 'install':
        $r = $actions->install($who, $selection);
        $kind = 'install';
        $ok = $r->message;
        break;
    case 'uninstall_preview':
        $r = $actions->uninstall($who, $selection, true);
        $kind = 'uninstall_preview';
        $ok = 'Preview ready. Nothing was changed.';
        break;
    default:
        $r = $actions->uninstall($who, $selection, false);
        $kind = 'uninstall';
        $ok = $r->message;
}
if ($r->ok) {
    $_SESSION[RIVET_RMM_STARTER_SESSION] = rivetRmmStarterStash($kind, $r->data, $sel);
    if ($kind === 'install' || $kind === 'uninstall') {
        $ok = ($kind === 'install' ? 'Install finished: ' : 'Uninstall finished: ') . rivetRmmStarterCountsText((array) ($r->data['counts'] ?? [])) . '.';
    }
}
rivetRmmAutoDone(rivetRmmStarterResult($r), $ok, $page . '#starter-result', ['counts' => $r->data['counts'] ?? null]);
