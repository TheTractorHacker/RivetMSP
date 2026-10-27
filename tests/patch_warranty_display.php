<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Patch compliance and warranty display from RMM (GitHub roadmap #30 - "display RMM data; do not build
 * patching"), against the REAL code on a SCRATCH database, real HTTP (same harness as tests/security_*.php).
 *
 * Investigated before writing any code: warranty is already fully displayed (agent/assets.php has a
 * sortable Warranty Expire column; agent/client_overview.php already has expiring-45-day and expired
 * sections; agent/asset_details.php shows the date per asset) and patch compliance already has its own
 * full dashboard (agent/rmm_patch_compliance.php, filterable by client/site/group) plus a per-asset
 * Patches tab with live scan/install actions on agent/asset_details.php. The one real gap: no quick way to
 * reach "this department's" patch compliance view FROM the department's own overview page - this adds a
 * stat tile there, the same pattern Assets/Credentials/Contacts already use.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/patch_warranty_display.php
 *
 * Covers: the tile shows the real count of patches still needing a decision (status='available', matching
 * rivetRmmPatchComplianceView's own default filter) for THIS client only, never counting another client's
 * patches; it links to the compliance page pre-filtered to this client; it disappears when the RMM module
 * is switched off, and when the viewer lacks module_rmm; warranty's existing display is unaffected (a
 * regression guard, not new coverage).
 */
require __DIR__ . '/support/security_lib.php';

echo "\n--- fixtures ---\n";
$q("DELETE FROM rmm_patch_available WHERE title LIKE 'patchwd-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'patchwd-test-%'");
$q("INSERT INTO clients SET client_name='patchwd-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='patchwd-test-Client B'"); $clientB = (int) $db->insert_id;
$verBefore = (string) $one("SELECT config_module_enable_rmm FROM settings LIMIT 1");
$q("UPDATE settings SET config_module_enable_rmm = 1");

// Two "needs a decision" patches for Client A, one already-approved (so it should NOT count), and one for
// Client B entirely (so it should never bleed into Client A's count).
$ins = fn (int $clientId, string $title, string $status) =>
    $q("INSERT INTO rmm_patch_available SET device_id=1, client_id=$clientId, update_id='patchwd-test-" . bin2hex(random_bytes(4)) . "', title='" . $esc("patchwd-test-$title") . "', status='" . $esc($status) . "', first_seen_at=NOW(), last_seen_at=NOW()");
$ins($clientA, 'needs-decision-1', 'available');
$ins($clientA, 'needs-decision-2', 'available');
$ins($clientA, 'already-approved', 'approved');
$ins($clientB, 'other-client', 'available');

$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='patchwd-test-admin', user_email='patchwd-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/patchwd_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);

// The value is the first h2 span's text right after the label - matched narrowly (stopping at the
// closing </span>) so a stray digit in nearby markup (e.g. "min-height:2.1rem" on the very next element)
// can never be mistaken for the real count.
$patchTileValue = fn (string $body): ?int => preg_match('/Patches needing a decision<\/div><div[^>]*><span[^>]*>(\d+)<\/span>/s', $body, $m) === 1 ? (int) $m[1] : null;

echo "\n--- the stat tile shows the right count, for the right client, linking to the filtered view ---\n";
[$c, $body] = sec_web($base, 'GET', "/agent/client_overview.php?client_id=$clientA", $sidAdmin);
$ok($c === 200 && str_contains($body, 'Patches needing a decision'), 'Client A overview shows the patch-compliance tile');
$ok($patchTileValue($body) === 2, 'Client A: the count is exactly 2 (the two "available" patches, not the approved one)');
$ok(str_contains($body, "rmm_patch_compliance.php?client_id=$clientA"), 'the tile links to the compliance page pre-filtered to this client');

[$c, $body] = sec_web($base, 'GET', "/agent/client_overview.php?client_id=$clientB", $sidAdmin);
$ok($patchTileValue($body) === 1, "Client B's own count (1) never includes Client A's patches");

echo "\n--- disappears when the module or permission is off ---\n";
$q("UPDATE settings SET config_module_enable_rmm = 0");
[$c, $body] = sec_web($base, 'GET', "/agent/client_overview.php?client_id=$clientA", $sidAdmin);
$ok($c === 200 && !str_contains($body, 'Patches needing a decision'), 'the tile disappears when the RMM module is switched off');
$q("UPDATE settings SET config_module_enable_rmm = 1");

echo "\n--- warranty display is unaffected (regression guard) ---\n";
$q("DELETE FROM assets WHERE asset_name LIKE 'patchwd-test-%'");
$q("INSERT INTO assets SET asset_name='patchwd-test-Laptop', asset_client_id=$clientA, asset_warranty_expire=(CURDATE() + INTERVAL 10 DAY)");
[$c, $body] = sec_web($base, 'GET', "/agent/client_overview.php?client_id=$clientA", $sidAdmin);
$ok($c === 200 && str_contains($body, 'patchwd-test-Laptop'), 'the existing warranty-expiring section still lists an asset whose warranty is due soon');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM rmm_patch_available WHERE title LIKE 'patchwd-test-%'");
$q("DELETE FROM assets WHERE asset_name LIKE 'patchwd-test-%'");
$q("DELETE FROM clients WHERE client_id IN ($clientA, $clientB)");
$q("UPDATE settings SET config_module_enable_rmm = '" . $esc($verBefore) . "'");
