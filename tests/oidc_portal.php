<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Client Portal OpenID Connect sign-in (GitHub roadmap #14 - generalizes the existing Microsoft-only
 * client/login_microsoft.php to any standards-compliant provider), against the REAL code on a SCRATCH
 * database, real HTTP for the admin/login pages (same harness as tests/security_*.php), and the token/URL
 * validation functions called directly with a self-signed test key (no mock HTTPS identity provider needed
 * for that part - same technique RivetIT's own tests/oidc_portal.php uses).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/oidc_portal.php
 *
 * Covers: the DB step (db.sql parity, idempotent re-run, the users.user_oidc_* columns are utf8mb4_bin -
 * case-sensitive - from the start, not RivetIT's own general_ci-then-fix history); the issuer/endpoint
 * same-origin and HTTPS-only validation; a full valid ID token resolves its subject, and a battery of
 * tampered/malformed tokens (wrong audience, wrong nonce, wrong access-token binding, wrong issuer, expired,
 * missing exp/iat, string exp, future iat, azp mismatch, untrusted extra audience, tampered signature) are all
 * rejected; account linking by (issuer, subject) and by first-sign-in verified email, including the race-safe
 * single-UPDATE claim; two subjects differing only by case resolve to two different accounts, never collide
 * (the exact bug class RivetIT had to fix in a later migration); the admin settings page and the login-page
 * SSO button/error banner.
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/database_version.php";
require_once "$root/includes/oidc_portal.php";

use Firebase\JWT\JWT;

function oidc_rejects(callable $call, string $message): bool
{
    try {
        $call();
    } catch (Throwable $error) {
        return true;
    }
    return false;
}

echo "\n--- DB 2.6.113 step ---\n";
// Checked AFTER forcing a replay below, not before: this branch, msp-port-kb-v2 and msp-port-intune-per-client
// all branched from 2.6.112 and each claims "2.6.113" for its own step - whichever one's test ran most recently
// against this SHARED scratch database may have already left config_current_database_version at the literal
// string "2.6.113" without THIS branch's migration ever having run.
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.113', '>='), 'LATEST_DATABASE_VERSION is 2.6.113 or later');
$sqlText = (string) file_get_contents("$root/db.sql");
$ok(str_contains($sqlText, '`config_oidc_issuer` varchar(255) DEFAULT NULL') && str_contains($sqlText, 'UNIQUE KEY `uniq_user_oidc`'), 'db.sql carries the settings columns and the unique key');
$verBefore = (string) $one('SELECT config_current_database_version FROM settings LIMIT 1');
$q("UPDATE settings SET config_current_database_version = '2.6.112'");
[, $updOut] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut, '2.6.112') && (string) $one('SELECT config_current_database_version FROM settings LIMIT 1') === LATEST_DATABASE_VERSION, 'the updater advances past 2.6.112 to the latest version');
[, $updOut2] = sec_sh('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db');
$ok(str_contains($updOut2, 'already at the latest'), 'running it again is a no-op, not an error');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'config_oidc_issuer'") === 1, 'settings has config_oidc_issuer after the replay');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'user_oidc_subject'") === 1, 'users has user_oidc_subject after the replay');
$q("UPDATE settings SET config_current_database_version = '" . $esc($verBefore) . "'");

echo "\n--- user_oidc_* columns are case-sensitive (utf8mb4_bin) from the start ---\n";
$collOf = fn (string $col) => $one("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = '$col'");
$ok($collOf('user_oidc_issuer') === 'utf8mb4_bin', 'user_oidc_issuer is utf8mb4_bin, not the table default (prevents the case-collision bug RivetIT had to fix later)');
$ok($collOf('user_oidc_subject') === 'utf8mb4_bin', 'user_oidc_subject is utf8mb4_bin too');

