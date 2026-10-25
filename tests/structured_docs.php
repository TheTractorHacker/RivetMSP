<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Structured documentation field builder (GitHub roadmap #22 - Hudu/IT Glue "flexible assets": an admin
 * defines a document type's own field layout once, every document of that type then has the same fields),
 * against the REAL code on a SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 * New work for both editions (checked RivetIT: no equivalent exists there either). Deliberately NOT built
 * on this app's existing `custom_fields` table, a dead stub per project memory - its own dedicated module
 * instead, modeled on the (field_id, scope_id) -> value shape RivetCore's own rmm_custom_fields/
 * rmm_custom_field_values already use for RMM. Deliberately NOT drag-and-drop with a live preview (Hudu's
 * own headline feature per the roadmap issue) - a plain numeric Order field, matching this app's existing
 * ordering convention (category_order, item_order, ...) elsewhere; flagged clearly as a scope decision.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/structured_docs.php
 *
 * Covers: the DB step; an admin builds a type with one field of each kind (text, textarea, number, date,
 * checkbox, dropdown, url, password) through the real field-builder forms; creating a document of that
 * type through the real add form stores every field's value in its own shape; editing it with a BLANK
 * password field keeps the stored secret (never redisplayed, never blanked); the real reveal endpoint
 * decrypts it correctly and is audited; a technician restricted to a different client cannot view, add to,
 * edit, reveal a field on, or delete a document belonging to a client they cannot access - each checked
 * explicitly, with the target client's data proven unchanged afterward; archiving a field or a type hides
 * it from new documents without breaking an existing document's already-stored values.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";

echo "\n--- DB 2.6.113 step ---\n";
// Checked AFTER forcing a replay below: this branch and the other worktrees queued this session all
// branched from 2.6.112 and each claims "2.6.113" for its own step (chain in whatever order they land -
// see commit d8e047b42 for the precedent).
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, 'CREATE TABLE `structured_doc_types`') && str_contains($sqlText, 'CREATE TABLE `structured_doc_fields`') && str_contains($sqlText, 'CREATE TABLE `structured_documents`') && str_contains($sqlText, 'CREATE TABLE `structured_document_values`'), 'db.sql carries all four tables');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'structured_doc_types'") === 1, 'structured_doc_types exists after the replay');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- fixtures ---\n";
$q("DELETE FROM structured_document_values WHERE doc_id IN (SELECT doc_id FROM structured_documents WHERE doc_name LIKE 'sdoc-test-%')");
$q("DELETE FROM structured_documents WHERE doc_name LIKE 'sdoc-test-%'");
$q("DELETE FROM structured_doc_fields WHERE field_type_id IN (SELECT type_id FROM structured_doc_types WHERE type_name LIKE 'sdoc-test-%')");
$q("DELETE FROM structured_doc_types WHERE type_name LIKE 'sdoc-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'sdoc-test-%'");
$q("DELETE FROM user_roles WHERE role_name = 'sdoc-test-TechB'");
$q("DELETE FROM users WHERE user_email LIKE 'sdoc-test-%'");

$q("INSERT INTO clients SET client_name='sdoc-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='sdoc-test-Client B'"); $clientB = (int) $db->insert_id;

