<?php
/*
 * Wave 1 security, item 10: the credential vault list.
 *   - agent/credentials.php draws masked values and carries no plaintext secret (real page over HTTP, forged session)
 *   - agent/credential_reveal.php returns ONE field of ONE credential on request, and only to a user who may
 *   - every reveal and copy writes a Credential log line (and, with the Core audit module on, a credential.reveal / credential.copy
 *     audit event), never the value itself
 *   - per-user reveal limit (counted from the activity log): blocked, one "Reveal Limit" entry, administrators notified
 *   - password step-up after the configured idle minutes: required, wrong password, lock after repeated failures, accepted
 *   - locked vault, archived credential, another client, bad input, CSRF
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_x_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... php tests/security_vault_reveal.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_policy.php";
require_once "$root/includes/vault_reveal.php";
ob_start();   // generateUserSessionKey-style cookies and the audit writer must not trip over output having started

$auditWas = (int) $one("SELECT config_core_audit_enabled FROM settings WHERE company_id=1");
$q("DELETE FROM security_settings");
$q("DELETE FROM credentials WHERE credential_name LIKE 'sec-vault-%'");
$q("DELETE FROM audit_events WHERE event_type LIKE 'credential.%'");
$q("DELETE FROM logs WHERE log_type='Credential'");
$q("DELETE FROM notifications WHERE notification_type='Vault'");
$q("DELETE FROM user_client_permissions WHERE user_id IN (701,702,703)");
$q("DELETE FROM users WHERE user_id IN (701,702,703)");
$q("DELETE FROM user_role_permissions WHERE user_role_id IN (97,98)");
$q("DELETE FROM user_roles WHERE role_id IN (97,98)");
$q("INSERT INTO user_roles SET role_id=97, role_name='Sec Vault Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=98, role_name='Sec Vault Tech', role_is_admin=0, role_type=1");
$moduleIds = [];
foreach (['module_credential', 'module_client', 'module_support'] as $mn) {
    $mid = (int) $one("SELECT module_id FROM modules WHERE module_name='$mn'");
    if ($mid === 0) { $q("INSERT INTO modules SET module_name='$mn'"); $mid = (int) $db->insert_id; }
    $moduleIds[] = $mid;
}
foreach ($moduleIds as $mid) { $q("INSERT INTO user_role_permissions SET user_role_id=98, module_id=$mid, user_role_permission_level=2"); }   // a full agent role, not a module-only login
$pw = 'Correct-Horse-Battery-9';
$hash = secPasswordHash($pw);
foreach ([701 => [97, 'vadmin'], 702 => [98, 'vtech'], 703 => [98, 'vother']] as $id => [$role, $tag]) {
    $q("INSERT INTO users SET user_id=$id, user_name='Vault $tag', user_email='sec-vault-$tag@example.test', user_password='" . $esc($hash) . "', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
}
$q("DELETE FROM clients WHERE client_id IN (801,802)");
$q("INSERT INTO clients SET client_id=801, client_name='Vault Client A'");
$q("INSERT INTO clients SET client_id=802, client_name='Vault Client B'");
$q("INSERT INTO user_client_permissions SET user_id=703, client_id=802");   // vother may only reach Client B

// the vault key of the signed-in session, as generateUserSessionKey() lays it out
$master = randomString(); $sk = randomString(); $siv = randomString();
$sessionCipher = openssl_encrypt($master, 'aes-128-cbc', $sk, 0, $siv);
$mkcred = function (string $name, int $client, string $user, string $pass, ?string $archived = null) use ($q, $esc, $master, $one): int {
    $u = $esc(encryptCredentialEntryWithKey($user, $master));
    $p = $esc(encryptCredentialEntryWithKey($pass, $master));
    $a = $archived ? "'$archived'" : 'NULL';
    $q("INSERT INTO credentials SET credential_name='$name', credential_client_id=$client, credential_username='$u', credential_password='$p', credential_archived_at=$a");
    return (int) $one("SELECT credential_id FROM credentials WHERE credential_name='$name'");
};
$cA = $mkcred('sec-vault-a', 801, 'alice@example.test', 'S3cret-Pass-AAA!');
$cB = $mkcred('sec-vault-b', 802, 'bob@example.test', 'S3cret-Pass-BBB!');
$cArch = $mkcred('sec-vault-archived', 801, 'old@example.test', 'Old-Pass-ZZZ!', '2026-01-01 00:00:00');
$cEmpty = (function () use ($q, $one) { $q("INSERT INTO credentials SET credential_name='sec-vault-empty', credential_client_id=801"); return (int) $one("SELECT credential_id FROM credentials WHERE credential_name='sec-vault-empty'"); })();

// the session array vaultReveal() works on, with the vault key in it
$_COOKIE['user_encryption_session_key'] = $sk;
$sess = ['user_encryption_session_ciphertext' => $sessionCipher, 'user_encryption_session_iv' => $siv, 'vault_stepup_at' => time()];
$_SESSION = $sess;
$actor = fn(int $id, string $name, bool $admin, int $perm, bool $hasPw = true) => ['id' => $id, 'name' => $name, 'is_admin' => $admin, 'perm' => $perm, 'has_password' => $hasPw];
$ADMIN = $actor(701, 'Vault vadmin', true, 3);
$TECH  = $actor(702, 'Vault vtech', false, 2);
$OTHER = $actor(703, 'Vault vother', false, 2);
$req = fn(int $id, string $field = 'password', string $mode = 'reveal', ?string $stepup = null) => ['credential_id' => $id, 'field' => $field, 'mode' => $mode, 'stepup_password' => $stepup];
// the activity log is the record that is always on (the structured audit trail mirrors it only while the Core audit module is switched on)
$lg = fn(string $action) => $rows("SELECT * FROM logs WHERE log_type='Credential' AND log_action='$action' ORDER BY log_id");

// ------------------------------------------------------------------ the function
secSettingSet($db, 'vault_stepup_minutes', 15);
secSettingSet($db, 'vault_reveal_limit', 30);
$r = vaultReveal($db, $_SESSION, $ADMIN, $req($cA));
$ok($r['status'] === 200 && $r['body']['ok'] === true && $r['body']['value'] === 'S3cret-Pass-AAA!', 'an administrator reveals a password');
$r = vaultReveal($db, $_SESSION, $TECH, $req($cA, 'username'));
$ok($r['status'] === 200 && $r['body']['value'] === 'alice@example.test', 'a user with the module reveals a username');
$ev = $lg('Reveal');
$ok(count($ev) === 2 && (int) $ev[0]['log_user_id'] === 701 && (int) $ev[0]['log_entity_id'] === $cA && (int) $ev[0]['log_client_id'] === 801, 'each reveal writes a Credential log entry for that credential, client and user');
$ok(str_contains($ev[0]['log_description'], 'the password of credential sec-vault-a') && str_contains($ev[1]['log_description'], 'the username of'), 'naming the field and the credential');
$ok(!str_contains(json_encode($ev), 'S3cret-Pass-AAA') && !str_contains(json_encode($ev), 'alice@example.test'), 'the log never contains the value');
$r = vaultReveal($db, $_SESSION, $TECH, $req($cA, 'password', 'copy'));
$ok($r['status'] === 200 && count($lg('Copy')) === 1 && $lg('Copy')[0]['log_description'] !== '' && (int) $lg('Copy')[0]['log_user_id'] === 702, 'a copy is logged as a copy, by the user who made it');
$all = $rows("SELECT log_action FROM logs WHERE log_type='Credential' ORDER BY log_id");
$ok(count($all) === 3 && !str_contains(json_encode($all), 'S3cret'), 'three entries so far, none with a value');

$r = vaultReveal($db, $_SESSION, $TECH, $req($cEmpty, 'password'));
$ok($r['status'] === 200 && $r['body']['value'] === '', 'a credential with no password reveals as empty');
$r = vaultReveal($db, $_SESSION, $TECH, $req($cArch));
$ok($r['status'] === 404, 'an archived credential is not found');
$r = vaultReveal($db, $_SESSION, $TECH, $req(999999));
$ok($r['status'] === 404, 'a missing credential is not found');
$r = vaultReveal($db, $_SESSION, $TECH, $req($cA, 'note'));
$ok($r['status'] === 400, 'only username and password can be asked for');
$r = vaultReveal($db, $_SESSION, $TECH, $req($cA, 'password', 'peek'));
$ok($r['status'] === 400, 'and only reveal or copy');
$r = vaultReveal($db, $_SESSION, $actor(702, 'Vault vtech', false, 0), $req($cA));
$ok($r['status'] === 403, 'a role without the credential module is refused');
$r = vaultReveal($db, $_SESSION, $OTHER, $req($cA));
$ok($r['status'] === 403, 'a user limited to Client B cannot reach a Client A credential');
$r = vaultReveal($db, $_SESSION, $OTHER, $req($cB));
$ok($r['status'] === 200 && $r['body']['value'] === 'S3cret-Pass-BBB!', 'but can reach Client B\'s');
$before = count($rows("SELECT log_id FROM logs WHERE log_type='Credential' AND log_action IN ('Reveal','Copy')"));
vaultReveal($db, $_SESSION, $OTHER, $req($cA));
vaultReveal($db, $_SESSION, $TECH, $req($cArch));
$ok(count($rows("SELECT log_id FROM logs WHERE log_type='Credential' AND log_action IN ('Reveal','Copy')")) === $before, 'a refused or failed request writes no reveal event');

// locked vault: no vault key in this session (decryptCredentialEntry reads the real $_SESSION / cookie)
$saved = $_SESSION;
$_SESSION = ['vault_stepup_at' => time()];
$r = vaultReveal($db, $_SESSION, $TECH, $req($cA));
$ok($r['status'] === 409 && $r['body']['error'] === 'vault_locked' && !isset($r['body']['value']), 'a locked vault is reported as locked, never as an empty value');
$_SESSION = $saved;
$_SESSION['user_encryption_session_ciphertext'] = openssl_encrypt(randomString(), 'aes-128-cbc', $sk, 0, $siv);
$r = vaultReveal($db, $_SESSION, $TECH, $req($cA));
$ok($r['status'] === 409 && $r['body']['error'] === 'decrypt_failed', 'a vault key that does not open the credential is reported');
$_SESSION = $saved;

// ------------------------------------------------------------------ rate limit
$q("DELETE FROM logs WHERE log_type='Credential'");
$q("DELETE FROM notifications WHERE notification_type='Vault'");
secSettingSet($db, 'vault_reveal_limit', 3);
$s = $sess;
for ($i = 0; $i < 3; $i++) { $r = vaultReveal($db, $s, $TECH, $req($cA)); }
$ok($r['status'] === 200, 'up to the limit, reveals work');
$r = vaultReveal($db, $s, $TECH, $req($cA));
$ok($r['status'] === 429 && $r['body']['error'] === 'rate_limited' && !isset($r['body']['value']), 'past the limit the request is refused and returns no value');
$rl = $lg('Reveal Limit');
$ok(count($rl) === 1 && (int) $rl[0]['log_user_id'] === 702, 'a "Reveal Limit" entry is recorded for the user');
vaultReveal($db, $s, $TECH, $req($cA));
$ok(count($lg('Reveal Limit')) === 1, 'once per window, not once per blocked attempt');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type='Vault' AND notification_user_id=701") === 1, 'the administrators are notified');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type='Vault' AND notification_user_id=702") === 0, 'the blocked user is not');
$r = vaultReveal($db, $s, $ADMIN, $req($cA));
$ok($r['status'] === 200, 'another user is not affected by it');
$q("UPDATE logs SET log_created_at = NOW() - INTERVAL 11 MINUTE WHERE log_type='Credential'");
$r = vaultReveal($db, $s, $TECH, $req($cA));
$ok($r['status'] === 200, 'after the 10 minute window the user can reveal again');
secSettingSet($db, 'vault_reveal_limit', 30);

// ------------------------------------------------------------------ step-up
$q("DELETE FROM logs WHERE log_type='Credential'");
$stale = $sess; $stale['vault_stepup_at'] = time() - 16 * 60;
$r = vaultReveal($db, $stale, $TECH, $req($cA));
$ok($r['status'] === 401 && !empty($r['body']['stepup']) && !isset($r['body']['value']), 'after 16 minutes without a password entry the password is asked for');
$ok(count($lg('Reveal')) === 0, 'and nothing is revealed or logged as revealed meanwhile');
$r = vaultReveal($db, $stale, $TECH, $req($cA, 'password', 'reveal', 'not-my-password'));
$ok($r['status'] === 401 && $r['body']['error'] === 'stepup_invalid', 'a wrong password is refused');
$ok(count($lg('Step-up Failed')) === 1, 'and recorded');
$r = vaultReveal($db, $stale, $TECH, $req($cA, 'password', 'reveal', $pw));
$ok($r['status'] === 200 && $r['body']['value'] === 'S3cret-Pass-AAA!' && $stale['vault_stepup_at'] >= time() - 2, 'the right password reveals and restarts the 15 minute clock');
$r = vaultReveal($db, $stale, $TECH, $req($cA));
$ok($r['status'] === 200, 'the next reveal needs no password');
// lock after repeated failures
$stale2 = $sess; $stale2['vault_stepup_at'] = time() - 3600;
for ($i = 0; $i < 5; $i++) { $r = vaultReveal($db, $stale2, $TECH, $req($cA, 'password', 'reveal', 'nope-' . $i)); }
$r = vaultReveal($db, $stale2, $TECH, $req($cA, 'password', 'reveal', $pw));
$ok($r['status'] === 429 && $r['body']['error'] === 'stepup_locked', 'five wrong passwords lock the step-up, even for the right password');
$later = vaultReveal($db, $stale2, $TECH, $req($cA, 'password', 'reveal', $pw), time() + 301);
$ok($later['status'] === 200, 'the lock ends after five minutes');
// settings
secSettingSet($db, 'vault_stepup_minutes', 0);
$never = $sess; $never['vault_stepup_at'] = 1;
$ok(vaultReveal($db, $never, $TECH, $req($cA))['status'] === 200, 'with step-up set to 0 the password is never asked for');
secSettingSet($db, 'vault_stepup_minutes', 15);
$ok(vaultReveal($db, $never, $actor(702, 'Vault vtech', false, 2, false), $req($cA))['status'] === 200, 'an account without a local password (SSO) is exempt from step-up');
$ok(vaultStepUpDue(['vault_stepup_at' => 100], 15, true, 100 + 15 * 60) === false && vaultStepUpDue(['vault_stepup_at' => 100], 15, true, 100 + 15 * 60 + 1) === true, 'step-up is due strictly after N minutes');
$ok(vaultStepUpDue([], 15, true) === true, 'a session with no password entry on record is due (a passkey sign-in, for example)');

// ------------------------------------------------------------------ the real pages over HTTP
$sdir = sys_get_temp_dir() . '/sec_vault_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => 701, 'csrf_token' => $csrf, 'user_encryption_session_ciphertext' => $sessionCipher, 'user_encryption_session_iv' => $siv, 'vault_stepup_at' => time(), 'sec_created' => time(), 'sec_last' => time()]);
$ck = ['user_encryption_session_key' => $sk];
$q("DELETE FROM logs WHERE log_type='Credential'");

[$c, $body] = sec_web($base, 'GET', '/agent/credentials.php', $sid, [], $ck);
$ok($c === 200 && str_contains($body, 'sec-vault-a'), 'the vault list renders over HTTP');
foreach (['S3cret-Pass-AAA!', 'S3cret-Pass-BBB!', 'alice@example.test', 'bob@example.test', 'Old-Pass-ZZZ'] as $secret) {
    $ok(!str_contains($body, $secret), "the page HTML does not contain '$secret'");
}
$ok(substr_count($body, 'js-cred-reveal') >= 4 && str_contains($body, 'data-masked') && str_contains($body, 'js-cred-copy') && str_contains($body, 'credential_vault_list.js'), 'it carries the masks, the reveal and copy buttons and the script');
$ok(!str_contains($body, 'data-clipboard-text="S3') && !str_contains($body, 'data-bs-content="S3'), 'no clipboard or popover attribute carries a password');
$ok(str_contains($body, 'id="credStepUpModal"'), 'the step-up password dialog is on the page');
$ok(count($lg('Reveal')) === 0 && count($lg('Copy')) === 0, 'merely opening the list reveals (and logs) nothing');

[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal'], $ck);
$ok($c === 403 && (json_decode($body, true)['error'] ?? '') === 'csrf', 'a request without the CSRF token is refused');
[$c, $body] = sec_web($base, 'GET', '/agent/credential_reveal.php?credential_id=' . $cA . '&field=password', $sid, [], $ck);
$ok($c === 405, 'GET is refused (a value never travels in a URL)');
[$c, $body, $hd] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], $ck);
$j = json_decode($body, true);
$ok($c === 200 && ($j['value'] ?? '') === 'S3cret-Pass-AAA!', 'the reveal endpoint returns the password for a signed-in user');
$ok(stripos($hd, 'cache-control: no-store') !== false && stripos($hd, 'application/json') !== false, 'with no-store and a JSON content type');
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => $cA, 'field' => 'username', 'mode' => 'copy', 'csrf_token' => $csrf], $ck);
$ok($c === 200 && (json_decode($body, true)['value'] ?? '') === 'alice@example.test', 'a copy request returns the username');
$ok(count($lg('Reveal')) === 1 && count($lg('Copy')) === 1 && (int) $lg('Reveal')[0]['log_user_id'] === 701, 'both are in the Credential log with the right user and mode');
// with the Core audit module on, the same entries are mirrored into the structured audit trail
$q("DELETE FROM audit_events WHERE event_type LIKE 'credential.%'");
$q("UPDATE settings SET config_core_audit_enabled=1 WHERE company_id=1");
\RivetMSP\Core\CoreBridge::reset();
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], $ck);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='credential.reveal' AND actor_user_id=701 AND entity_id='$cA'") === 1, 'with the Core audit module on a reveal also lands in the audit trail as credential.reveal');
$q("UPDATE settings SET config_core_audit_enabled=$auditWas WHERE company_id=1");
\RivetMSP\Core\CoreBridge::reset();
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid, ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], []);
$ok(($c === 409) && (json_decode($body, true)['error'] ?? '') === 'vault_locked', 'without the vault-key cookie the endpoint says the vault is locked');
// signed out
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', 'nosuchsession', ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], $ck);
$ok($c !== 200 && !str_contains($body, 'S3cret'), 'a signed-out request never gets a value');
// step-up over HTTP
$sid2 = sec_forge_session($sdir, ['logged' => true, 'user_id' => 702, 'csrf_token' => $csrf, 'user_encryption_session_ciphertext' => $sessionCipher, 'user_encryption_session_iv' => $siv, 'vault_stepup_at' => time() - 3600, 'sec_created' => time(), 'sec_last' => time()]);
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid2, ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf], $ck);
$ok($c === 401 && !empty(json_decode($body, true)['stepup']), 'over HTTP, a stale session is asked for the password');
[$c, $body] = sec_web($base, 'POST', '/agent/credential_reveal.php', $sid2, ['credential_id' => $cA, 'field' => 'password', 'mode' => 'reveal', 'csrf_token' => $csrf, 'stepup_password' => $pw], $ck);
$ok($c === 200 && (json_decode($body, true)['value'] ?? '') === 'S3cret-Pass-AAA!', 'and gets the value after entering it');

// the shipped script: only talks to the endpoint, never stores a value
$js = file_get_contents("$root/agent/js/credential_vault_list.js");
$ok(!str_contains($js, 'localStorage') && !str_contains($js, 'sessionStorage') && str_contains($js, 'setTimeout(function () { mask(cell); }, SHOW_MS)'), 'the script keeps nothing in browser storage and masks a revealed value again after a timeout');
$list = file_get_contents("$root/agent/credentials.php");
$ok(!str_contains($list, 'decryptCredentialEntry') && !str_contains($list, 'credential_id_with_secret'), 'agent/credentials.php no longer decrypts anything');

// cleanup
$q("DELETE FROM credentials WHERE credential_name LIKE 'sec-vault-%'");
$q("DELETE FROM audit_events WHERE event_type LIKE 'credential.%'");
$q("DELETE FROM logs WHERE log_type='Credential'");
$q("DELETE FROM notifications WHERE notification_type='Vault'");
$q("DELETE FROM user_client_permissions WHERE user_id IN (701,702,703)");
$q("DELETE FROM users WHERE user_id IN (701,702,703)");
$q("DELETE FROM user_settings WHERE user_id IN (701,702,703)");
$q("DELETE FROM user_role_permissions WHERE user_role_id IN (97,98)");
$q("DELETE FROM user_roles WHERE role_id IN (97,98)");
$q("DELETE FROM clients WHERE client_id IN (801,802)");
$q("DELETE FROM security_settings");
