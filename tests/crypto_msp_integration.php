<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * RivetCore\Crypto adoption in RivetMSP: everything around the cipher that the unit suites (crypto_settings, crypto_rewrap, crypto_vault,
 * crypto_panel) do not reach. Scratch database only, real PHP processes, real HTTP.
 *
 *   A. every place that looks at a ciphertext PREFIX accepts v3: (secIsWrapped, the SQL that finds cleartext rows, webhook URL masking on the real
 *      page, the restore drill's secret check, EndpointSecretBox, the setup restore note)
 *   B. an unknown or damaged envelope fails closed ('' and one log line), true legacy cleartext still reads
 *   C. the key file and its directory: what the web user can reach, said clearly (status, key store, CLI), and the 0755 / 0640 standard
 *   D. after a REAL rewrap (RewrapService over a key file whose active key is not k1), through the code that uses each secret:
 *        - TOTP login over HTTP (cleartext, ENC2 and v3 seeds),
 *        - SMTP password and mail OAuth access token used by cron/mail_queue.php against a fake SMTP server,
 *        - webhook secret and URL used by the cron queue delivery (HMAC verified at a loopback sink),
 *        - the settings loader (every config_* secret), mailbox, accounting, AI and payment provider credentials,
 *        - the vault canonical key (its own context) and a cleartext straggler wrapped in the right context.
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=rivetmsp_x_scratch RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... php tests/crypto_msp_integration.php
 */
$cdir = sys_get_temp_dir() . '/crypto-int-' . bin2hex(random_bytes(4));
mkdir($cdir, 0755, true);
$kf = "$cdir/keys.json";
putenv('SCRATCH_KEYFILE=' . $kf);      // config.php of the scratch install turns this into $config_keyfile; every child process inherits it
putenv('RIVETMSP_WEBHOOK_ALLOW_PRIVATE=1');
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";
require_once "$root/includes/security_policy.php";
require_once "$root/plugins/totp/totp.php";
require_once "$root/admin/includes/webhook_form_lib.php";

use RivetMSP\Crypto\ColumnRegistry;
use RivetMSP\Crypto\KeyAdmin;
use RivetMSP\Crypto\KeyStore;
use RivetMSP\Crypto\RewrapService;
use RivetMSP\Crypto\SettingsCrypto;

$procs = [];
register_shutdown_function(function () use (&$procs, $cdir) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    @chmod($cdir, 0755);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cdir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { @chmod($f->getPathname(), 0700); $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($cdir);
});
$LEGACY = (string) $GLOBALS['config_settings_enc_key'];
$use = function (?string $path) { $GLOBALS['config_keyfile'] = $path ?? ''; unset($GLOBALS['config_crypto_v3_settings'], $GLOBALS['config_crypto_v3_totp']); SettingsCrypto::reset(); };
$logFile = "$cdir/php-errors.log";
ini_set('error_log', $logFile);
$logged = fn () => is_file($logFile) ? (string) file_get_contents($logFile) : '';

// a clean slate
foreach (ColumnRegistry::all() as $cs) { if ($cs->table === 'settings') { $q("UPDATE settings SET `{$cs->column}` = NULL WHERE company_id = 1"); } }
$use($kf);   // the file does not exist yet: legacy source

// ====================================================================== A. prefix sites
$cbcVal = (function () use ($LEGACY) { $iv = random_bytes(16); return 'ENC:' . base64_encode($iv . openssl_encrypt('c', 'aes-128-cbc', substr(hash('sha256', $LEGACY, true), 0, 16), OPENSSL_RAW_DATA, $iv)); })();
KeyAdmin::generate($kf);
$use($kf);
$v3 = encryptSetting('prefix-secret');
$enc2 = (function () use ($use, $kf) { $use(null); $x = encryptSetting('prefix-secret'); $use($kf); return $x; })();
$ok(str_starts_with($v3, 'v3:') && str_starts_with($enc2, 'ENC2:') && str_starts_with($cbcVal, 'ENC:'), 'A: one value of each stored form');
$ok(secIsWrapped($v3) && secIsWrapped($enc2) && secIsWrapped($cbcVal) && !secIsWrapped('cleartext') && !secIsWrapped(null), 'A: secIsWrapped() knows v3:, ENC2: and ENC:');
$ok(secWrapIfPlain($v3) === $v3 && secWrapIfPlain('') === '' && secWrapIfPlain('plain') !== 'plain', 'A: secWrapIfPlain() never wraps a v3: value twice');
foreach (['v3' => $v3, 'enc2' => $enc2, 'enc' => $cbcVal] as $label => $val) {
    $ok(rivetWebhookValueSealed($val) === true, "A: webhook helper: a $label value is sealed");
}
$ok(rivetWebhookValueSealed('https://hook.example.test/x') === false && rivetWebhookValueSealed('') === false, 'A: webhook helper: a cleartext URL is not');
// the cleartext-row SQL (secRewrapColumn) must not re-wrap what is already v3
$q("UPDATE settings SET config_login_key_secret='" . $esc($v3) . "' WHERE company_id=1");
$r = secRewrapColumn($db, 'settings', 'company_id', 'config_login_key_secret');
$ok($r['wrapped'] === 0 && $one("SELECT config_login_key_secret FROM settings WHERE company_id=1") === $v3, 'A: secRewrapColumn() leaves a v3: value alone (the NOT LIKE list knows v3:)');
$q("UPDATE settings SET config_login_key_secret='cleartext-lk' WHERE company_id=1");
$r = secRewrapColumn($db, 'settings', 'company_id', 'config_login_key_secret');
$now = (string) $one("SELECT config_login_key_secret FROM settings WHERE company_id=1");
$ok($r['wrapped'] === 1 && str_starts_with($now, 'v3:') && decryptSetting($now) === 'cleartext-lk', 'A: and wraps a cleartext one (v3 with the key file)');
// a cleartext vault canonical key is wrapped in ITS context, a cleartext TOTP seed bound to its user
$q("UPDATE settings SET config_vault_canonical_key='abcdef0123456789abcdef0123456789' WHERE company_id=1");
secRewrapColumn($db, 'settings', 'company_id', 'config_vault_canonical_key');
$ok(getCanonicalVaultKey($db) === 'abcdef0123456789abcdef0123456789', 'A: a cleartext canonical vault key is wrapped under its own context (getCanonicalVaultKey() reads it)');
$q("DELETE FROM users WHERE user_email LIKE 'cint-%'");
$q("INSERT INTO users SET user_name='cint-seed', user_email='cint-seed@example.test', user_password='x', user_role_id=1, user_token='CLEARSEED234567A'");
$uSeed = (int) $one("SELECT user_id FROM users WHERE user_email='cint-seed@example.test'");
secRewrapColumn($db, 'users', 'user_id', 'user_token');
$ok(secUserTotpSecret((string) $one("SELECT user_token FROM users WHERE user_id=$uSeed"), $uSeed) === 'CLEARSEED234567A', 'A: a cleartext TOTP seed wrapped by the straggler pass is read by the TOTP reader');
$q("DELETE FROM users WHERE user_id=$uSeed");
$q("UPDATE settings SET config_vault_canonical_key=NULL, config_login_key_secret=NULL WHERE company_id=1");

// the webhook list page masks a v3 URL
$q("DELETE FROM user_roles WHERE role_id=97"); $q("INSERT INTO user_roles SET role_id=97, role_name='cint admin', role_is_admin=1, role_type=1");
$q("DELETE FROM users WHERE user_id=731"); $q("INSERT INTO users SET user_id=731, user_name='cint admin', user_email='cint-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=97");
$q("INSERT INTO user_settings SET user_id=731 ON DUPLICATE KEY UPDATE user_id=user_id");
$q("DELETE FROM webhooks WHERE webhook_id IN (7301, 7302)");
$hookUrl = 'https://hooks.example.test/TOP-SECRET-PATH-ab12cd34';
$q("INSERT INTO webhooks SET webhook_id=7301, webhook_name='cint v3 hook', webhook_url='" . $esc(encryptSetting($hookUrl)) . "', webhook_secret='" . $esc(encryptSetting('hook-secret-v3')) . "', webhook_events='ticket.created', webhook_enabled=1");
$q("INSERT INTO webhooks SET webhook_id=7302, webhook_name='cint cleartext hook', webhook_url='https://clear.example.test/CLEAR-PATH-zz99', webhook_secret='', webhook_events='ticket.created', webhook_enabled=1");
$sdir = $cdir . '/sess'; mkdir($sdir, 0700);
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sid = sec_forge_session($sdir, ['logged' => true, 'user_id' => 731, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
[$c, $body] = sec_web($base, 'GET', '/admin/settings_webhooks.php', $sid);
$ok($c === 200 && str_contains($body, 'hooks.example.test') && !str_contains($body, 'TOP-SECRET-PATH-ab12cd34'), 'A: the webhook list masks a v3: URL (host shown, secret path not)');
$ok(str_contains($body, 'CLEAR-PATH-zz99'), 'A: and still shows a cleartext legacy URL as it is');
[$c, $body] = sec_web($base, 'GET', '/admin/webhook_form.php?id=7301', $sid);
$ok($c === 200 && !str_contains($body, 'TOP-SECRET-PATH-ab12cd34') && str_contains($body, 'leave blank to keep'), 'A: the edit form hides a v3: URL too (saved: host..., leave blank to keep)');
$q("DELETE FROM webhooks WHERE webhook_id IN (7301, 7302)");

// EndpointSecretBox
$box = new \RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox();
$ok($box->decrypt($v3) === 'prefix-secret' && $box->decrypt($enc2) === 'prefix-secret' && $box->decrypt('cleartext-key') === '', 'A: EndpointSecretBox opens v3: and ENC2:, and refuses cleartext');

// the restore drill's secret check and key ring check (v3 value in the settings row)
$q("UPDATE settings SET config_smtp_password='" . $esc($v3) . "' WHERE company_id=1");
$src = (string) file_get_contents("$root/src/Recovery/RestoreDrill.php");
$ok(substr_count($src, "str_starts_with(\$val, 'v3:')") >= 1 && str_contains($src, 'keyRingCheck'), 'A: the restore drill looks for v3: values and checks the key ring');
$q("UPDATE settings SET config_smtp_password=NULL WHERE company_id=1");
// setup restore: the manifest's key ring against the key file here
require_once "$root/setup/setup_functions.php";
$GLOBALS['setup_manifest_keyring'] = ['k1' => KeyStore::load()->ring->fingerprint('k1')];
$ok(manifestKeyringNote() === '', 'A: setup restore: a backup whose key ring matches the key file here gets no warning');
$GLOBALS['setup_manifest_keyring'] = ['k1' => KeyStore::load()->ring->fingerprint('k1'), 'k9' => str_repeat('a', 16)];
$ok(str_contains(manifestKeyringNote(), 'k9') && str_contains(manifestKeyringNote(), $kf) && str_contains(manifestKeyringNote(), '0755'), 'A: and one that names a missing key says which and where the file goes');
unset($GLOBALS['setup_manifest_keyring']);
$ok(manifestKeyringNote() === '', 'A: a backup without a key ring needs no note');
// the deploy scripts accept the same
$bk = (string) file_get_contents("$root/deploy/backup.sh"); $rs = (string) file_get_contents("$root/deploy/restore.sh");
$ok(str_contains($bk, '"keyring"') && str_contains($rs, '--key-file=') && str_contains($rs, 'install -d -m 0755'), 'A: backup.sh writes the key ring, restore.sh installs a key file into a 0755 directory');

// ====================================================================== B. unknown or damaged envelopes fail closed
$use($kf);
$before = $logged();
foreach (['v4:k1:AAAA', 'v3:k1:not-base64!!', 'v3:nokid', 'ENC2:AAAA', 'ENC:%%%', 'ENC3:something', 'vw3:abc', 'V2:xyz', 'enc:abcdef'] as $bad) {
    $ok(decryptSetting($bad) === '', "B: '$bad' reads as empty, never as the text itself");
}
$ok(decryptSetting('plain password with: a colon') === 'plain password with: a colon' && decryptSetting('https://example.test/path') === 'https://example.test/path' && decryptSetting('0') === '0', 'B: true legacy cleartext (colons, URLs, "0") still reads as it is');
$ok(secUserTotpSecret('v4:whatever', 5) === '' && secUserTotpSecret('PLAINSEED234567AA', 5) === 'PLAINSEED234567AA', 'B: a TOTP seed in an unknown envelope reads as empty (no login), cleartext seeds still read');
$after = $logged();
$ok(substr_count($after, 'Settings crypto:') >= 1 && !str_contains($after, 'something') && !str_contains($after, 'AAAA') && !str_contains($after, 'xyz'), 'B: logged once per reason, without any value');
// the same through an RMM-style consumer: a value that is not a ciphertext is not a key
$ok($box->decrypt('v4:k1:AAAA') === '' && $box->decrypt('ENC3:foo') === '', 'B: EndpointSecretBox: unknown prefixes are "not available"');

// ====================================================================== C. the key file and what the web user can reach
$ok((fileperms($kf) & 0777) === 0640, 'C: generate() writes the key file 0640');
$t = $cdir . '/web'; mkdir($t, 0755); $tkf = "$t/keys.json"; KeyAdmin::generate($tkf);
$myUid = posix_geteuid(); $myGid = posix_getegid();
$ok(KeyAdmin::webAccessProblems($tkf, $myUid + 7, $myGid + 7) !== [] && str_contains(implode(' ', KeyAdmin::webAccessProblems($tkf, $myUid + 7, $myGid + 7)), 'cannot read'), 'C: other users cannot read a 0640 file they are not in the group of (reported, with the fix)');
$ok(KeyAdmin::webAccessProblems($tkf, $myUid + 7, $myGid) === [], 'C: but a member of the file group can (0640, group www-data style)');
chmod($t, 0750);
$ok(count(KeyAdmin::webAccessProblems($tkf, $myUid + 7, $myGid + 7)) >= 1 && str_contains(implode(' ', KeyAdmin::webAccessProblems($tkf, $myUid + 7, $myGid + 7)), 'chmod 0755'), 'C: a 0750 directory that excludes the web group is reported with chmod 0755 as the fix');
chmod($t, 0755);
chmod($tkf, 0600);
$ok(str_contains(implode(' ', KeyAdmin::webAccessProblems($tkf, $myUid + 7, $myGid)), 'cannot read'), 'C: a 0600 file is not readable by the group');
chmod($tkf, 0640);
// a directory this process cannot traverse: the file cannot be seen, and the status must not just say "legacy"
$hidden = $cdir . '/hidden'; mkdir($hidden, 0755); $hkf = "$hidden/keys.json"; KeyAdmin::generate($hkf, true);   // a fresh random key: values sealed under it cannot open through config.php
$use($hkf);
$sealedFresh = encryptSetting('sealed-with-the-file');
$ok(str_starts_with($sealedFresh, 'v3:') && decryptSetting($sealedFresh) === 'sealed-with-the-file', 'C: (setup) a value sealed under the key file');
chmod($hidden, 0600);
SettingsCrypto::reset();
$st = KeyStore::load();
$status = KeyAdmin::status();
$ok($st->source === 'legacy' && $st->unreachable !== null && str_contains($st->unreachable, 'cannot traverse') && $status['access_problems'] !== [], 'C: a file behind a directory the web user cannot traverse is flagged as unreachable, not shown as a plain legacy install');
$ok(decryptSetting($sealedFresh) === '' && decryptSetting($enc2) === 'prefix-secret', 'C: meanwhile v3 values read as empty (fail closed) and ENC2 values still open through config.php');
$ok(str_starts_with(encryptSetting('written-while-blind'), 'ENC2:'), 'C: and new writes stay ENC2 (nothing is sealed under a key set the next request will not see)');
[$cc, $oo] = sec_sh('SCRATCH_KEYFILE=' . escapeshellarg($hkf) . ' ' . PHP_BINARY . ' ' . escapeshellarg("$root/scripts/keys_cli.php") . ' status');
$ok($cc === 0 && str_contains($oo, 'NOT REACHABLE') && str_contains($oo, 'ACCESS'), 'C: keys_cli status says clearly that the key file is not reachable');
chmod($hidden, 0755);
$use($kf);
[$cc, $oo] = sec_sh('SCRATCH_KEYFILE=' . escapeshellarg($kf) . ' ' . PHP_BINARY . ' ' . escapeshellarg("$root/scripts/keys_cli.php") . ' status');
$ok($cc === 0 && !str_contains($oo, 'NOT REACHABLE') && str_contains($oo, 'k1'), 'C: a reachable key file reports normally');
// generate() creates a missing directory 0755 only as root (this run is not root): as another user it refuses clearly
if (posix_geteuid() !== 0) {
    $threw = false; try { KeyAdmin::generate("$cdir/nodir/keys.json"); } catch (\Throwable $e) { $threw = true; }
    $ok($threw, 'C: as a non-root user generate() does not invent a missing directory');
}
$r = KeyAdmin::createForInstall($LEGACY);
$ok($r['status'] === 'exists', 'C: createForInstall() on an existing file changes nothing');
$use(null);
$r2 = (function () use ($cdir, $LEGACY, $use) { $GLOBALS['config_keyfile'] = "$cdir/newdir/keys.json"; SettingsCrypto::reset(); $r = KeyAdmin::createForInstall($LEGACY); return $r; })();
$ok($r2['status'] === 'created' && (fileperms("$cdir/newdir") & 0777) === 0755 && (fileperms("$cdir/newdir/keys.json") & 0777) === 0640, 'C: the installer creates the directory 0755 and the file 0640');
$use($kf);

// ====================================================================== D. a REAL rewrap, then the code that uses each secret
// seed in the legacy forms (no key file in effect), as an existing install has them
$use(null);
$PW = 'Admin-Horse-Battery-77'; $master = randomString();
$mk = function (string $tag, ?string $seedStored) use ($q, $esc, $one, $PW, $master): int {
    $cipher = setupFirstUserSpecificKey($PW, $master);
    $q("DELETE FROM users WHERE user_email='cint-$tag@example.test'");
    $q("INSERT INTO users SET user_name='cint $tag', user_email='cint-$tag@example.test', user_password='" . $esc(secPasswordHash($PW)) . "', user_specific_encryption_ciphertext='" . $esc($cipher) . "', user_type=1, user_status=1, user_role_id=97");
    $id = (int) $one("SELECT user_id FROM users WHERE user_email='cint-$tag@example.test'");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
    if ($seedStored !== null) { $q("UPDATE users SET user_token='" . $esc($seedStored) . "' WHERE user_id=$id"); }
    return $id;
};
$seeds = ['clear' => 'JBSWY3DPEHPK3PXP', 'enc2' => 'KRSXG5CTMVRXEZLU', 'rw' => 'MFRGGZDFMZTWQ2LK'];
$uClear = $mk('clear', $seeds['clear']);
$uEnc2 = $mk('enc2', encryptSetting($seeds['enc2']));
$uRw = $mk('rewrapped', encryptSetting($seeds['rw']));
$ok(!str_starts_with((string) $one("SELECT user_token FROM users WHERE user_id=$uClear"), 'ENC') && str_starts_with((string) $one("SELECT user_token FROM users WHERE user_id=$uEnc2"), 'ENC2:'), 'D: seeds in the legacy forms (cleartext, ENC2:)');
// settings secrets in the legacy form
$smtpPw = 'smtp-Pa55-word'; $oauthAccess = 'ya29.access-token-ABC'; $oauthRefresh = 'refresh-token-XYZ'; $oauthSecret = 'oauth-client-secret-Q';
$q("UPDATE settings SET config_enable_cron=1, config_smtp_provider='standard_smtp', config_smtp_host='127.0.0.1', config_smtp_port=0, config_smtp_encryption='', config_smtp_username='smtp-user',
    config_smtp_password='" . $esc(encryptSetting($smtpPw)) . "', config_mail_oauth_client_secret='" . $esc(encryptSetting($oauthSecret)) . "', config_mail_oauth_refresh_token='" . $esc(encryptSetting($oauthRefresh)) . "',
    config_mail_oauth_access_token='" . $esc(encryptSetting($oauthAccess)) . "', config_mail_oauth_access_token_expires_at=DATE_ADD(NOW(), INTERVAL 1 DAY), config_mail_from_email='noreply@example.test', config_mail_from_name='Crypto Test',
    config_imap_password='" . $esc(encryptSetting('imap-pw-1')) . "', config_azure_client_secret='" . $esc(encryptSetting('azure-sec-1')) . "', config_login_key_secret='" . $esc(encryptSetting('LOGINKEY1')) . "',
    config_backup_passphrase='" . $esc(encryptSetting('backup-passphrase-1234')) . "', config_backup_s3_secret_key='" . $esc(encryptSetting('s3-secret-1')) . "', config_comet_admin_pass='" . $esc(encryptSetting('comet-pass-1')) . "',
    config_comet_totp_secret='" . $esc(encryptSetting('comet-totp-1')) . "', config_comet_webhook_secret='" . $esc(encryptSetting('comet-hook-1')) . "', config_outlook_cal_client_secret='" . $esc(encryptSetting('cal-secret-1')) . "'
    WHERE company_id=1");
$q("UPDATE settings SET config_vault_canonical_key='" . $esc(encryptSetting('canon0123456789abcdef0123456789ab', SettingsCrypto::VAULT_CANONICAL_KEY)) . "' WHERE company_id=1");
// a webhook with a secret and a URL pointing at a loopback sink
$sinkPort = (function () { $s = stream_socket_server('tcp://127.0.0.1:0'); $p = (int) explode(':', stream_socket_get_name($s, false))[1]; fclose($s); return $p; })();
$sinkOut = "$cdir/sink.jsonl";
file_put_contents("$cdir/sink.php", '<?php $b = file_get_contents("php://input"); file_put_contents(' . var_export($sinkOut, true) . ', json_encode(["sig" => $_SERVER["HTTP_X_RIVETMSP_SIGNATURE"] ?? "", "event" => $_SERVER["HTTP_X_RIVETMSP_EVENT"] ?? "", "body" => $b]) . "\n", FILE_APPEND); http_response_code(200); echo "ok";');
$procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$sinkPort", "$cdir/sink.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $sinkPort); $i++) { usleep(100000); }
$hookSecret = 'whsec-' . bin2hex(random_bytes(6));
$q("DELETE FROM webhook_queue"); $q("DELETE FROM webhooks WHERE webhook_id=7310");
$q("INSERT INTO webhooks SET webhook_id=7310, webhook_name='cint delivery', webhook_url='" . $esc(encryptSetting("http://127.0.0.1:$sinkPort/hook")) . "', webhook_secret='" . $esc(encryptSetting($hookSecret)) . "', webhook_events='ticket.created', webhook_enabled=1");
// payment / AI / accounting / mailbox / unifi rows
foreach (['payment_providers', 'ai_providers', 'accounting_integrations', 'mailboxes', 'unifi_integrations'] as $t) { $q("DELETE FROM `$t` WHERE 1"); }
$q("INSERT INTO payment_providers SET payment_provider_id=7330, payment_provider_name='Stripe', payment_provider_private_key='" . $esc(encryptSetting('sk_test_PRIVATE')) . "', payment_provider_webhook_secret='" . $esc(encryptSetting('whsec_PROV')) . "'");
$q("INSERT INTO ai_providers SET ai_provider_id=7331, ai_provider_name='AI', ai_provider_api_key='" . $esc(encryptSetting('ai-api-KEY')) . "'");
$q("INSERT INTO accounting_integrations SET accounting_id=7332, accounting_client_secret='" . $esc(encryptSetting('qbo-client-SECRET')) . "', accounting_access_token='" . $esc(encryptSetting('qbo-access')) . "', accounting_refresh_token='" . $esc(encryptSetting('qbo-refresh')) . "'");
$q("INSERT INTO mailboxes SET mailbox_id=7333, mailbox_imap_password_enc='" . $esc(encryptSetting('mailbox-imap-pw')) . "', mailbox_oauth_refresh_token_enc='" . $esc(encryptSetting('mailbox-refresh')) . "', mailbox_oauth_access_token_enc='" . $esc(encryptSetting('mailbox-access')) . "'");
$q("INSERT INTO unifi_integrations SET id=7334, api_key_enc='" . $esc(encryptSetting('unifi-api-KEY')) . "'");
$q("INSERT INTO software SET software_id=7335, software_name='cint sw', software_key='" . $esc(encryptSetting('LICENSE-KEY-7335')) . "'");

// -- the real rewrap: a new key file (the legacy key becomes k1), a second key added and made active, so every value has to move
@unlink($kf); foreach (glob("$kf.bak-*") ?: [] as $b) { @unlink($b); }
KeyAdmin::generate($kf);
$use($kf);
KeyAdmin::addKey(true);
$use($kf);
$active = (string) KeyStore::load()->ring->activeKid();
$svc = new RewrapService($db, 1, "$cdir/state");
$bad = 0; $moved = 0;
foreach ([1, 2] as $pass) { foreach ($svc->run() as $rep) { $bad += $rep->failed + $rep->conflicts; $moved += $rep->rewrapped; } }
$ok($bad === 0 && $moved >= 25, "D: the rewrap ran for real: $moved value(s) moved to key $active, no failures");
$sample = fn (string $sql) => str_starts_with((string) $one($sql), 'v3:' . $active . ':');
$ok($sample("SELECT config_smtp_password FROM settings WHERE company_id=1") && $sample("SELECT config_mail_oauth_access_token FROM settings WHERE company_id=1") && $sample("SELECT webhook_secret FROM webhooks WHERE webhook_id=7310") && $sample("SELECT user_token FROM users WHERE user_id=$uEnc2") && $sample("SELECT user_token FROM users WHERE user_id=$uClear"), 'D: SMTP password, OAuth token, webhook secret and both TOTP seeds are now v3: under the active key');

// -- TOTP login over HTTP (the server process reads the key file too)
class CBrowser {
    public string $jar; public array $last = [0, '', '']; public string $base;
    public function __construct(string $base) { $this->base = $base; $this->jar = tempnam(sys_get_temp_dir(), 'cintjar_'); register_shutdown_function(fn () => @unlink($this->jar)); }
    public function go(string $method, string $path, array $post = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return $this->last = [$code, substr((string) $raw, $hs), substr((string) $raw, 0, $hs)];
    }
    public function location(): string { return preg_match('/^location:\s*(\S+)/mi', $this->last[2], $m) ? $m[1] : ''; }
}
$q("UPDATE settings SET config_client_portal_enable=0, config_login_key_required=0 WHERE company_id=1");
$totpLogin = function (string $tag, string $seed) use ($base): array {
    @unlink(sys_get_temp_dir() . '/rivetmsp_totp_replay/' . hash('sha256', $seed));
    $b = new CBrowser($base);
    [$c, $body] = $b->go('POST', '/login.php', ['email' => "cint-$tag@example.test", 'password' => 'Admin-Horse-Battery-77', 'login' => '1']);
    $tok = preg_match('/name="pending_mfa_token"\s+value="([0-9a-f]+)"/', $body, $m) ? $m[1] : '';
    if ($tok === '') { return [false, "no MFA step ($c)"]; }
    [$c, $body] = $b->go('POST', '/login.php', ['mfa_login' => '1', 'pending_mfa_token' => $tok, 'current_code' => TokenAuth6238::getTokenCode($seed), 'recovery_code' => '']);
    return [$c === 302 && str_contains($b->location(), 'agent/'), "HTTP $c " . $b->location()];
};
foreach (['clear' => $uClear, 'enc2' => $uEnc2, 'rewrapped' => $uRw] as $tag => $uid) {
    $seed = $tag === 'clear' ? $seeds['clear'] : ($tag === 'enc2' ? $seeds['enc2'] : $seeds['rw']);
    [$good, $why] = $totpLogin($tag, $seed);
    $ok($good, "D: TOTP login over HTTP after the rewrap works for the '$tag' seed ($why)");
}
[$good] = $totpLogin('enc2', 'AAAAAAAAAAAAAAAA');
$ok($good === false, 'D: and a wrong code still fails');
$tokenNow = (string) $one("SELECT user_token FROM users WHERE user_id=$uEnc2");
$ok(str_starts_with($tokenNow, 'v3:' . $active . ':') && secUserTotpSecret($tokenNow, $uEnc2) === $seeds['enc2'] && secUserTotpSecret($tokenNow, $uClear) === '', 'D: the seed is bound to its user (another user id cannot open it)');

// -- SMTP password and OAuth access token by cron/mail_queue.php against a fake SMTP server
file_put_contents("$cdir/smtp.php", <<<'PHPSMTP'
<?php
// A tiny SMTP server that records the AUTH exchange. argv: port, output file.
[$_, $port, $out] = $argv;
$srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es); if (!$srv) { exit(1); }
while ($c = @stream_socket_accept($srv, 120)) {
    stream_set_timeout($c, 10);
    fwrite($c, "220 fake ESMTP\r\n");
    $data = false; $auth = [];
    while (($line = fgets($c)) !== false) {
        $l = rtrim($line, "\r\n");
        if ($data) { if ($l === '.') { $data = false; fwrite($c, "250 queued\r\n"); } continue; }
        $u = strtoupper($l);
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) { fwrite($c, "250-fake\r\n250 AUTH LOGIN PLAIN XOAUTH2\r\n"); }
        elseif (str_starts_with($u, 'AUTH LOGIN')) { fwrite($c, "334 VXNlcm5hbWU6\r\n"); $auth['user'] = base64_decode(rtrim((string) fgets($c))); fwrite($c, "334 UGFzc3dvcmQ6\r\n"); $auth['pass'] = base64_decode(rtrim((string) fgets($c))); fwrite($c, "235 ok\r\n"); file_put_contents($out, json_encode(['mech' => 'LOGIN'] + $auth) . "\n", FILE_APPEND); }
        elseif (str_starts_with($u, 'AUTH PLAIN')) { $p = explode(' ', $l, 3); $raw = base64_decode($p[2] ?? ''); $parts = explode("\0", $raw); fwrite($c, "235 ok\r\n"); file_put_contents($out, json_encode(['mech' => 'PLAIN', 'user' => $parts[1] ?? '', 'pass' => $parts[2] ?? '']) . "\n", FILE_APPEND); }
        elseif (str_starts_with($u, 'AUTH XOAUTH2')) { $p = explode(' ', $l, 3); file_put_contents($out, json_encode(['mech' => 'XOAUTH2', 'raw' => base64_decode($p[2] ?? '')]) . "\n", FILE_APPEND); fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'MAIL') || str_starts_with($u, 'RCPT') || str_starts_with($u, 'RSET') || str_starts_with($u, 'NOOP')) { fwrite($c, "250 ok\r\n"); }
        elseif (str_starts_with($u, 'DATA')) { $data = true; fwrite($c, "354 go\r\n"); }
        elseif (str_starts_with($u, 'QUIT')) { fwrite($c, "221 bye\r\n"); break; }
        else { fwrite($c, "250 ok\r\n"); }
    }
    fclose($c);
}
PHPSMTP);
$smtpPort = (function () { $s = stream_socket_server('tcp://127.0.0.1:0'); $p = (int) explode(':', stream_socket_get_name($s, false))[1]; fclose($s); return $p; })();
$smtpOut = "$cdir/smtp.jsonl";
$procs[] = proc_open([PHP_BINARY, "$cdir/smtp.php", (string) $smtpPort, $smtpOut], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pp2);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $smtpPort); $i++) { usleep(100000); }
$q("UPDATE settings SET config_smtp_port=$smtpPort WHERE company_id=1");
$queueMail = function (string $subject) use ($q, $esc): int {
    $q("INSERT INTO email_queue SET email_recipient='dest@example.test', email_recipient_name='Dest', email_from='noreply@example.test', email_from_name='Crypto Test', email_subject='" . $esc($subject) . "', email_content='<p>hello</p>', email_queued_at=NOW() - INTERVAL 1 MINUTE");
    return (int) $GLOBALS['db']->insert_id;
};
$runQueue = function () use ($root): array {
    @unlink(sys_get_temp_dir() . '/itflow_mail_queue_cryptomsp-scratch-installation-id-0.lock');
    exec('cd ' . escapeshellarg("$root/cron") . ' && ' . escapeshellarg(PHP_BINARY) . ' mail_queue.php --no-mx-validation 2>&1', $o, $code);
    return [$code, implode("\n", $o)];
};
$m1 = $queueMail('cint standard smtp');
[$mc, $mo] = $runQueue();
$sent1 = (int) $one("SELECT email_status FROM email_queue WHERE email_id=$m1");
$log = is_file($smtpOut) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($smtpOut)))) : [];
$ok($sent1 === 3 && $log !== [] && ($log[0]['user'] ?? '') === 'smtp-user' && ($log[0]['pass'] ?? '') === $smtpPw, "D: cron/mail_queue.php (a separate process) signed in to SMTP with the decrypted password after the rewrap (status $sent1" . ($sent1 !== 3 ? ", exit $mc: " . substr($mo, 0, 200) : '') . ')');
$q("UPDATE settings SET config_smtp_provider='google_oauth', config_smtp_username='oauth-user@example.test', config_smtp_encryption='none' WHERE company_id=1");
@unlink($smtpOut);
$m2 = $queueMail('cint oauth smtp');
[$mc2, $mo2] = $runQueue();
$sent2 = (int) $one("SELECT email_status FROM email_queue WHERE email_id=$m2");
$log2 = is_file($smtpOut) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($smtpOut)))) : [];
if ($sent2 !== 3) { fwrite(STDERR, 'oauth run: ' . substr($mo2, 0, 300) . ' | ' . json_encode($rows("SELECT app_log_details FROM app_logs WHERE app_log_category LIKE 'Cron-Mail%' ORDER BY app_log_id DESC LIMIT 2")) . "\n"); }
$ok($sent2 === 3 && ($log2[0]['mech'] ?? '') === 'XOAUTH2' && str_contains((string) ($log2[0]['raw'] ?? ''), 'Bearer ' . $oauthAccess), "D: and the mail OAuth access token (XOAUTH2) is the decrypted one (status $sent2" . ($sent2 !== 3 ? ': ' . substr($mo2, 0, 200) : '') . ')');
$q("UPDATE settings SET config_smtp_provider='standard_smtp' WHERE company_id=1");