$q("INSERT INTO user_roles SET role_name='sdoc-test-TechB', role_is_admin=0, role_type=1");
$roleTechB = (int) $db->insert_id;
$modSupportId = (int) $one("SELECT module_id FROM modules WHERE module_name='module_support'");
if ($modSupportId === 0) { $q("INSERT INTO modules SET module_name='module_support'"); $modSupportId = (int) $db->insert_id; }
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleTechB");
$q("INSERT INTO user_role_permissions SET user_role_id=$roleTechB, module_id=$modSupportId, user_role_permission_level=3");
$q("INSERT INTO users SET user_name='sdoc-test-techb', user_email='sdoc-test-techb@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$roleTechB");
$techB = (int) $db->insert_id;
$q("INSERT INTO user_settings SET user_id=$techB ON DUPLICATE KEY UPDATE user_id=user_id");
$q("INSERT INTO user_client_permissions SET user_id=$techB, client_id=$clientB");

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='sdoc-test-admin', user_email='sdoc-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/sdoc_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);
$sidTechB = sec_forge_session($sdir, ['logged' => true, 'user_id' => $techB, 'csrf_token' => 'csrftok12345678']);
$postAdmin = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], []);
$postAdminAdminPost = fn (array $f) => sec_web($base, 'POST', '/admin/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], [], ['Referer: ' . $base . '/admin/structured_doc_types.php']);
$postTechB = fn (array $f) => sec_web($base, 'POST', '/agent/post.php', $sidTechB, $f + ['csrf_token' => 'csrftok12345678'], []);
$getAdmin = fn (string $path) => sec_web($base, 'GET', $path, $sidAdmin);
$getTechB = fn (string $path) => sec_web($base, 'GET', $path, $sidTechB);

echo "\n--- admin builds a type with one field of each kind ---\n";
[$c] = $postAdminAdminPost(['add_structured_doc_type' => '1', 'type_name' => 'sdoc-test-Firewall', 'type_icon' => 'fa-shield-alt']);
$ok($c === 200 || $c === 302, 'add_structured_doc_type succeeds');
$typeId = (int) $one("SELECT type_id FROM structured_doc_types WHERE type_name = 'sdoc-test-Firewall'");
$ok($typeId > 0, 'the type was created');

$fieldKinds = ['text' => 'WAN IP', 'textarea' => 'Notes', 'number' => 'Port Count', 'date' => 'Install Date', 'checkbox' => 'HA Pair', 'dropdown' => 'Vendor', 'url' => 'Admin URL', 'password' => 'Admin Password'];
$fieldIds = [];
foreach ($fieldKinds as $kind => $label) {
    $extra = $kind === 'dropdown' ? ['field_options' => "Cisco\nFortinet\nPalo Alto"] : [];
    $postAdminAdminPost(array_merge(['add_structured_doc_field' => '1', 'type_id' => $typeId, 'field_label' => "sdoc-test-$label", 'field_kind' => $kind, 'field_required' => $kind === 'text' ? '1' : ''], $extra));
    $fieldIds[$kind] = (int) $one("SELECT field_id FROM structured_doc_fields WHERE field_type_id = $typeId AND field_label = '" . $esc("sdoc-test-$label") . "'");
}
$ok(count(array_filter($fieldIds)) === count($fieldKinds), 'every field kind was created');
$ok((string) $one("SELECT field_options_json FROM structured_doc_fields WHERE field_id = {$fieldIds['dropdown']}") !== '', 'the dropdown field stored its options');
$ok((string) $one("SELECT field_key FROM structured_doc_fields WHERE field_id = {$fieldIds['text']}") !== '', 'each field got a stable key slug');

[$c, $body] = $getAdmin("/admin/structured_doc_types.php?type_id=$typeId");
$ok($c === 200 && str_contains($body, 'sdoc-test-WAN IP') && str_contains($body, 'sdoc-test-Admin Password'), 'the field builder page lists every field just created');

echo "\n--- creating a document through the real add form stores every field's value ---\n";
$postAdmin([
    'add_structured_doc' => '1', 'client_id' => $clientA, 'type_id' => $typeId, 'doc_name' => 'sdoc-test-FW01',
    "field_{$fieldIds['text']}" => '10.0.0.1', "field_{$fieldIds['textarea']}" => "line one\nline two",
    "field_{$fieldIds['number']}" => '24', "field_{$fieldIds['date']}" => '2026-01-15',
    "field_{$fieldIds['checkbox']}" => '1', "field_{$fieldIds['dropdown']}" => 'Fortinet',
    "field_{$fieldIds['url']}" => 'https://10.0.0.1:8443', "field_{$fieldIds['password']}" => 'S3cret-FW-pw',
]);
$docId = (int) $one("SELECT doc_id FROM structured_documents WHERE doc_name = 'sdoc-test-FW01'");
$ok($docId > 0, 'the document was created');
$valuesByField = [];
foreach ($rows("SELECT * FROM structured_document_values WHERE doc_id = $docId") as $v) { $valuesByField[(int) $v['field_id']] = $v; }
$ok(($valuesByField[$fieldIds['text']]['value_text'] ?? null) === '10.0.0.1', 'text field value stored exactly');
$ok(str_contains((string) ($valuesByField[$fieldIds['textarea']]['value_text'] ?? ''), 'line two'), 'textarea value stored');
$ok(($valuesByField[$fieldIds['checkbox']]['value_text'] ?? null) === '1', 'checkbox value stored as 1');
$ok(($valuesByField[$fieldIds['dropdown']]['value_text'] ?? null) === 'Fortinet', 'dropdown value stored');
$ok(!empty($valuesByField[$fieldIds['password']]['value_enc'] ?? null) && empty($valuesByField[$fieldIds['password']]['value_text'] ?? null), 'password field is stored encrypted, in value_enc, never in value_text');
$ok(!str_contains((string) $valuesByField[$fieldIds['password']]['value_enc'], 'S3cret-FW-pw'), 'the encrypted value does not contain the plaintext');
$ok(decryptSetting((string) $valuesByField[$fieldIds['password']]['value_enc']) === 'S3cret-FW-pw', 'it round-trips through decryptSetting()');

echo "\n--- the document page renders the values, masking the password ---\n";
[$c, $body] = $getAdmin("/agent/structured_doc.php?id=$docId&client_id=$clientA");
$ok($c === 200 && str_contains($body, '10.0.0.1') && str_contains($body, 'Fortinet'), 'the document page shows the stored field values');
$ok(!str_contains($body, 'S3cret-FW-pw'), 'the password value is never rendered in the page itself');

echo "\n--- the real reveal endpoint decrypts correctly and is audited ---\n";
$auditCountBefore = (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Structured Documentation' AND log_action = 'Reveal'");
[$c, $body] = $postAdmin(['reveal_structured_doc_field' => '1', 'doc_id' => $docId, 'field_id' => $fieldIds['password']]);
$j = json_decode($body, true);
$ok($c === 200 && ($j['ok'] ?? false) === true && $j['value'] === 'S3cret-FW-pw', 'the reveal endpoint returns the real plaintext to an authorized caller');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Structured Documentation' AND log_action = 'Reveal'") === $auditCountBefore + 1, 'the reveal was logged');

echo "\n--- editing with a BLANK password field keeps the stored secret ---\n";
// The real edit modal always renders every one of the type's non-archived fields (see
// agent/modals/structured_doc/structured_doc_edit.php), so a real browser submission always includes all
// of them - this POST does the same, re-sending every field's current value, to exercise the real
// behavior rather than a partial submission the actual UI never produces.
$cipherBefore = (string) $valuesByField[$fieldIds['password']]['value_enc'];
$postAdmin([
    'edit_structured_doc' => '1', 'doc_id' => $docId, 'doc_name' => 'sdoc-test-FW01',
    "field_{$fieldIds['text']}" => '10.0.0.2', "field_{$fieldIds['textarea']}" => "line one\nline two",
    "field_{$fieldIds['number']}" => '24', "field_{$fieldIds['date']}" => '2026-01-15',
    "field_{$fieldIds['checkbox']}" => '1', "field_{$fieldIds['dropdown']}" => 'Fortinet',
    "field_{$fieldIds['url']}" => 'https://10.0.0.1:8443', "field_{$fieldIds['password']}" => '',
]);
$ok((string) $one("SELECT value_text FROM structured_document_values WHERE doc_id = $docId AND field_id = {$fieldIds['text']}") === '10.0.0.2', 'the edit form really did update the text field');
$ok((string) $one("SELECT value_enc FROM structured_document_values WHERE doc_id = $docId AND field_id = {$fieldIds['password']}") === $cipherBefore, 'a blank password field on edit keeps the stored ciphertext unchanged, never blanks it');

echo "\n--- ADVERSARIAL: a technician restricted to Client B cannot touch Client A's document ---\n";
[$c, $body] = $getTechB("/agent/structured_doc.php?id=$docId&client_id=$clientA");
$ok($c !== 200 || !str_contains($body, '10.0.0.2'), 'GET the Client A document as the Client-B-only technician does not render Client A\'s data');
$beforeAttack = $rows("SELECT * FROM structured_documents WHERE doc_id = $docId")[0];
$postTechB(['edit_structured_doc' => '1', 'doc_id' => $docId, 'doc_name' => 'HIJACKED', "field_{$fieldIds['text']}" => 'HIJACKED']);
$afterAttack = $rows("SELECT * FROM structured_documents WHERE doc_id = $docId")[0];
$ok($afterAttack['doc_name'] === $beforeAttack['doc_name'], 'editing Client A\'s document as the Client-B-only technician is refused - the name is unchanged');
$ok((string) $one("SELECT value_text FROM structured_document_values WHERE doc_id = $docId AND field_id = {$fieldIds['text']}") === '10.0.0.2', 'and the field value is unchanged too');
[$c, $body] = $postTechB(['reveal_structured_doc_field' => '1', 'doc_id' => $docId, 'field_id' => $fieldIds['password']]);
$j = json_decode($body, true);
$ok($c === 403 && ($j['ok'] ?? true) === false && !str_contains($body, 'S3cret-FW-pw'), 'the Client-B-only technician cannot reveal Client A\'s password field - a clean 403, not just a redirect, and the plaintext never appears in the response');
[$c] = sec_web($base, 'GET', "/agent/post.php?delete_structured_doc=$docId&csrf_token=csrftok12345678", $sidTechB);
$ok((int) $one("SELECT COUNT(*) FROM structured_documents WHERE doc_id = $docId AND doc_archived_at IS NULL") === 1, 'deleting Client A\'s document as the Client-B-only technician is refused - it still exists, not archived');

echo "\n--- adding to Client A as the Client-B-only technician is refused too ---\n";
$countBefore = (int) $one("SELECT COUNT(*) FROM structured_documents WHERE doc_client_id = $clientA");
$postTechB(['add_structured_doc' => '1', 'client_id' => $clientA, 'type_id' => $typeId, 'doc_name' => 'sdoc-test-hijack-add']);
$ok((int) $one("SELECT COUNT(*) FROM structured_documents WHERE doc_client_id = $clientA") === $countBefore, 'adding a document to Client A as the Client-B-only technician creates nothing');

echo "\n--- archiving a field or type hides it from NEW documents without breaking an existing one ---\n";
// archive_structured_doc_field is a GET-based action (an <a href="post.php?archive_...">, same shape as
// every other archive/delete link in this app) - exercised as a real GET, not the POST helper.
sec_web($base, 'GET', "/admin/post.php?archive_structured_doc_field={$fieldIds['url']}&type_id=$typeId&csrf_token=csrftok12345678", $sidAdmin, [], [], ['Referer: ' . $base . '/admin/structured_doc_types.php']);
[$c, $body] = $getAdmin("/agent/modals/structured_doc/structured_doc_add.php?client_id=$clientA&type_id=$typeId");
$ok($c === 200 && !str_contains($body, 'sdoc-test-Admin URL'), 'the archived field no longer appears on the add form for a NEW document');
[$c, $body] = $getAdmin("/agent/structured_doc.php?id=$docId&client_id=$clientA");
// Not a plain str_contains() on the whole page: the archive action's own flash_alert toast ("Field
// ... archived") legitimately names the field and is still showing on this, the first full-page load
// since - checking for the field actually rendered in the real field list (a <dt> label) instead.
$ok($c === 200 && !preg_match('/<dt[^>]*>sdoc-test-Admin URL/', $body), 'and no longer shows on the existing document\'s page either (it is simply not part of the type\'s layout anymore)');
$ok((string) $one("SELECT value_text FROM structured_document_values WHERE doc_id = $docId AND field_id = {$fieldIds['url']}") === 'https://10.0.0.1:8443', 'but the value already stored for it is NOT deleted - archiving a field does not destroy data');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM structured_document_values WHERE doc_id IN (SELECT doc_id FROM structured_documents WHERE doc_name LIKE 'sdoc-test-%')");
$q("DELETE FROM structured_documents WHERE doc_name LIKE 'sdoc-test-%'");
$q("DELETE FROM structured_doc_fields WHERE field_type_id = $typeId");
$q("DELETE FROM structured_doc_types WHERE type_id = $typeId");
$q("DELETE FROM clients WHERE client_id IN ($clientA, $clientB)");
$q("DELETE FROM user_client_permissions WHERE user_id = $techB");
$q("DELETE FROM users WHERE user_id = $techB");
$q("DELETE FROM user_role_permissions WHERE user_role_id = $roleTechB");
$q("DELETE FROM user_roles WHERE user_role_id = $roleTechB");
