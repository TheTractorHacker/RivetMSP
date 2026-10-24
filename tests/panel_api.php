<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * RivetPanel device-token API (GitHub roadmap "Prompt 2"; RivetCore 1.0.0-rc.37, migration 0040/0041, DB 2.6.111),
 * against the REAL code on a SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/panel_api.php
 *
 * Covers: the DB step; the module switch (off by default, 503 module_disabled while off, PanelModule's own
 * 'enabled' option - not just a page-level gate); single-use enrollment unchanged by default; a fleet token
 * (maxUses > 1) enrolling several devices, the cap enforced, and the old single-use behaviour still exactly
 * single-use; the full enroll -> create ticket (with an attachment) -> list -> chat -> staff reply -> internal
 * note hidden flow over real HTTP; a client-level device blocked until the per-client setting is on; revoking a
 * device/token (401 on the next call); the Deploy page UI (fleet badge, token/device tables, revoke links).
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.111 step ---\n";
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_devices' AND COLUMN_NAME = 'kind'") === 1, 'endpoint_agent_devices has kind');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'panel_client_settings'") === 1, 'panel_client_settings exists');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'config_core_panel_enabled'") === 1, 'settings has config_core_panel_enabled');
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.111', '>='), 'LATEST_DATABASE_VERSION is 2.6.111 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, 'CREATE TABLE `panel_ticket_owner`') && str_contains($sqlText, '`config_core_panel_enabled` tinyint(1) NOT NULL DEFAULT 0'), 'db.sql carries the panel schema and the switch, off by default');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.110'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.110') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.110 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");
$ok((int) $one('SELECT config_core_panel_enabled FROM settings LIMIT 1') === 0, 'the module is off by default (not flipped on by the migration)');

echo "\n--- fixtures ---\n";
// Stale rows from an earlier interrupted run of this file (a fatal crash, a Ctrl-C mid-run) can leave an
// enrollment-token row with the same label as this run's but a now-gone client_id, since the end-of-file
// cleanup below scopes by $clientA (a fresh id every run) rather than by label - delete by label FIRST, before
// any assertion below reads "the token with this label" and risks picking the wrong, stale duplicate.
$q("DELETE FROM endpoint_agent_enrollment_tokens WHERE label LIKE 'pnl-test-%'");
$q("DELETE FROM endpoint_agent_devices WHERE hostname LIKE 'FLEET-%' OR hostname LIKE 'SINGLE-%' OR hostname = 'CLIENTLVL' OR hostname = 'FLEET-REENROLL'");
$q("DELETE FROM clients WHERE client_name LIKE 'pnl-test-%'");
// Enrollment failures are rate-limited per address from a shared DB attempt log (10 failures/600s, PANEL_API.md
// "Rate limits") - this scratch DB accumulates them across every run of this file (and anything else hitting
// panel_enroll from 127.0.0.1), so clear it first or a real test run can itself be rate-limited.
$q("DELETE FROM endpoint_agent_enroll_attempts");
$q("DELETE FROM categories WHERE category_name LIKE 'pnl-test-%'");
$q("DELETE FROM panel_client_settings WHERE client_id IN (SELECT client_id FROM clients WHERE client_name LIKE 'pnl-test-%')");
$q("INSERT INTO clients SET client_name='pnl-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_name='pnl-test-Jane', contact_client_id=$clientA"); $jane = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name='pnl-test-Hardware', category_type='Ticket'"); $catId = (int) $db->insert_id;
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='pnl-test-admin', user_email='pnl-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/pnl_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$post = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678']);
// install_id and client_request_id must both be real UUIDs (PanelApi validates the shape, not just "some unique
// hex string") - a plain panel_uuid() is 422 invalid.
function panel_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}
// sec_web() has no raw-JSON-body helper (the device API's requests are JSON, not form-encoded), so build one directly with curl here.
function panel_raw(string $base, string $method, string $path, ?string $token, $body = null): array {
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) { $headers[] = "Authorization: Bearer $token"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body); }
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, json_decode((string) $raw, true) ?? []];
}