// -- the settings loader: every config_* secret as the app sees it after the rewrap
$loader = (string) file_get_contents("$root/includes/load_global_settings.php");
$code = '$mysqli = $GLOBALS["mysqli"]; $session_user_id = 0; $_SERVER["REQUEST_URI"] = "/x"; ob_start(); include ' . var_export("$root/includes/load_global_settings.php", true) . '; ob_end_clean(); $out = []; foreach (["config_smtp_password","config_imap_password","config_azure_client_secret","config_outlook_cal_client_secret","config_mail_oauth_client_secret","config_mail_oauth_refresh_token","config_mail_oauth_access_token","config_login_key_secret","config_backup_s3_secret_key","config_backup_passphrase","config_comet_admin_pass","config_comet_totp_secret","config_comet_webhook_secret"] as $k) { $out[] = $$k ?? null; } echo json_encode($out);';
$ldr = $cdir . '/loader.php';
file_put_contents($ldr, "<?php\nchdir(" . var_export($root, true) . ");\n\$_SERVER['DOCUMENT_ROOT'] = " . var_export($root, true) . ";\nrequire " . var_export("$root/config.php", true) . ";\nrequire " . var_export("$root/functions.php", true) . ";\nrequire " . var_export("$root/vendor/autoload.php", true) . ";\n" . $code . "\n");
[$lc, $lo] = sec_sh(PHP_BINARY . ' ' . escapeshellarg($ldr));
$vals = json_decode(trim((string) preg_replace('/^.*?(\[".*)$/s', '$1', $lo)), true);
$expectVals = [$smtpPw, 'imap-pw-1', 'azure-sec-1', 'cal-secret-1', $oauthSecret, $oauthRefresh, $oauthAccess, 'LOGINKEY1', 's3-secret-1', 'backup-passphrase-1234', 'comet-pass-1', 'comet-totp-1', 'comet-hook-1'];
if (!is_array($vals)) { fwrite(STDERR, "loader output: " . substr($lo, 0, 600) . "\n"); } elseif ($vals !== $expectVals) { fwrite(STDERR, 'loader mismatch: ' . json_encode(array_keys(array_diff_assoc($vals, $expectVals))) . "\n"); }
$ok(is_array($vals) && $vals === $expectVals, 'D: includes/load_global_settings.php (a fresh process) reads all 13 config_* secrets as their plaintext after the rewrap' . (is_array($vals) ? '' : ': ' . substr($lo, 0, 300)));