echo "\n--- issuer/endpoint validation ---\n";
$issuer = 'https://idp.rivetmsp-oidc.test/application/o/rivetmsp';
$ok(portalOidcIssuerValid($issuer), 'HTTPS issuer accepted');
$ok(portalOidcIssuerValid($issuer . '/'), 'trailing slash accepted');
$ok(!portalOidcIssuerValid('http://idp.rivetmsp-oidc.test'), 'HTTP issuer rejected');
$ok(!portalOidcIssuerValid('https://user@idp.rivetmsp-oidc.test'), 'URL credentials rejected');
$ok(portalOidcEndpointValid($issuer . '/token', $issuer), 'same-origin endpoint accepted');
$ok(!portalOidcEndpointValid('https://evil.test/token', $issuer), 'cross-origin endpoint rejected');
$ok(portalOidcTokenAuthMethod([]) === 'client_secret_basic', 'default token auth is client_secret_basic');
$ok(portalOidcTokenAuthMethod(['token_endpoint_auth_methods_supported' => ['client_secret_post']]) === 'client_secret_post', 'client_secret_post supported when advertised');
$ok(oidc_rejects(fn () => portalOidcTokenAuthMethod(['token_endpoint_auth_methods_supported' => ['none']]), ''), 'a provider offering only public (none) auth is rejected - a confidential client secret is required');

