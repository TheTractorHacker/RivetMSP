<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Security finding #6 (Aikido scan): the Azure AD / Entra OAuth client secret (Administration > Identity Providers) must never round-trip back
 * to the browser once saved - admin/identity_provider.php used to echo the decrypted secret straight into the input's value="" attribute on every
 * page load, so anyone who could see the rendered page (view-source, a browser extension, a shoulder-surf, a future XSS on that page) got the
 * live secret handed to them for free. Fixed: the field is always blank; a placeholder says whether one is already set; saving with the field
 * left blank keeps the existing stored secret (it does not clear it); saving with a new value replaces it. Real HTTP against the real code
 * (forged admin session, php -S), same scratch-DB rules as tests/endpoint_agent_lib.php: never a live database.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_x_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway redis> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/security_identity_provider.php
 */
require __DIR__ . '/endpoint_agent_lib.php';

ea_reset();
$tokens = ea_seed_users();
$sdir = sys_get_temp_dir() . '/sec_idp_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', [], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sidA = ea_forge_session($sdir, 1);
$csrf = 'csrftok1';

$getPage = fn () => web($wb, 'GET', '/admin/identity_provider.php', $sidA);
$save = fn (string $id, string $secret) => web($wb, 'POST', '/admin/post.php', $sidA, ['edit_identity_provider' => '1', 'csrf_token' => $csrf, 'azure_client_id' => $id, 'azure_client_secret' => $secret], ['Referer: ' . $wb . '/admin/identity_provider.php']);

echo "\n--- a secret already set: the page never echoes it back ---\n";
$FIRST_SECRET = 'S1-' . bin2hex(random_bytes(16));   // stands in for a real Azure client secret; synthetic test value, not a real credential
[$c] = $save('11111111-1111-1111-1111-111111111111', $FIRST_SECRET);
$ok($c === 302, 'first save redirects');
[$c, $body] = $getPage();
$ok($c === 200 && !str_contains($body, $FIRST_SECRET), 'the stored secret never appears anywhere in the rendered page');
$ok(str_contains($body, 'name="azure_client_secret"') && preg_match('/name="azure_client_secret"[^>]*value=""/', $body) === 1, 'the secret field is always rendered blank');
$ok(str_contains($body, 'A secret is already set') || str_contains($body, 'A secret is saved'), 'the page says a secret is already configured, without showing it');

echo "\n--- saving with the secret field left blank keeps the existing secret ---\n";
[$c] = $save('22222222-2222-2222-2222-222222222222', '');
$ok($c === 302, 'save with a blank secret redirects (not rejected)');
$stored = (string) $one("SELECT config_azure_client_secret FROM settings WHERE company_id = 1");
$ok($stored !== '' && decryptSetting($stored) === $FIRST_SECRET, 'the previously-saved secret is unchanged after a blank-secret save');
$ok((string) $one("SELECT config_azure_client_id FROM settings WHERE company_id = 1") === '22222222-2222-2222-2222-222222222222', 'the client id field, which is not a secret, is updated independently');

echo "\n--- saving with a new secret replaces the old one ---\n";
$SECOND_SECRET = 'S2-' . bin2hex(random_bytes(16));
[$c] = $save('22222222-2222-2222-2222-222222222222', $SECOND_SECRET);
$ok($c === 302, 'save with a new secret redirects');
$stored2 = (string) $one("SELECT config_azure_client_secret FROM settings WHERE company_id = 1");
$ok(decryptSetting($stored2) === $SECOND_SECRET && decryptSetting($stored2) !== $FIRST_SECRET, 'the new secret replaces the old one');

echo "\n--- hostile input in the client id field is stored and rendered safely, never raw ---\n";
$hostile = "x'\" onmouseover=alert(9) ";   // no angle-bracket tag: sanitizeInput() already strip_tags()'s those (a separate, pre-existing control, not this fix's concern)
[$c] = $save($hostile, '');
[$c, $body] = $getPage();
$storedId = (string) $one("SELECT config_azure_client_id FROM settings WHERE company_id = 1");
// sanitizeInput() SQL-escapes (mysqli_real_escape_string) before this code ever sees the value - a pre-existing, separate convention of this
// codebase (not this fix's concern) that leaves a literal backslash in front of the quote; what THIS fix proves is that the now-parameterized
// UPDATE stores whatever sanitizeInput() handed it byte for byte, rather than that string further breaking out of a concatenated SQL literal.
$ok($storedId === addslashes(trim($hostile)), 'the (already sanitizeInput()-escaped) value reaches storage unmangled by the now-parameterized UPDATE');
$ok($c === 200 && str_contains($body, 'value="' . nullable_htmlentities($storedId) . '"'), 'the stored value is HTML-escaped when rendered back into the client id field, so it cannot break out of the attribute');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