// -- webhook queue delivery: the block from cron/cron.php run against the loopback sink
$cron = (string) file_get_contents("$root/cron/cron.php");
$s = strpos($cron, '$sql_wq = mysqli_query'); $e = strpos($cron, '// Prune old delivered/failed webhook entries');
$block = str_replace('dirname(__DIR__)', var_export($root, true), substr($cron, (int) $s, (int) $e - (int) $s));   // the block runs from a temp file here, not from cron/
$ok($s !== false && $e > $s, 'D: the legacy webhook delivery block is found in cron/cron.php');
$payload = json_encode(['event' => 'ticket.created', 'ticket_id' => 42]);
$q("INSERT INTO webhook_queue SET queue_webhook_id=7310, queue_event='ticket.created', queue_payload='" . $esc($payload) . "', queue_status='pending', queue_attempts=0, queue_next_attempt_at=NOW() - INTERVAL 1 MINUTE");
$wqRun = $cdir . '/wq.php';
file_put_contents($wqRun, "<?php\nchdir(" . var_export("$root/cron", true) . ");\nrequire " . var_export("$root/config.php", true) . ";\nrequire " . var_export("$root/functions.php", true) . ";\nrequire " . var_export("$root/vendor/autoload.php", true) . ";\n" . $block . "\necho 'done';\n");
[$wc, $wo] = sec_sh(PHP_BINARY . ' ' . escapeshellarg($wqRun));
$got = is_file($sinkOut) ? json_decode(trim((string) strtok((string) file_get_contents($sinkOut), "\n")), true) : null;
$ok($got !== null && ($got['sig'] ?? '') === 'sha256=' . hash_hmac('sha256', $payload, $hookSecret) && ($got['body'] ?? '') === $payload, 'D: the queued webhook was delivered to the sink with an HMAC made from the DECRYPTED secret (url and secret both v3: in the database)' . ($got === null ? ': ' . substr($wo, 0, 200) : ''));
$ok((string) $one("SELECT queue_status FROM webhook_queue WHERE queue_webhook_id=7310") === 'delivered', 'D: and the queue row is marked delivered');

