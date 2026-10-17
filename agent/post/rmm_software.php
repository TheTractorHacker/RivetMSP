<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the software deployment pages and the Deployment tab of the asset page (RMM Phase 5, RivetCore 1.0.0-rc.21): detect, dry_run, run, save_package, delete_package,
 * add_version, retire_version, add_host, remove_host, assign, unassign.
 *
 * A direct endpoint like agent/post/rmm_patch.php. The prologue (rivetRmmAutoPostBegin) checks POST, the CSRF token, the module and the `software_deploy` sub-switch; every action then
 * calls RivetCore's technician API as the signed-in user, which decides again (rmm.software.view / manage / deploy, rmm.admin for the host list and for lowering a package's
 * two-person flag, the client scope, the confirmation rule of a real run, the two-person approvals, audit). The form fields are converted into the exact typed JSON Core wants
 * (ints as ints, booleans as booleans) and ONLY the fields of the chosen format are sent. Nothing a person types here becomes a job parameter: Core rebuilds every value an agent
 * receives from the catalog row. A real run is only sent when the form carries the confirmation box; Core refuses it without `confirm: true` too.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_software_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'software_deploy', (int) $session_user_id, (string) $session_name);
$ctx['mysqli'] = $mysqli;
$api = static fn (string $method, array $segments, ?array $body = null): array => rivetRmmSoftwareApi($ctx, $method, $segments, [], $body);
$ret = $ctx['return'];
$catalog = '/agent/rmm_software_catalog.php';
$assignments = '/agent/rmm_software_assignments.php';
$action = rivetRmmAutoStr('action', 40);

switch ($action) {
    case 'detect':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        if ($dev < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing device.'], '', $ret);
        }
        $r = $api('POST', [$dev, 'deployments', 'detect']);
        rivetRmmAutoDone($r, !empty($r['data']['skipped']) ? ($r['data']['skipped'] === 'nothing_to_detect' ? 'No package is assigned to this device, so there is nothing to ask.' : 'A check is already waiting for this device.')
            : 'Check requested. The device answers at its next check-in. Nothing is downloaded or installed.', $ret);

    case 'dry_run':
    case 'run':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        $pkg = rivetRmmAutoInt('package_id') ?? 0;
        $act = rivetRmmAutoStr('run_action', 12);
        if ($dev < 1 || $pkg < 1 || !in_array($act, ['install', 'uninstall'], true)) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Choose a package and what to do.'], '', $ret);
        }
        $body = ['package_id' => $pkg, 'action' => $act, 'mode' => $action === 'run' ? 'run' : 'dry_run'];
        if ($action === 'run') {
            if (($_POST['confirm_run'] ?? '') !== '1') {
                rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick the confirmation box before installing or removing.'], '', $ret);
            }
            $body['confirm'] = true;
        }
        $r = $api('POST', [$dev, 'deployments', 'run'], $body);
        if (($r['data']['state'] ?? '') === 'pending_approval') {
            $msg = 'Sent for approval. A second person has to confirm it under Approvals before anything runs on the device.';
        } elseif (!empty($r['data']['skipped'])) {
            $msg = 'A software job is already waiting or running on this device.';
        } else {
            $msg = ($action === 'run' ? 'Queued.' : 'Dry run queued.') . ' The device picks it up at its next check-in.' . ($action === 'run' ? '' : ' A dry run changes nothing: the result says what would happen.');
        }
        rivetRmmAutoDone($r, $msg, $ret);

    case 'save_package':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        [$body, $err] = rivetRmmSoftwarePackageBody($_POST);
        $back = $catalog . ($id > 0 ? '?package_id=' . $id : '?new=1');
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = $id > 0 ? $api('PUT', ['software_packages', $id], $body) : $api('POST', ['software_packages'], $body);
        $newId = (int) ($r['data']['package_id'] ?? 0);
        rivetRmmAutoDone($r, $id > 0 ? 'Package saved.' : 'Package created. Add a version to it, then assign it.', $r['ok'] ? $catalog . '?package_id=' . ($id > 0 ? $id : $newId) : $back);

    case 'delete_package':
        rivetRmmAutoDone($api('DELETE', ['software_packages', rivetRmmAutoInt('package_id') ?? 0]), 'Package deleted.', $catalog);

    case 'add_version':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        [$body, $err] = rivetRmmSoftwareVersionBody($_POST);
        $back = $catalog . '?package_id=' . $id . '#versions';
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        rivetRmmAutoDone($api('POST', ['software_packages', $id, 'versions'], $body), 'Version added. It cannot be changed afterwards; add a new one instead.', $back);

    case 'retire_version':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        $retire = ($_POST['retired'] ?? '') === '1';
        rivetRmmAutoDone($api('PATCH', ['software_packages', $id, 'versions', rivetRmmAutoInt('version_id') ?? 0], ['retired' => $retire]), $retire ? 'Version retired.' : 'Version restored.', $catalog . '?package_id=' . $id . '#versions');

    case 'add_host':
        rivetRmmAutoDone($api('POST', ['software_hosts'], ['host' => rivetRmmAutoStr('host', 253), 'note' => rivetRmmAutoStr('note', 120)]), 'Host allowed.', $catalog . '#hosts');

    case 'remove_host':
        $host = rivetRmmAutoStr('host', 253);
        if ($host === '') {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing host.'], '', $catalog . '#hosts');
        }
        rivetRmmAutoDone($api('DELETE', ['software_hosts', $host]), 'Host removed. Packages that download from it are refused until it is allowed again.', $catalog . '#hosts');

    case 'assign':
        $pkg = rivetRmmAutoInt('package_id') ?? 0;
        $act = rivetRmmAutoStr('assign_action', 12);
        $scope = rivetRmmAutoReadScope('sa', ['global', 'client', 'site', 'group', 'tag', 'device']);
        if ($pkg < 1 || !in_array($act, ['install', 'uninstall'], true)) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Choose a package and what to do.'], '', $assignments);
        }
        if ($scope['type'] !== 'global' && $scope['id'] < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Choose where the package applies.'], '', $assignments);
        }
        $body = ['package_id' => $pkg, 'scope_type' => $scope['type'], 'scope_id' => $scope['id'], 'action' => $act];
        $ver = rivetRmmAutoInt('version_id') ?? 0;
        if ($ver > 0) {
            $body['version_id'] = $ver;
        }
        $r = $api('POST', ['software_assignments'], $body);
        rivetRmmAutoDone($r, ($r['data']['state'] ?? '') === 'pending_approval' ? 'Saved, switched off. A second person has to approve this assignment under Approvals before it takes effect.'
            : 'Assigned. Devices there are asked what they have; nothing is installed until live installs are on and the ring\'s delay has passed.', $assignments);

    case 'unassign':
        rivetRmmAutoDone($api('DELETE', ['software_assignments', rivetRmmAutoInt('assignment_id') ?? 0]), 'Assignment removed. Software already installed stays installed.', $assignments);
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