echo "\n--- module switch (off by default, 503) ---\n";
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => 'rvtp1.' . str_repeat('a', 12) . '.' . str_repeat('b', 40), 'device' => ['install_id' => panel_uuid(), 'hostname' => 'x', 'os' => 'windows']]);
$ok($c === 503 && ($j['code'] ?? '') === 'module_disabled', 'while off, panel_enroll answers 503 module_disabled - PanelModule\'s own gate, not just the Deploy page');
$q("UPDATE settings SET config_core_panel_enabled = 1 WHERE company_id = 1");
\RivetMSP\Core\CoreBridge::reset();

echo "\n--- single-use token: default unchanged ---\n";
$post(['create_token' => '1', 'client_id' => $clientA, 'contact_id' => $jane, 'label' => 'pnl-test-single', 'ttl_hours' => 24, 'max_uses' => 1]);
$tok1 = (string) $one("SELECT token_hash FROM endpoint_agent_enrollment_tokens WHERE label = 'pnl-test-single'");
$tokenRow1 = $rows("SELECT token_id, max_uses FROM endpoint_agent_enrollment_tokens WHERE label = 'pnl-test-single'")[0];
$ok((int) $tokenRow1['max_uses'] === 1, 'a plain token (no fleet size given) defaults to max_uses 1, unchanged');
// Mint via Core directly to get the real plaintext (the deploy-page flash session value isn't something this test can read back from a stateless curl call).
require_once "$root/includes/panel_bootstrap.php";
$single = rivetPanelModule($db)->enrollment()->createToken($clientA, $jane, 24, 'pnl-test-single-direct', $admin, 1);
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => $single['token'], 'device' => ['install_id' => panel_uuid(), 'hostname' => 'SINGLE-PC', 'os' => 'windows', 'os_version' => '11', 'app_version' => '0.1']]);
$ok($c === 201 && isset($j['device_token']) && $j['contact']['contact_id'] === $jane, 'a single-use token enrolls one device, with the contact it was issued for');
$deviceTokenSingle = (string) $j['device_token'];
[$c2] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => $single['token'], 'device' => ['install_id' => panel_uuid(), 'hostname' => 'SECOND-PC', 'os' => 'windows']]);
$ok($c2 === 403, 'and a second PC with the SAME single-use token is refused (403 forbidden - used up)');

echo "\n--- fleet token: maxUses > 1 ---\n";
$fleet = rivetPanelModule($db)->enrollment()->createToken($clientA, null, 24, 'pnl-test-fleet', $admin, 3);
$fleetRow = $rows("SELECT max_uses FROM endpoint_agent_enrollment_tokens WHERE label = 'pnl-test-fleet'")[0];
$ok((int) $fleetRow['max_uses'] === 3, 'the fleet token is stored with max_uses = 3');
$fleetDeviceIds = [];
foreach (['FLEET-1', 'FLEET-2', 'FLEET-3'] as $host) {
    [$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => $fleet['token'], 'device' => ['install_id' => panel_uuid(), 'hostname' => $host, 'os' => 'linux']]);
    $ok($c === 201, "fleet device $host enrolls with the same token");
    $fleetDeviceIds[] = (int) $j['device_id'];
    $ok($j['contact'] === null, "$host is client-level (the fleet token named no contact)");
}
$ok(count(array_unique($fleetDeviceIds)) === 3, 'each fleet device gets its own distinct device id/credential');
[$c4, $j4] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => $fleet['token'], 'device' => ['install_id' => panel_uuid(), 'hostname' => 'FLEET-4', 'os' => 'linux']]);
$ok($c4 === 403, 'a 4th device with a token capped at 3 is refused');
$ok((int) $one("SELECT use_count FROM endpoint_agent_enrollment_tokens WHERE token_id = " . (int) $fleet['token_id']) === 3, 'use_count stops at the cap, not 4');

