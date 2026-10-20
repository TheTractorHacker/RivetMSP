<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the hypervisor pages (RivetCore 1.0.0-rc.29, migration 0036): ignore_vm, unignore_vm, ignore_host, unignore_host.
 *
 * A direct endpoint like agent/post/rmm_patch.php. The prologue (rivetRmmAutoPostBegin) checks POST, the CSRF token, the module and the
 * `hypervisor` sub-switch; every action then calls RivetCore\Rmm\Hypervisor\HypervisorActions directly as the signed-in user, which decides
 * again (rmm.hypervisor.manage, the client scope, the audit entry). Browser forms get a flash message and a redirect back; a request that
 * asks for JSON gets JSON.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_hypervisor_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'hypervisor', (int) $session_user_id, (string) $session_name);
$who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
$rmm = rivetRmmModule($mysqli);
$action = rivetRmmAutoStr('action', 40);
/** @param \RivetCore\Rmm\Technician\ActionResult $r */
$done = static function ($r) use ($ctx): never {
    rivetRmmAutoDone(['ok' => $r->ok, 'status' => $r->http, 'error' => $r->message], $r->message, $ctx['return']);
};

switch ($action) {
    case 'ignore_vm':
    case 'unignore_vm':
        $vmId = rivetRmmAutoInt('vm_id') ?? 0;
        if ($vmId < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing VM.'], '', $ctx['return']);
        }
        $done($rmm->hypervisorActions()->setVmIgnored($who, $vmId, $action === 'ignore_vm', rivetRmmAutoStr('reason', 200)));

    case 'ignore_host':
    case 'unignore_host':
        $deviceId = rivetRmmAutoInt('device_id') ?? 0;
        if ($deviceId < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing device.'], '', $ctx['return']);
        }
        $done($rmm->hypervisorActions()->setHostIgnored($who, $deviceId, $action === 'ignore_host'));

    default:
        rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ctx['return']);
}