// -- subscriptions through the Core adapter
$subs = \RivetMSP\Core\Adapter\Webhooks\WebhooksTableSubscriptions::subscriptionFromRow($rows("SELECT * FROM webhooks WHERE webhook_id=7310")[0]);
$ok($subs->url === "http://127.0.0.1:$sinkPort/hook" && $subs->secret === $hookSecret, 'D: the Core webhook subscription adapter reads the URL and secret');

// -- provider credentials
require_once "$root/includes/class_stripe_payment_provider.php";
$ok(decryptSetting((string) $one("SELECT payment_provider_private_key FROM payment_providers WHERE payment_provider_id=7330")) === 'sk_test_PRIVATE' && decryptSetting((string) $one("SELECT payment_provider_webhook_secret FROM payment_providers WHERE payment_provider_id=7330")) === 'whsec_PROV', 'D: payment provider private key and webhook secret');
$ok(decryptSetting((string) $one("SELECT ai_provider_api_key FROM ai_providers WHERE ai_provider_id=7331")) === 'ai-api-KEY', 'D: AI provider key');
$qbo = $rows("SELECT * FROM accounting_integrations WHERE accounting_id=7332")[0];
$ok(decryptSetting($qbo['accounting_client_secret']) === 'qbo-client-SECRET' && decryptSetting($qbo['accounting_access_token']) === 'qbo-access' && decryptSetting($qbo['accounting_refresh_token']) === 'qbo-refresh', 'D: accounting (QuickBooks) client secret and tokens');
$mb = $rows("SELECT * FROM mailboxes WHERE mailbox_id=7333")[0];
$ok(decryptSetting($mb['mailbox_imap_password_enc']) === 'mailbox-imap-pw' && decryptSetting($mb['mailbox_oauth_refresh_token_enc']) === 'mailbox-refresh' && decryptSetting($mb['mailbox_oauth_access_token_enc']) === 'mailbox-access', 'D: mailbox IMAP password and OAuth tokens');
$ok(decryptSetting((string) $one("SELECT api_key_enc FROM unifi_integrations WHERE id=7334")) === 'unifi-api-KEY', 'D: UniFi API key');
$ok(decryptSetting((string) $one("SELECT software_key FROM software WHERE software_id=7335")) === 'LICENSE-KEY-7335', 'D: a software license key');
$ok(getCanonicalVaultKey($db) === 'canon0123456789abcdef0123456789ab' && $sample("SELECT config_vault_canonical_key FROM settings WHERE company_id=1"), 'D: the vault canonical key kept its own context through the rewrap (getCanonicalVaultKey() opens it, v3: under the active key)');

// cleanup
foreach (['payment_providers', 'ai_providers', 'accounting_integrations', 'mailboxes', 'unifi_integrations'] as $t) { $q("DELETE FROM `$t` WHERE 1"); }
$q("DELETE FROM software WHERE software_id=7335"); $q("DELETE FROM webhooks WHERE webhook_id=7310"); $q("DELETE FROM webhook_queue");
$q("DELETE FROM email_queue WHERE email_subject LIKE 'cint %'");
$q("DELETE FROM users WHERE user_email LIKE 'cint-%' OR user_id=731"); $q("DELETE FROM user_settings WHERE user_id=731"); $q("DELETE FROM user_roles WHERE role_id=97");
$q("UPDATE settings SET config_smtp_provider='', config_smtp_host='', config_smtp_port=0, config_smtp_username='', config_enable_cron=0, config_mail_oauth_access_token_expires_at=NULL WHERE company_id=1");
foreach (ColumnRegistry::all() as $cs) { if ($cs->table === 'settings') { $q("UPDATE settings SET `{$cs->column}` = NULL WHERE company_id = 1"); } }
$use(null);
