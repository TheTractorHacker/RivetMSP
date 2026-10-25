<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Teams and Slack notifications (GitHub roadmap #26), against the REAL code on a SCRATCH database, real HTTP
 * (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/chat_webhooks.php
 *
 * This is a VERIFICATION, not a new feature: investigating before writing any code found that Teams/Slack
 * notifications already work end-to-end through infrastructure this edition already has - RivetCore (the
 * shared package this edition already depends on, no version bump needed) ships 'slack' and 'teams' as
 * registered webhook Destination presets with their own URL validation, setup docs and a native
 * PayloadFormatter that shapes an event into Slack Block Kit JSON or a Teams Adaptive Card; RivetMSP's own
 * admin/settings_webhooks.php already drives entirely off Core's generic Destinations registry, already lists
 * both in admin/includes/webhook_form_lib.php's RIVET_WEBHOOK_POPULAR quick-pick, and already derives
 * webhook_format from the chosen destination automatically ($format = $dest->format in rivetWebhookCollect()).
 * Nothing new was built - this proves the existing wiring actually produces a correctly Slack/Teams-shaped
 * delivery for a real event, end to end through the real admin add-webhook flow and the real "Send test" tool.
 *
 * Covers: both presets are in the popular quick-pick list; adding a webhook with destination=slack or teams
 * through the real admin flow auto-stores the matching webhook_format; the real "Send test" tool
 * (admin/webhook_tools.php?action=test) delivers through the real dispatcher to a loopback receiver and the
 * BODY THAT ACTUALLY ARRIVED is Slack Block Kit shaped for slack and an Adaptive Card for teams - not just
 * that the HTTP call succeeded; a non-admin is refused the webhook tools endpoint.
 */
require __DIR__ . '/support/security_lib.php';

use RivetCore\Webhooks\Destinations;

echo "\n--- the presets exist and are already surfaced as quick picks ---\n";
$ok(Destinations::has('slack'), "RivetCore\\Webhooks\\Destinations has 'slack'");
$ok(Destinations::has('teams'), "RivetCore\\Webhooks\\Destinations has 'teams'");
require_once "$root/admin/includes/webhook_form_lib.php";
$ok(in_array('slack', RIVET_WEBHOOK_POPULAR, true) && in_array('teams', RIVET_WEBHOOK_POPULAR, true), 'both are already in the popular quick-pick list admins see first');

echo "\n--- fixtures ---\n";
$q("DELETE FROM webhooks WHERE webhook_name LIKE 'chatwh-test-%'");
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='chatwh-test-admin', user_email='chatwh-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

// A loopback receiver that captures the raw body of whatever gets delivered to it - a stand-in for Slack's/
// Teams' own webhook endpoint, never a real one.
$captureSlack = sys_get_temp_dir() . '/chatwh_capture_slack_' . bin2hex(random_bytes(4)) . '.json';
$captureTeams = sys_get_temp_dir() . '/chatwh_capture_teams_' . bin2hex(random_bytes(4)) . '.json';
function chatwh_start_receiver(string $captureFile): array {
    global $root;
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
    $logf = sys_get_temp_dir() . "/chatwh_recv_$port.log";
    $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", "$root/tests/support/webhook_capture_router.php"];
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logf, 'a'], 2 => ['file', $logf, 'a']], $pipes, $root, array_merge(getenv(), ['WEBHOOK_CAPTURE_FILE' => $captureFile]));
    register_shutdown_function(function () use ($proc) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } });
    for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
    return ['port' => $port];
}
$recvSlack = chatwh_start_receiver($captureSlack);
$recvTeams = chatwh_start_receiver($captureTeams);

$sdir = sys_get_temp_dir() . '/chatwh_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);   // already sets RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1, so a loopback target is allowed
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
// admin/post.php routes by the Referer's basename (which admin/post/<name>.php to include), not by $path.
$post = fn (string $path, array $f) => sec_web($base, 'POST', $path, $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], [], ['Referer: ' . $base . '/admin/settings_webhooks.php']);

