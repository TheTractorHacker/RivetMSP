<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Install-apps-with-the-agent actions from the Software catalog page (includes/rmm_auto_install_ui.php): per-package auto-install scope, vendor
 * parameters, hosted installer files, starter templates. A direct JSON/form endpoint (not loaded through agent/post.php's glob dispatcher - it is a
 * no-op there), same convention as agent/post/rmm_policy_pack.php. Every write is authorized server-side by
 * RivetCore\Rmm\AutoInstall\AutoInstallActions (rmm.software.manage/deploy, already mapped); hiding a control here is cosmetic only.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_auto_install_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    // validateCSRFToken() itself redirects to index.php on failure and never returns false.
    exit;
}

$ia_rmm = rivetRmmModule();
$ia_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);
$ia_op = (string) ($_POST['ia_op'] ?? '');

if ($ia_op === 'set_auto') {
    $ia_pid = intval($_POST['package_id'] ?? 0);
    $ia_in = ['scope' => (string) ($_POST['scope'] ?? 'off'), 'install_order' => intval($_POST['install_order'] ?? 100), 'required' => !empty($_POST['required'])];
    if ($ia_in['scope'] === 'os') {
        $ia_in['os'] = (string) ($_POST['os'] ?? 'windows');
    }
    if ($ia_in['scope'] === 'clients') {
        $ia_in['clients'] = array_values(array_filter(array_map(static fn (string $s): int => (int) trim($s), explode(',', (string) ($_POST['client_ids'] ?? ''))), static fn (int $c): bool => $c > 0));
    }
    $r = $ia_rmm->autoInstallActions()->setAuto($ia_who, $ia_pid, $ia_in);
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'success' : 'error');
    redirect();
}

if ($ia_op === 'set_param' || $ia_op === 'clear_param') {
    $ia_pid = intval($_POST['package_id'] ?? 0);
    $ia_in = ['name' => (string) ($_POST['name'] ?? '')];
    if ($ia_op === 'set_param') {
        $ia_in['value'] = (string) ($_POST['value'] ?? '');
        $r = $ia_rmm->autoInstallActions()->setParam($ia_who, $ia_pid, $ia_in);
    } else {
        $r = $ia_rmm->autoInstallActions()->clearParam($ia_who, $ia_pid, $ia_in);
    }
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'success' : 'error');
    redirect();
}

if ($ia_op === 'upload_file') {
    $ia_up = $_FILES['hosted_file'] ?? null;
    if (!is_array($ia_up) || ($ia_up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $ia_up['tmp_name'])) {
        flash_alert('Choose a file to upload, or it is larger than the PHP limit (upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . ').', 'error');
        redirect();
    }
    $r = $ia_rmm->autoInstallActions()->uploadFile($ia_who, (string) $ia_up['tmp_name'], basename((string) $ia_up['name']));
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'success' : 'error');
    redirect();
}

if ($ia_op === 'delete_file') {
    $r = $ia_rmm->autoInstallActions()->deleteFile($ia_who, intval($_POST['file_id'] ?? 0));
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'success' : 'error');
    redirect();
}

if ($ia_op === 'from_template') {
    $ia_key = (string) ($_POST['template_key'] ?? '');
    $ia_tin = ['version' => (string) ($_POST['t_version'] ?? '')];
    if (trim((string) ($_POST['t_pkg_name'] ?? '')) !== '') {
        $ia_tin['pkg_name'] = (string) $_POST['t_pkg_name'];
    }
    if (is_numeric($_POST['t_hosted_file_id'] ?? null) && (int) $_POST['t_hosted_file_id'] > 0) {
        $ia_tin['hosted_file_id'] = (int) $_POST['t_hosted_file_id'];
    } elseif (trim((string) ($_POST['t_url'] ?? '')) !== '') {
        $ia_tin['url'] = (string) $_POST['t_url'];
        if (trim((string) ($_POST['t_sha256'] ?? '')) !== '') {
            $ia_tin['sha256'] = strtolower((string) $_POST['t_sha256']);
        }
        if (is_numeric($_POST['t_size_bytes'] ?? null)) {
            $ia_tin['size_bytes'] = (int) $_POST['t_size_bytes'];
        }
    }
    if (trim((string) ($_POST['t_detect_value'] ?? '')) !== '') {
        $ia_detect = (string) $_POST['t_detect_value'];
        $ia_tin['detect_kind'] = str_starts_with(trim($ia_detect), '{') ? 'product_code' : 'uninstall_name';
        $ia_tin['detect_value'] = $ia_detect;
    }
    $r = $ia_rmm->autoInstallActions()->fromTemplate($ia_who, $ia_key, $ia_tin);
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'success' : 'error');
    redirect();
}

flash_alert('Unknown action.', 'error');
redirect();
