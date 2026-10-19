<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Agent updates > Local build: the section's progress poll (26.10.33). A direct endpoint like agent_build_poll.php. POST only, CSRF-checked, administrators only (RmmAbility::ADMIN).
 * Answers JSON {ok, jobs:[{id,state,label,text,pct,note,log_tail,result,active}], hosts:[{id,dot}]}. Text fields are plain text; the page writes them with textContent.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_agent_localbuild.php';

mysqli_report(MYSQLI_REPORT_OFF);
$lp_out = static function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$lp_tok = $_POST['csrf_token'] ?? null;
if (!is_string($lp_tok) || $lp_tok === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $lp_tok)) {
    $lp_out(403, ['ok' => false, 'error' => 'Your session token has expired. Reload the page.']);
}
if (!rivetRmmEnabled($mysqli)) {
    $lp_out(404, ['ok' => false, 'error' => 'The RMM module is switched off.']);
}
$lp_rmm = rivetRmmModule();
if ($lp_rmm->authorizer()->check((int) $session_user_id, \RivetCore\Rmm\Authz\RmmAbility::ADMIN, 0, false) !== null) {
    $lp_out(403, ['ok' => false, 'error' => 'Administrators only.']);
}
$lp = \RivetMsp\AgentFeed\rivetLocalBuild($mysqli, $lp_rmm);
if (!$lp->ready()) {
    $lp_out(409, ['ok' => false, 'error' => 'Run the database update first.']);
}
$lp_jobs = [];
foreach ($lp->jobs() as $j) {
    $lp_jobs[] = ['id' => (int) $j['job_id'], 'state' => (string) $j['state'], 'label' => ucfirst((string) $j['state']), 'text' => (string) $j['progress']['text'], 'pct' => (int) $j['progress']['pct'], 'note' => (string) $j['note'],
        'log_tail' => (string) $j['log_tail'], 'result' => (string) $j['result'], 'active' => (bool) $j['active']];
}
$lp_hosts = [];
foreach ($lp->hosts() as $hRow) {
    $lp_hosts[] = ['id' => (int) $hRow['host_id'], 'dot' => (string) $hRow['dot']];
}
$lp_out(200, ['ok' => true, 'jobs' => $lp_jobs, 'hosts' => $lp_hosts, 'active' => $lp->hasActive()]);