// Both presets enforce their own platform-specific URL shape (Destination::urlMatches(), checked at ADD
// time) - hooks.slack.com for slack, logic.azure.com/powerplatform.com for teams - so a loopback test URL
// can never be ADDED through the real form under either destination. That is a deliberate, correct guard
// against an admin picking "Slack" and pasting an unrelated URL, and is itself checked as its own
// assertion below. It means the end-to-end delivery check right after it has to get a loopback URL onto a
// stored row a different way (direct SQL), since this test cannot add a real reachable Slack/Teams
// endpoint - what it CAN and does prove for real is that a stored slack/teams-destination row delivers a
// correctly Slack/Teams-shaped payload through the real dispatcher, which is what #26 is actually about.
$sampleUrls = ['slack' => 'https://hooks.slack.com/services/T000/B000/XXXX', 'teams' => 'https://prod-00.westus.logic.azure.com/workflows/abc/triggers/manual/paths/invoke'];
foreach ($sampleUrls as $dest => $sampleUrl) {
    [$c] = $post('/admin/post.php', [
        'add_webhook' => '1', 'webhook_destination' => $dest, 'webhook_name' => "chatwh-test-$dest-realurl",
        'webhook_url' => "http://127.0.0.1:1/", 'webhook_method' => 'POST', 'webhook_auth_mode' => 'none', 'webhook_events' => ['ticket.created'],
    ]);
    $ok((int) $one("SELECT COUNT(*) FROM webhooks WHERE webhook_name = 'chatwh-test-$dest-realurl'") === 0, "$dest: a URL that does not match this platform's own shape is refused, not silently accepted");
    [$c] = $post('/admin/post.php', [
        'add_webhook' => '1', 'webhook_destination' => $dest, 'webhook_name' => "chatwh-test-$dest-format",
        'webhook_url' => $sampleUrl, 'webhook_method' => 'POST', 'webhook_auth_mode' => 'none', 'webhook_events' => ['ticket.created'],
    ]);
    $row = $rows("SELECT webhook_destination, webhook_format FROM webhooks WHERE webhook_name = 'chatwh-test-$dest-format'")[0] ?? null;
    $ok($row !== null && $row['webhook_destination'] === $dest && $row['webhook_format'] === $dest, "$dest: adding with a URL matching this platform's real shape stores webhook_format derived automatically from the destination (no separate choice needed)");
}

foreach (['slack' => [$recvSlack, $captureSlack], 'teams' => [$recvTeams, $captureTeams]] as $dest => [$recv, $captureFile]) {
    echo "\n--- $dest: a stored destination=$dest row delivers a correctly shaped payload ---\n";
    $q("INSERT INTO webhooks SET webhook_name='chatwh-test-$dest-deliver', webhook_destination='$dest', webhook_format='$dest', webhook_url='" . $esc("http://127.0.0.1:{$recv['port']}/") . "', webhook_method='POST', webhook_auth_mode='none', webhook_events='ticket.created', webhook_enabled=1");
    $webhookId = (int) $db->insert_id;

    [$c, $body] = $post('/admin/webhook_tools.php', ['action' => 'test', 'webhook_id' => $webhookId]);
    $j = json_decode($body, true);
    $ok($c === 200 && ($j['ok'] ?? false) === true, "$dest: the real 'Send test' tool reports success");

    $received = null;
    for ($i = 0; $i < 30; $i++) {
        if (is_file($captureFile) && filesize($captureFile) > 0) { $received = json_decode((string) file_get_contents($captureFile), true); break; }
        usleep(100000);
    }
    $ok($received !== null, "$dest: the mock receiver actually received a delivery");

    if ($dest === 'slack') {
        $ok(is_array($received) && isset($received['blocks']) && is_array($received['blocks']), 'slack: the body that arrived is Slack Block Kit shaped (a "blocks" array), not generic JSON');
        $headerBlock = $received['blocks'][0] ?? [];
        $ok(($headerBlock['type'] ?? null) === 'header', 'slack: the first block is a header, matching Slack\'s own incoming-webhook shape');
    } else {
        $ok(is_array($received) && ($received['type'] ?? null) === 'message' && isset($received['attachments']) && is_array($received['attachments']), 'teams: the body that arrived is an Adaptive Card message ("type":"message", "attachments"), not generic JSON');
        $card = $received['attachments'][0]['content'] ?? [];
        $ok(($card['type'] ?? null) === 'AdaptiveCard', 'teams: the attachment content is a real AdaptiveCard');
    }
}

echo "\n--- a non-admin is refused the webhook tools endpoint ---\n";
$q("DELETE FROM user_roles WHERE role_name = 'chatwh-test-NonAdmin'");
$q("DELETE FROM users WHERE user_email = 'chatwh-test-nonadmin@example.test'");
$q("INSERT INTO user_roles SET role_name='chatwh-test-NonAdmin', role_is_admin=0, role_type=1");
$roleNonAdmin = (int) $db->insert_id;
$q("INSERT INTO users SET user_name='chatwh-test-nonadmin', user_email='chatwh-test-nonadmin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleNonAdmin");
$nonAdmin = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$nonAdmin ON DUPLICATE KEY UPDATE user_id=user_id");
$sidNonAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $nonAdmin, 'csrf_token' => 'csrftok12345678']);
$webhookIdAny = (int) $one("SELECT webhook_id FROM webhooks WHERE webhook_name = 'chatwh-test-slack'");
[$c, $body] = sec_web($base, 'POST', '/admin/webhook_tools.php', $sidNonAdmin, ['action' => 'test', 'webhook_id' => $webhookIdAny, 'csrf_token' => 'csrftok12345678']);
$j = json_decode($body, true);
$ok($c === 403 && ($j['ok'] ?? true) === false, 'a non-admin is refused (403), cannot trigger a delivery for any webhook');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM webhook_deliveries WHERE webhook_id IN (SELECT webhook_id FROM webhooks WHERE webhook_name LIKE 'chatwh-test-%')");
$q("DELETE FROM webhooks WHERE webhook_name LIKE 'chatwh-test-%'");
$q("DELETE FROM users WHERE user_id = $nonAdmin");
$q("DELETE FROM user_roles WHERE user_role_id = $roleNonAdmin");
@unlink($captureSlack);
@unlink($captureTeams);
