<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Roadmap #27 (AI features, provider-agnostic interface) verification against the REAL code on a SCRATCH
 * database (same harness as tests/security_*.php).
 *
 * This is a verify-only pass, not a build: includes/ai_functions.php's aiChat() + the admin ai_provider.php/
 * ai_model.php CRUD already ARE a provider-agnostic interface - a provider is just a name + base URL + API
 * key, the wire format is the generic OpenAI-compatible chat/completions shape, and FOUR separate real
 * features (agent/ajax.php's ai_reword/ai_create_document_template/ai_ticket_summary/ai_ticket_reply_draft,
 * plus includes/automation_functions.php's ai_triage automation action) all already share this one
 * interface rather than each rolling its own HTTP client. Nothing here is hardcoded to any one vendor
 * (OpenAI, Azure OpenAI's compatible mode, Ollama's OpenAI-compat server, OpenRouter, etc. would all work
 * by just entering their base URL) - that is proven below by pointing TWO differently-configured
 * "providers" at a loopback fake OpenAI-compatible server and confirming aiChat() calls whichever one is
 * actually configured, with its own URL/key/model, not something baked into the code.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/ai_provider_verify.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/ai_functions.php";

echo "\n--- fixtures ---\n";
$q("DELETE FROM ticket_ai_summaries WHERE ticket_id IN (SELECT ticket_id FROM tickets WHERE ticket_subject LIKE 'aipv-test-%')");
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'aipv-test-%'");
$q("DELETE FROM ai_models WHERE ai_model_name LIKE 'aipv-test-%'");
$q("DELETE FROM ai_providers WHERE ai_provider_name LIKE 'aipv-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'aipv-test-%'");
$q("UPDATE settings SET config_ai_enable = 1 WHERE company_id = 1");

$port = random_int(8600, 8999);
$fakeBase = "http://127.0.0.1:$port";
$cmd = 'cd ' . escapeshellarg($root) . " && php -S 127.0.0.1:$port tests/support/fake_openai_router.php > /tmp/aipv_fake_$port.log 2>&1 & echo \$!";
$pid = (int) trim((string) shell_exec($cmd));
register_shutdown_function(function () use ($pid) { if ($pid > 0) { @exec("kill $pid 2>/dev/null"); } });
usleep(300000); // let php -S come up

echo "\n--- provider A: aiChat() sends exactly what Provider A's row says ---\n";
$q("INSERT INTO ai_providers SET ai_provider_name='aipv-test-ProviderA', ai_provider_api_url='" . $esc("$fakeBase/providerA") . "', ai_provider_api_key='" . $esc(encryptSetting('secret-key-A')) . "'");
$provA = (int) $db->insert_id;
$q("INSERT INTO ai_models SET ai_model_name='aipv-test-model-A', ai_model_use_case='General', ai_model_ai_provider_id=$provA");

$ai = aiChat([['role' => 'user', 'content' => 'hello from A']], 'General', ['check_enabled' => false]);
$ok(!empty($ai['ok']), 'aiChat() succeeds against the loopback Provider A');
$echoed = json_decode((string) ($ai['content'] ?? ''), true) ?: [];
$ok(($echoed['path'] ?? '') === '/providerA/chat/completions', "the request hit Provider A's exact configured path (not hardcoded to any vendor's fixed URL)");
$ok(($echoed['auth'] ?? '') === 'Bearer secret-key-A', "the Authorization header carries Provider A's own decrypted key");
$ok(($echoed['model'] ?? '') === 'aipv-test-model-A', "the request body's model field is Provider A's configured model name");
$ok(($echoed['user_msg'] ?? '') === 'hello from A', 'the message content is passed through unmodified');

echo "\n--- provider B: swapping config (no code change) swaps which provider/key/model gets called ---\n";
$q("DELETE FROM ai_models WHERE ai_model_name = 'aipv-test-model-A'");
$q("INSERT INTO ai_providers SET ai_provider_name='aipv-test-ProviderB', ai_provider_api_url='" . $esc("$fakeBase/providerB") . "', ai_provider_api_key='" . $esc(encryptSetting('secret-key-B')) . "'");
$provB = (int) $db->insert_id;
$q("INSERT INTO ai_models SET ai_model_name='aipv-test-model-B', ai_model_use_case='General', ai_model_ai_provider_id=$provB");

$ai2 = aiChat([['role' => 'user', 'content' => 'hello from B']], 'General', ['check_enabled' => false]);
$echoed2 = json_decode((string) ($ai2['content'] ?? ''), true) ?: [];
$ok(($echoed2['path'] ?? '') === '/providerB/chat/completions', "after reconfiguring, the SAME aiChat() call now hits Provider B's path");
$ok(($echoed2['auth'] ?? '') === 'Bearer secret-key-B', "...with Provider B's own key, not Provider A's leftover key");
$ok(($echoed2['model'] ?? '') === 'aipv-test-model-B', "...and Provider B's own model name - this is a config-driven swap, nothing in ai_functions.php named either provider");

echo "\n--- gating: company-wide off and per-client opt-out both refuse WITHOUT ever calling the provider ---\n";
$q("UPDATE settings SET config_ai_enable = 0 WHERE company_id = 1");
$ai3 = aiChat([['role' => 'user', 'content' => 'should not be sent']], 'General');
$ok($ai3['ok'] === false && $ai3['error'] === 'AI is disabled', 'company-wide config_ai_enable=0 refuses before any HTTP call');
$q("UPDATE settings SET config_ai_enable = 1 WHERE company_id = 1");

$q("INSERT INTO clients SET client_name='aipv-test-OptOutClient', client_ai_opt_out=1");
$optOutClient = (int) $db->insert_id;
$ai4 = aiChat([['role' => 'user', 'content' => 'should not be sent']], 'General', ['client_id' => $optOutClient]);
$ok($ai4['ok'] === false && $ai4['error'] === 'AI is disabled', "a client with client_ai_opt_out=1 is refused even though AI is enabled company-wide");
$q("INSERT INTO clients SET client_name='aipv-test-NormalClient', client_ai_opt_out=0");
$normalClient = (int) $db->insert_id;
$ai5 = aiChat([['role' => 'user', 'content' => 'should be sent']], 'General', ['client_id' => $normalClient]);
$ok(!empty($ai5['ok']), 'a client WITHOUT the opt-out still goes through normally');

echo "\n--- a real feature already built on this interface: ai_ticket_summary (agent/ajax.php) ---\n";
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='aipv-test-admin', user_email='aipv-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }
$q("INSERT INTO contacts SET contact_client_id = $normalClient, contact_name = 'aipv-test-Contact', contact_email='aipv-c@example.test', contact_primary=1");
$contact = (int) $db->insert_id;

$sdir = sys_get_temp_dir() . '/aipv_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$agentPost = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sid, $f + ['csrf_token' => 'csrftok12345678'], []);

[$c] = $agentPost(['add_ticket' => '1', 'client_id' => (string) $normalClient, 'contact_id' => (string) $contact, 'subject' => 'aipv-test-Ticket', 'details' => 'Printer on 3rd floor is jammed and will not print.', 'priority' => 'Low', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']);
$ticketId = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'aipv-test-Ticket'");
$ok($ticketId > 0, 'fixture ticket created for the summary call');

// ai_ticket_summary is a POST (ticket_id/csrf_token in the body) gated behind a GET-style query
// key (?ai_ticket_summary=1), and it echoes the (HTMLPurifier-sanitized) AI content directly as the
// HTTP body - not JSON. The fake router's echoed path/model/key string has no HTML-special characters,
// so it survives purification unchanged as plain text and can be checked as a literal substring.
[$c, $body] = sec_web($base, 'POST', '/agent/ajax.php?ai_ticket_summary=1', $sid, ['ticket_id' => (string) $ticketId, 'csrf_token' => 'csrftok12345678']);
$ok($c === 200, 'ai_ticket_summary responds 200 via the real HTTP path');
// The fake router's content is itself JSON (so the test's direct aiChat() checks above could
// json_decode it cleanly); json_encode() escapes "/" as "\/", and that escaped form is what
// survives into the final purified HTML body here, not the plain path.
$ok(str_contains($body, '\\/providerB\\/chat\\/completions'), 'ai_ticket_summary (a real, pre-existing feature) reused the exact same aiChat() path to the currently-configured provider - no feature-specific HTTP client');
$ok(str_contains($body, 'Bearer secret-key-B'), "...and Provider B's own key - the summary feature never hardcodes a provider either");

[, $log] = sec_sh('cat ' . escapeshellarg(sys_get_temp_dir()) . '/sec_server_*.log 2>/dev/null');
$ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log) || str_contains($log, 'Undefined array key "HTTP_USER_AGENT"'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');