echo "\n--- ticket flow (contact device) ---\n";
[$c, $j] = panel_raw($base, 'GET', '/api/v1/panel_me', $deviceTokenSingle);
$ok($c === 200 && $j['can_create_tickets'] === true, 'panel_me says this device may create tickets');
[$c, $j] = panel_raw($base, 'GET', '/api/v1/panel_categories', $deviceTokenSingle);
$ok($c === 200 && in_array($catId, array_column($j['categories'], 'category_id'), true), 'panel_categories lists the real category');
$attachmentB64 = base64_encode('hello from the panel');
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_tickets', $deviceTokenSingle, [
    'subject' => 'pnl-test ticket', 'body' => 'Printer is jammed', 'category_id' => $catId, 'display_name' => 'jsmith',
    'client_request_id' => panel_uuid(),
    'attachments' => [['filename' => 'photo.png', 'content_type' => 'image/png', 'data_base64' => base64_encode("\x89PNG\r\n\x1a\n" . 'fakepngdata')]],
]);
$ok($c === 201 && ($j['replayed'] ?? null) === false && isset($j['ticket_id']), 'creating a ticket with an attachment succeeds, not replayed');
$ticketId = (int) $j['ticket_id'];
$ok((int) $one("SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticketId") === $clientA, 'the ticket is filed under the credential\'s client, not anything the request could have named');
$ok((int) $one("SELECT ticket_contact_id FROM tickets WHERE ticket_id = $ticketId") === $jane, 'and the credential\'s contact');
$ok((int) $one("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticketId") === 1, 'the attachment was stored');

[$c, $j] = panel_raw($base, 'GET', '/api/v1/panel_tickets?limit=20&offset=0', $deviceTokenSingle);
$ok($c === 200 && in_array($ticketId, array_column($j['tickets'], 'ticket_id'), true), 'the ticket shows up in the device\'s own list');

$q("UPDATE tickets SET ticket_created_by = $admin WHERE ticket_id = $ticketId");   // irrelevant edit to isolate the next assertion from create-time state
\logAction('Support', 'Edit', 'staff replied (test fixture)', $clientA, $ticketId);
$q("INSERT INTO ticket_replies SET ticket_reply = 'We are on it', ticket_reply_type = 'Public', ticket_reply_by = $admin, ticket_reply_ticket_id = $ticketId");
$q("INSERT INTO ticket_replies SET ticket_reply = 'INTERNAL-ONLY-NOTE', ticket_reply_type = 'Internal', ticket_reply_by = $admin, ticket_reply_ticket_id = $ticketId");
[$c, $j] = panel_raw($base, 'GET', "/api/v1/panel_ticket_chat?ticket_id=$ticketId&after=0&limit=50", $deviceTokenSingle);
$bodies = implode('|', array_column($j['messages'], 'body'));
$ok($c === 200 && str_contains($bodies, 'We are on it'), 'the staff reply is visible in chat');
$ok(!str_contains($bodies, 'INTERNAL-ONLY-NOTE'), 'the internal note is never returned to the panel');

[$c, $j] = panel_raw($base, 'POST', "/api/v1/panel_ticket_chat?ticket_id=$ticketId", $deviceTokenSingle, ['body' => 'Thanks!', 'display_name' => 'jsmith']);
$ok($c === 201 && isset($j['message_id']), 'the device can reply in chat');

echo "\n--- client-level device, the per-client setting ---\n";
$fleetDeviceToken = null;
// Re-enroll one fleet device fresh to capture its plaintext credential (the loop above discarded it).
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => $fleet['token'], 'device' => ['install_id' => panel_uuid(), 'hostname' => 'FLEET-REENROLL', 'os' => 'linux']]);
// The token is used up (3/3); re-enrolling the SAME install_id with the SAME exhausted token should still be
// refused - a fresh fleet token is minted instead to get a usable client-level credential for this section.
$ok($c === 403, 'the exhausted fleet token still refuses a brand new install id too');
$fleet2 = rivetPanelModule($db)->enrollment()->createToken($clientA, null, 24, 'pnl-test-clientlevel', $admin, 1);
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_enroll', null, ['enrollment_token' => $fleet2['token'], 'device' => ['install_id' => panel_uuid(), 'hostname' => 'CLIENTLVL', 'os' => 'windows']]);
$ok($c === 201, 'fixture: a fresh client-level device enrolls');
$clDeviceToken = (string) $j['device_token'];
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_tickets', $clDeviceToken, ['subject' => 'cl ticket', 'body' => 'x', 'client_request_id' => panel_uuid()]);
$ok($c === 403 && ($j['code'] ?? '') === 'client_level_disabled', 'a client-level device cannot create a ticket while the per-client setting is off (the default)');
$post(['set_client_level' => '1', 'client_id' => $clientA, 'allow_client_level' => '1']);
$ok((int) $one("SELECT allow_client_level_tickets FROM panel_client_settings WHERE client_id = $clientA") === 1, 'the Deploy page toggle flips the setting');
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_tickets', $clDeviceToken, ['subject' => 'cl ticket', 'body' => 'x', 'client_request_id' => panel_uuid()]);
$ok($c === 201, 'and once it is on, the same client-level device can create a ticket');
$clTicketId = (int) $j['ticket_id'];
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_tickets', $deviceTokenSingle, ['subject' => 'contact cannot see cl', 'body' => 'x', 'client_request_id' => panel_uuid()]);
[$c, $j2] = panel_raw($base, 'GET', "/api/v1/panel_ticket_chat?ticket_id=$clTicketId", $deviceTokenSingle);
$ok($c === 404 && ($j2['code'] ?? '') === 'not_found', "a contact device cannot read a client-level device's own ticket (404, same as a missing one)");

