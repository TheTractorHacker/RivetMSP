<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the patch management pages and the Patches tab of the asset page (RMM Phase 4, RivetCore 1.0.0-rc.20): scan, approve, decline, dry_run, install,
 * save_policy, delete_policy, assign_policy, unassign_policy, save_ring, delete_ring, save_rule, delete_rule.
 *
 * A direct endpoint like agent/post/rmm_automation_alr.php. The prologue (rivetRmmAutoPostBegin) checks POST, the CSRF token, the module and the `patching` sub-switch;
 * every action then calls RivetCore's technician API as the signed-in user, which decides again (rmm.patch.view / manage / install, the client scope, the confirmation
 * rules of a real install, audit). The form fields are converted into the exact typed JSON Core wants (ints as ints). Browser forms get a flash message and a redirect
 * back; a request that asks for JSON gets JSON. A real install is only sent when the form carries the confirmation box; Core refuses it without `confirm: true` too.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_patch_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'patching', (int) $session_user_id, (string) $session_name);
$ctx['mysqli'] = $mysqli;
$api = static fn (string $method, array $segments, ?array $body = null): array => rivetRmmPatchApi($ctx, $method, $segments, [], $body);
$ret = $ctx['return'];
$policies = '/agent/rmm_patch_policies.php';
$action = rivetRmmAutoStr('action', 40);
$plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');

switch ($action) {
    case 'scan':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        if ($dev < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing device.'], '', $ret);
        }
        $r = $api('POST', [$dev, 'patches', 'scan']);
        rivetRmmAutoDone($r, !empty($r['data']['skipped']) ? 'A scan is already waiting for this device.' : 'Scan requested. The device answers at its next check-in. Nothing is downloaded or installed.', $ret);

    case 'approve':
    case 'decline':
        $ids = rivetRmmPatchPostIds('update_ids', 200);
        if ($ids === []) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick at least one update first.'], '', $ret);
        }
        $body = ['update_ids' => $ids];
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        $client = rivetRmmAutoInt('client_id') ?? 0;
        if ($dev > 0) {
            $body['device_id'] = $dev;
        } elseif ($client > 0) {
            $body['client_id'] = $client;
        }
        if ($action === 'decline' && rivetRmmAutoStr('reason', 200) !== '') {
            $body['reason'] = rivetRmmAutoStr('reason', 200);
        }
        $r = $api('POST', ['patches', $action], $body);
        $msg = ($r['data']['state'] ?? '') === 'pending_approval'
            ? 'Sent for approval. A second person has to confirm it under Approvals before the updates are approved.'
            : ($action === 'approve' ? 'Approved ' . $plural((int) ($r['data']['changed'] ?? 0), 'update row') . '.' : 'Declined ' . $plural((int) ($r['data']['changed'] ?? 0), 'update row') . '.');
        rivetRmmAutoDone($r, $msg, $ret);

    case 'dry_run':
    case 'install':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        if ($dev < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing device.'], '', $ret);
        }
        $body = ['mode' => $action === 'install' ? 'install' : 'dry_run'];
        $ids = rivetRmmPatchPostIds('update_ids', 40);
        if ($ids !== []) {
            $body['ids'] = $ids;
        }
        if ($action === 'install') {
            if (($_POST['confirm_install'] ?? '') !== '1') {
                rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick the confirmation box before installing.'], '', $ret);
            }
            $body['confirm'] = true;
            if (($_POST['confirm_reboot'] ?? '') === '1') {
                $body['reboot_policy'] = 'if_required_now';
                $body['confirm_reboot'] = true;
            }
        }
        $r = $api('POST', [$dev, 'patches', 'install'], $body);
        $n = (int) ($r['data']['queued'] ?? 0);
        $more = (int) ($r['data']['remaining'] ?? 0);
        rivetRmmAutoDone($r, ($action === 'install' ? 'Install queued for ' : 'Dry run queued for ') . $plural($n, 'update') . '. The device picks it up at its next check-in.'
            . ($more > 0 ? ' ' . $plural($more, 'more update') . ' did not fit in one job; run it again afterwards.' : '') . ($action === 'dry_run' ? ' A dry run changes nothing: the result lists what would be installed.' : ''), $ret);

    case 'save_policy':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        [$body, $err] = rivetRmmPatchPolicyBody($_POST);
        $back = $policies . ($id > 0 ? '?policy_id=' . $id : '?new=1');
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = $id > 0 ? $api('PUT', ['patch_policies', $id], $body) : $api('POST', ['patch_policies'], $body);
        $newId = (int) ($r['data']['policy']['policy_id'] ?? 0);
        rivetRmmAutoDone($r, $id > 0 ? 'Patch policy saved.' : 'Patch policy created. It applies nowhere until you assign it.', $r['ok'] ? ($newId > 0 && $id === 0 ? $policies . '?policy_id=' . $newId : $policies) : $back);

    case 'delete_policy':
        rivetRmmAutoDone($api('DELETE', ['patch_policies', rivetRmmAutoInt('policy_id') ?? 0]), 'Patch policy deleted.', $policies);

    case 'assign_policy':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        $scope = rivetRmmAutoReadScope('pa', ['global', 'client', 'site', 'group', 'tag', 'device']);
        if ($scope['type'] !== 'global' && $scope['id'] < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Choose where the policy applies.'], '', $policies . '?policy_id=' . $id);
        }
        $prio = rivetRmmAutoInt('priority') ?? 100;
        rivetRmmAutoDone($api('POST', ['patch_policies', $id, 'assignments'], ['scope_type' => $scope['type'], 'scope_id' => $scope['id'], 'priority' => max(0, min(10000, $prio)), 'enforce' => ($_POST['enforce'] ?? '') === '1']),
            'Patch policy assigned.', $policies . '?policy_id=' . $id);

    case 'unassign_policy':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        rivetRmmAutoDone($api('DELETE', ['patch_policies', $id, 'assignments', rivetRmmAutoInt('assignment_id') ?? 0]), 'Assignment removed.', rivetRmmAutoSafeReturn($ret, $policies));

    case 'save_ring':
        $id = rivetRmmAutoInt('ring_id') ?? 0;
        [$body, $err] = rivetRmmPatchRingBody($_POST);
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $policies . '#rings');
        }
        rivetRmmAutoDone($id > 0 ? $api('PUT', ['patches', 'rings', $id], $body) : $api('POST', ['patches', 'rings'], $body), 'Ring saved.', $policies . '#rings');

    case 'delete_ring':
        rivetRmmAutoDone($api('DELETE', ['patches', 'rings', rivetRmmAutoInt('ring_id') ?? 0]), 'Ring deleted.', $policies . '#rings');

    case 'save_rule':
        $id = rivetRmmAutoInt('rule_id') ?? 0;
        [$body, $err] = rivetRmmPatchRuleBody($_POST);
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $policies . '#rules');
        }
        rivetRmmAutoDone($id > 0 ? $api('PUT', ['patches', 'rules', $id], $body) : $api('POST', ['patches', 'rules'], $body), 'Approval rule saved.', $policies . '#rules');

    case 'delete_rule':
        rivetRmmAutoDone($api('DELETE', ['patches', 'rules', rivetRmmAutoInt('rule_id') ?? 0]), 'Approval rule deleted.', $policies . '#rules');
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
