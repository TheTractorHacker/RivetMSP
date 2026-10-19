<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Agent updates > Build a new version: the card's progress poll (26.10.31). A direct endpoint like agent/post/rmm_installer.php. POST only, CSRF-checked, administrators only
 * (RmmAbility::ADMIN). Answers JSON {state,label,detail,run_url,...} and, when the build was started with "publish automatically" and the release is there, publishes it once with
 * the same code as the Publish button. The token is never part of an answer.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_agent_build.php';

mysqli_report(MYSQLI_REPORT_OFF);
$bp_out = static function (int $status, array $body): void {
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
$bp_tok = $_POST['csrf_token'] ?? null;
if (!is_string($bp_tok) || $bp_tok === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $bp_tok)) {
    $bp_out(403, ['ok' => false, 'error' => 'Your session token has expired. Reload the page.']);
}
if (!rivetRmmEnabled($mysqli)) {
    $bp_out(404, ['ok' => false, 'error' => 'The RMM module is switched off.']);
}
$bp_rmm = rivetRmmModule();
if ($bp_rmm->authorizer()->check((int) $session_user_id, \RivetCore\Rmm\Authz\RmmAbility::ADMIN, 0, false) !== null) {
    $bp_out(403, ['ok' => false, 'error' => 'Administrators only.']);
}
$bp_feed = new \RivetMsp\AgentFeed\Feed($mysqli);
if (!$bp_feed->ready()) {
    $bp_out(409, ['ok' => false, 'error' => 'Run the database update first.']);
}
$bp = new \RivetMsp\AgentFeed\Build($bp_feed, static function (string $a, string $t): void {
    logAction('Endpoint Agent', $a, $t);
});
$bp_d = $bp->poll(\RivetMsp\AgentFeed\rivetAgentBuildPublisher($bp_rmm, $bp_feed, $bp_rmm->admin(), rivetRmmPrincipal((int) $session_user_id, (string) $session_name), (int) $session_user_id, (string) $session_name));
$bp_out(200, ['ok' => true, 'build' => $bp_d === null ? null : array_intersect_key($bp_d, array_flip(['version', 'state', 'label', 'detail', 'run_url', 'release_seen', 'published', 'publish_message', 'active']))]);