echo "\n--- idempotent creation ---\n";
$rid = panel_uuid();
[$c, $j] = panel_raw($base, 'POST', '/api/v1/panel_tickets', $deviceTokenSingle, ['subject' => 'retry me', 'body' => 'x', 'client_request_id' => $rid]);
$firstTicket = (int) $j['ticket_id'];
[$c2, $j2] = panel_raw($base, 'POST', '/api/v1/panel_tickets', $deviceTokenSingle, ['subject' => 'retry me', 'body' => 'x', 'client_request_id' => $rid]);
$ok($c2 === 200 && ($j2['replayed'] ?? null) === true && (int) $j2['ticket_id'] === $firstTicket, 'retrying the same client_request_id replays the same ticket, 200 not 201, and never creates a second one');
$ok((int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_subject = 'retry me'") === 1, 'exactly one ticket exists for that request id');

echo "\n--- revocation ---\n";
$deviceIdSingle = (int) $one("SELECT device_id FROM endpoint_agent_devices WHERE hostname = 'SINGLE-PC'");
$panel = rivetPanelModule($db);
$panel->admin()->revokeDevice($deviceIdSingle, 'test revoke', $admin);
[$c] = panel_raw($base, 'GET', '/api/v1/panel_me', $deviceTokenSingle);
$ok($c === 401, 'a revoked device gets 401 on its very next call');

echo "\n--- Deploy page UI ---\n";
[$c, $body] = sec_web($base, 'GET', '/agent/panel_deploy.php?client_id=' . $clientA, $sidAdmin);
$ok($c === 200 && str_contains($body, 'pnl-test-fleet') && str_contains($body, 'x3'), 'the Deploy page lists the fleet token with its x3 badge');
$ok(str_contains($body, 'FLEET-1') || str_contains($body, 'SINGLE-PC'), 'and the enrolled devices');
$ok(str_contains($body, 'confirm-link'), 'revoke actions use the RivetDialog confirm-link pattern, not a native confirm()');
$q("UPDATE settings SET config_core_panel_enabled = 0 WHERE company_id = 1");
\RivetMSP\Core\CoreBridge::reset();
[$c, $body] = sec_web($base, 'GET', '/agent/panel_deploy.php?client_id=' . $clientA, $sidAdmin);
$ok($c === 200 && str_contains($body, 'is turned off'), 'with the module switched off, the Deploy page shows the same "turned off" notice Problems & Changes/Workflows use');
$q("UPDATE settings SET config_core_panel_enabled = 1 WHERE company_id = 1");
\RivetMSP\Core\CoreBridge::reset();

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

// cleanup
$q("DELETE FROM ticket_attachments WHERE ticket_attachment_ticket_id IN (SELECT ticket_id FROM tickets WHERE ticket_client_id = $clientA)");
$q("DELETE FROM ticket_replies WHERE ticket_reply_ticket_id IN (SELECT ticket_id FROM tickets WHERE ticket_client_id = $clientA)");
$q("DELETE FROM tickets WHERE ticket_client_id = $clientA");
$q("DELETE FROM endpoint_agent_devices WHERE client_id = $clientA AND kind = 'panel'");
$q("DELETE FROM endpoint_agent_enrollment_tokens WHERE client_id = $clientA AND kind = 'panel'");
$q("DELETE FROM panel_client_settings WHERE client_id = $clientA");
$q("DELETE FROM contacts WHERE contact_id = $jane");
$q("DELETE FROM categories WHERE category_id = $catId");
$q("DELETE FROM clients WHERE client_id = $clientA");
$q("UPDATE settings SET config_core_panel_enabled = 0 WHERE company_id = 1");
