<?php
// Review R24 (client credentials tab uses the resolved bearer token), R25 (PUT /me: changed name may not take another staff member's name/email),
// R26 (biometric key enrolment fails closed on an unreadable TOTP seed), R27 (PUT /me: a CHANGED email needs current_password; unchanged does not).
// Run: SCRATCH_TABLES="users logs api_biometric_keys api_tokens" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R24_R25_R26_R27_api.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
define('FROM_API', 1);
class Done extends Exception { public $code2; public $data; }
function api_error($c, $m) { $e = new Done($m); $e->code2 = $c; $e->data = ['error' => $m]; throw $e; }
function api_response($c, $d) { $e = new Done('ok'); $e->code2 = $c; $e->data = $d; throw $e; }
function api_rate_limit() { return true; }
function logAction($t, $a, $d, $c = 0, $e = 0) { global $mysqli; $uid = (int) ($GLOBALS['session_user_id'] ?? 0); $mysqli->query("INSERT INTO logs (log_type, log_action, log_description, log_user_id, log_created_at) VALUES ('" . $mysqli->real_escape_string($t) . "','" . $mysqli->real_escape_string($a) . "','" . $mysqli->real_escape_string($d) . "',$uid,NOW())"); }
class InputStub { public static $body = ''; private $pos = 0; public $context;
    function stream_open($p, $m, $o, &$op) { return true; } function stream_read($n) { $r = substr(self::$body, $this->pos, $n); $this->pos += strlen($r); return $r; }
    function stream_eof() { return $this->pos >= strlen(self::$body); } function stream_stat() { return []; } }
stream_wrapper_unregister('php'); stream_wrapper_register('php', 'InputStub');
if (($argv[1] ?? '') === 'child') {   // one API call per process
    [, , $uid, $bodyJson] = $argv;
    $api_user_id = (int) $uid; $method = 'PUT'; InputStub::$body = $bodyJson; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    try { require __DIR__ . '/../api/v1/me.php'; } catch (Done $d) { echo json_encode(['code' => $d->code2, 'data' => $d->data]); } exit(0);
}
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$call = function (int $uid, array $body): array { $o = shell_exec('php ' . escapeshellarg(__FILE__) . ' child ' . $uid . ' ' . escapeshellarg(json_encode($body)) . ' 2>/dev/null'); return json_decode((string) $o, true) ?: ['code' => 0, 'data' => (string) $o]; };
$one = fn (string $s) => ($r = $mysqli->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$PW = 'Right-Pass-12345'; $hash = password_hash($PW, PASSWORD_DEFAULT);
$mk = fn (int $id, string $n, string $e, string $tok = '') => $mysqli->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_type,user_status,user_token) VALUES ($id,'$n','$e','$hash',1,1,'$tok')");
$mk(1, 'alice', 'alice@x.test'); $mk(2, 'bob', 'bob@x.test'); $mk(3, 'alice', 'alice3@x.test');

// R25
$r = $call(1, ['name' => 'bob', 'email' => 'alice@x.test']);
$ok('R25: taking another staff member\'s display name is refused (409)', $r['code'] === 409 && $one("SELECT user_name FROM users WHERE user_id=1") === 'alice');
$r = $call(1, ['name' => 'bob@x.test', 'email' => 'alice@x.test']);
$ok('R25: using another staff member\'s EMAIL as the display name is refused', $r['code'] === 409);
$r = $call(1, ['name' => 'alice', 'email' => 'alice@x.test']);
$ok('R25: an UNCHANGED name that another agent shares still saves (200)', $r['code'] === 200);
$r = $call(1, ['name' => 'Alice Renamed', 'email' => 'alice@x.test']);
$ok('R25: a fresh name saves', $r['code'] === 200 && $one("SELECT user_name FROM users WHERE user_id=1") === 'Alice Renamed');

// R27 (MOBILE CONTRACT)
$r = $call(1, ['name' => 'Alice Renamed', 'email' => 'ALICE@x.test']);
$ok('R27: name+email save with the SAME email needs no password (200, contract kept)', $r['code'] === 200);
$r = $call(1, ['name' => 'Alice Renamed', 'email' => 'evil@x.test']);
$ok('R27: a CHANGED email without current_password is refused with a 4xx {"error"} and not applied', $r['code'] === 403 && !empty($r['data']['error']) && strcasecmp((string) $one("SELECT user_email FROM users WHERE user_id=1"), 'alice@x.test') === 0);
$r = $call(1, ['name' => 'Alice Renamed', 'email' => 'evil@x.test', 'current_password' => 'nope']);
$ok('R27: ... with a wrong password too', $r['code'] === 403 && strcasecmp((string) $one("SELECT user_email FROM users WHERE user_id=1"), 'alice@x.test') === 0);
$r = $call(1, ['name' => 'Alice Renamed', 'email' => 'new@x.test', 'current_password' => $PW]);
$ok('R27: ... applied with the right current_password', $r['code'] === 200 && $one("SELECT user_email FROM users WHERE user_id=1") === 'new@x.test');
$ok('R27: the wrong-password attempt was counted for the rate limit', (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Password Change Denied'") >= 1);

// R26
$k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']); $pem = openssl_pkey_get_details($k)['key'];
$mysqli->query("UPDATE users SET user_token = 'v9:unknown-envelope-seed' WHERE user_id = 2");
$r = $call(2, ['device_public_key_pem' => $pem, 'current_password' => $PW]);
$ok('R26: right password but an UNREADABLE TOTP seed refuses enrolment (403)', $r['code'] === 403 && (int) $one("SELECT COUNT(*) FROM api_biometric_keys WHERE user_id=2") === 0);
$mysqli->query("UPDATE users SET user_token = '' WHERE user_id = 2");
$r = $call(2, ['device_public_key_pem' => $pem, 'current_password' => $PW]);
$ok('R26: control - a user with no 2FA still enrols with the password', $r['code'] === 200 && (int) $one("SELECT COUNT(*) FROM api_biometric_keys WHERE user_id=2") === 1);

// R24
$t = file_get_contents(__DIR__ . '/../api/v1/client_tabs.php');
$seg = substr($t, strpos($t, "case 'credentials':"), 1200);
preg_match('/\$cred_raw_token = [^\n]*/', $seg, $mm);
$ok('R24: credentials tab uses the token index.php resolved, not HTTP_AUTHORIZATION only', !empty($mm[0]) && str_contains($mm[0], '$raw_token') && !str_contains($mm[0], 'HTTP_AUTHORIZATION') && !str_contains($seg, "preg_match('/^Bearer"));
exit($fail ? 1 : 0);