echo "\n--- ID token validation, with a real self-signed key (no mock HTTPS provider needed) ---\n";
$private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
openssl_pkey_export($private, $privatePem);
$details = openssl_pkey_get_details($private);
$keys = ['keys' => [[
    'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
    'n' => portalOidcBase64Url($details['rsa']['n']),
    'e' => portalOidcBase64Url($details['rsa']['e']),
]]];
$access = 'temporary-access-token';
$claims = [
    'iss' => $issuer, 'sub' => 'stable-contact-42', 'aud' => 'rivetmsp-client',
    'iat' => time(), 'exp' => time() + 300, 'nonce' => 'browser-nonce',
    'at_hash' => portalOidcBase64Url(substr(hash('sha256', $access, true), 0, 16)),
];
$sign = static fn (array $body): string => JWT::encode($body, $privatePem, 'RS256', 'test-key');
$token = $sign($claims);
$ok(portalOidcValidateToken($token, $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access) === 'stable-contact-42', 'a valid signed ID token resolves the subject');
$ok(portalOidcValidateToken($sign([...$claims, 'azp' => 'rivetmsp-client']), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access) === 'stable-contact-42', 'a matching authorized party is accepted');
foreach ([
    'wrong audience' => fn () => portalOidcValidateToken($token, $keys, $issuer, 'other-client', 'browser-nonce', $access),
    'wrong nonce' => fn () => portalOidcValidateToken($token, $keys, $issuer, 'rivetmsp-client', 'other-nonce', $access),
    'wrong access-token binding' => fn () => portalOidcValidateToken($token, $keys, $issuer, 'rivetmsp-client', 'browser-nonce', 'other-access'),
    'wrong issuer' => fn () => portalOidcValidateToken($sign([...$claims, 'iss' => 'https://other.example.test']), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'expired token' => fn () => portalOidcValidateToken($sign([...$claims, 'exp' => time() - 300]), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'missing exp' => fn () => portalOidcValidateToken($sign(array_diff_key($claims, ['exp' => true])), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'missing iat' => fn () => portalOidcValidateToken($sign(array_diff_key($claims, ['iat' => true])), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'string exp' => fn () => portalOidcValidateToken($sign([...$claims, 'exp' => (string) (time() + 300)]), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'iat after exp' => fn () => portalOidcValidateToken($sign([...$claims, 'iat' => time() + 3600, 'exp' => time() + 600]), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'wrong authorized party' => fn () => portalOidcValidateToken($sign([...$claims, 'azp' => 'other-client']), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'untrusted extra audience' => fn () => portalOidcValidateToken($sign([...$claims, 'aud' => ['rivetmsp-client', 'other-client'], 'azp' => 'rivetmsp-client']), $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
    'tampered signature' => fn () => portalOidcValidateToken(substr($token, 0, -8) . 'abcdefgh', $keys, $issuer, 'rivetmsp-client', 'browser-nonce', $access),
] as $label => $attempt) {
    $ok(oidc_rejects($attempt, ''), "rejected: $label");
}

echo "\n--- fixtures for account linking ---\n";
$q("DELETE FROM contacts WHERE contact_email LIKE '%@oidc-test.example'");
$q("DELETE FROM users WHERE user_email LIKE '%@oidc-test.example'");
$q("DELETE FROM clients WHERE client_name LIKE 'oidc-test-%'");
$q("INSERT INTO clients SET client_name='oidc-test-Client'"); $clientA = (int) $db->insert_id;

// Already-linked login: resolves straight by (issuer, subject).
$q("INSERT INTO users SET user_name='Already Linked', user_email='linked@oidc-test.example', user_password='x', user_auth_method='oidc', user_type=2, user_status=1, user_oidc_issuer='$issuer', user_oidc_subject='stable-contact-42'");
$linkedUserId = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_name='Already Linked', contact_email='linked@oidc-test.example', contact_client_id=$clientA, contact_user_id=$linkedUserId");

$account = portalOidcEligibleAccount($db, $issuer, 'stable-contact-42');
$ok($account !== null && (int) $account['user_id'] === $linkedUserId, 'an already-linked (issuer, subject) resolves its account directly');
$ok(portalOidcEligibleAccount($db, $issuer, 'no-such-subject') === null, 'an unknown subject resolves nobody');

echo "\n--- first-sign-in linking by verified email ---\n";
$q("INSERT INTO users SET user_name='Blank Subject', user_email='blank@oidc-test.example', user_password='x', user_auth_method='oidc', user_type=2, user_status=1");
$blankUserId = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_name='Blank Subject', contact_email='blank@oidc-test.example', contact_client_id=$clientA, contact_user_id=$blankUserId");

$why = null;
$linked = portalOidcLinkByVerifiedEmail($db, $issuer, 'new-subject-99', ['email' => 'blank@oidc-test.example', 'email_verified' => true], $why);
$ok($linked !== null && (int) $linked['user_id'] === $blankUserId, 'first sign-in links the blank-subject login by verified email');
$ok((string) $one("SELECT user_oidc_subject FROM users WHERE user_id = $blankUserId") === 'new-subject-99', 'the subject is actually persisted');

// Once linked, the login no longer matches the "blank subject" filter at all - a second linking attempt on
// the same email finds no candidate, so it can never silently overwrite the subject already claimed above.
$why2 = null;
$relink = portalOidcLinkByVerifiedEmail($db, $issuer, 'a-different-subject', ['email' => 'blank@oidc-test.example', 'email_verified' => true], $why2);
$ok($relink === null && $why2 === 'no_blank_oidc_login_with_that_email', 'the same login cannot be re-linked to a second subject once claimed');
$ok((string) $one("SELECT user_oidc_subject FROM users WHERE user_id = $blankUserId") === 'new-subject-99', 'and its original linked subject is still exactly what was first claimed');

$why3 = null;
$unverified = portalOidcLinkByVerifiedEmail($db, $issuer, 'yet-another', ['email' => 'nonexistent@oidc-test.example', 'email_verified' => false], $why3, true);
$ok($unverified === null && $why3 === 'email_verified_is_boolean', 'an unverified email is refused when verification is required');

echo "\n--- case sensitivity: two subjects differing only by case never collide ---\n";
$q("INSERT INTO users SET user_name='Case A', user_email='casea@oidc-test.example', user_password='x', user_auth_method='oidc', user_type=2, user_status=1, user_oidc_issuer='$issuer', user_oidc_subject='CaseSub'");
$caseAId = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_name='Case A', contact_email='casea@oidc-test.example', contact_client_id=$clientA, contact_user_id=$caseAId");
$q("INSERT INTO users SET user_name='Case B', user_email='caseb@oidc-test.example', user_password='x', user_auth_method='oidc', user_type=2, user_status=1, user_oidc_issuer='$issuer', user_oidc_subject='caseSUB'");
$caseBId = (int) $db->insert_id;
$q("INSERT INTO contacts SET contact_name='Case B', contact_email='caseb@oidc-test.example', contact_client_id=$clientA, contact_user_id=$caseBId");

$rA = portalOidcEligibleAccount($db, $issuer, 'CaseSub');
$rB = portalOidcEligibleAccount($db, $issuer, 'caseSUB');
$ok($rA !== null && (int) $rA['user_id'] === $caseAId, "subject 'CaseSub' resolves its own account, not Case B's");
$ok($rB !== null && (int) $rB['user_id'] === $caseBId, "subject 'caseSUB' resolves its own account, not Case A's");
$ok(portalOidcEligibleAccount($db, $issuer, 'CASESUB') === null, 'a third spelling matches nobody');

echo "\n--- fixtures for HTTP checks ---\n";
$admin = (int) $one("SELECT user_id FROM users WHERE user_role_id = 1 LIMIT 1");
if (!$admin) { $q("INSERT INTO users SET user_name='oidc-test-admin', user_email='oidc-test-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1"); $admin = (int) $db->insert_id; }

$sdir = sys_get_temp_dir() . '/oidc_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin, 'csrf_token' => 'csrftok12345678']);

echo "\n--- admin settings page ---\n";
[$c, $body] = sec_web($base, 'GET', '/admin/identity_provider.php', $sidAdmin);
$ok($c === 200 && str_contains($body, 'OpenID Connect for Client Portal') && str_contains($body, 'client/login_oidc.php'), 'the generic OIDC section renders with the correct redirect URI hint');

$post = fn (array $f) => sec_web($base, 'POST', '/admin/post.php', $sidAdmin, $f + ['csrf_token' => 'csrftok12345678'], [], ['Referer: ' . $base . '/admin/identity_provider.php']);
[$c] = $post(['edit_oidc_provider' => '1', 'oidc_enabled' => '1', 'oidc_issuer' => $issuer, 'oidc_client_id' => 'rivetmsp-client', 'oidc_client_secret' => 'the-secret-value', 'oidc_link_by_email' => '1', 'oidc_require_verified_email' => '1']);
$ok($c === 200 || $c === 302, 'saving the OIDC settings succeeds');
$stored = $rows("SELECT config_oidc_enabled, config_oidc_issuer, config_oidc_client_id, config_oidc_client_secret FROM settings")[0];
$ok((int) $stored['config_oidc_enabled'] === 1 && $stored['config_oidc_issuer'] === $issuer && $stored['config_oidc_client_id'] === 'rivetmsp-client', 'the settings are stored exactly as entered');
$ok(!empty($stored['config_oidc_client_secret']) && !str_contains($stored['config_oidc_client_secret'], 'the-secret-value'), 'the client secret is encrypted at rest, never stored as plaintext');
$ok(decryptSetting($stored['config_oidc_client_secret']) === 'the-secret-value', 'it round-trips through decryptSetting()');

$cipherBefore = $stored['config_oidc_client_secret'];
$post(['edit_oidc_provider' => '1', 'oidc_enabled' => '1', 'oidc_issuer' => $issuer, 'oidc_client_id' => 'rivetmsp-client', 'oidc_client_secret' => '', 'oidc_link_by_email' => '1', 'oidc_require_verified_email' => '1']);
$ok($one("SELECT config_oidc_client_secret FROM settings") === $cipherBefore, 'a blank secret field on a later save keeps the stored ciphertext unchanged');

echo "\n--- login page shows the SSO button once configured, and the error banner on failure ---\n";
[$c, $body] = sec_web($base, 'GET', '/login.php', '');
$ok($c === 200 && str_contains($body, 'client/login_oidc.php') && str_contains($body, 'Single Sign-On'), 'the Single Sign-On button appears once OIDC is enabled and fully configured');
[$c, $body] = sec_web($base, 'GET', '/login.php?sso_error=1', '');
$ok($c === 200 && str_contains($body, 'Single sign-on could not complete'), 'a failed sign-on redirect shows a visible error, not a silent bounce');

$q("UPDATE settings SET config_oidc_enabled = 0");
[$c, $body] = sec_web($base, 'GET', '/login.php', '');
$ok($c === 200 && !str_contains($body, 'client/login_oidc.php'), 'the button disappears again once OIDC is disabled');

$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log (besides the pre-existing harness-wide missing-User-Agent one)');

echo "\n--- cleanup ---\n";
$q("DELETE FROM contacts WHERE contact_email LIKE '%@oidc-test.example'");
$q("DELETE FROM users WHERE user_email LIKE '%@oidc-test.example'");
$q("DELETE FROM clients WHERE client_name LIKE 'oidc-test-%'");
$q("UPDATE settings SET config_oidc_enabled = 0, config_oidc_issuer = NULL, config_oidc_client_id = NULL, config_oidc_client_secret = NULL, config_oidc_link_by_email = 0, config_oidc_require_verified_email = 1");
