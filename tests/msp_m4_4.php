<?php
// MSP-DS-166 (re-review M4-4): when Stripe can no longer return a guest PaymentIntent (test-to-live key switch, deleted intent) the invoice was blocked for good
// (guest_ajax 500 on every visit, saved-card claims in_progress/needs_review forever, expiry closes a row only on 'canceled'). Now (1) a DEFINITE resource_missing
// (HTTP 404) is told apart from a transient error and closes the attempt (claim, expiry and guest_ajax), (2) an admin-only "Clear this stuck charge attempt" action
// (REAL agent/post/invoice.php handler) closes a stuck row with a required logged reason, never while Stripe says the payment is succeeded/processing.
// Run: SCRATCH_TABLES="users user_roles user_settings user_client_permissions logs clients invoices payments invoice_charge_attempts history payment_providers" bash tests/lib/with_scratch_db.sh php tests/msp_m4_4.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
require __DIR__ . '/lib/handler_run.php';
require_once __DIR__ . '/../plugins/stripe-php/init.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
$src = fn (string $f) => file_get_contents(__DIR__ . '/../' . $f);

// ---- the definite-missing test, with the REAL Stripe exception classes
$missing = \Stripe\Exception\InvalidRequestException::factory('No such payment_intent: pi_old', 404, null, null, null, 'resource_missing');
$ok('404 resource_missing is a definite "missing"', chargeAttemptIsMissing($missing));
$ok('404 "No such payment_intent" without a code is missing too', chargeAttemptIsMissing(\Stripe\Exception\InvalidRequestException::factory('No such payment_intent: pi_old', 404)));
$ok('a 500 is NOT missing', !chargeAttemptIsMissing(\Stripe\Exception\UnknownApiErrorException::factory("boom", 500)));
$ok('a connection error is NOT missing', !chargeAttemptIsMissing(\Stripe\Exception\ApiConnectionException::factory('timeout')));
$ok('a 401 is NOT missing', !chargeAttemptIsMissing(\Stripe\Exception\AuthenticationException::factory('bad key', 401)));
$ok('a 400 bad request is NOT missing', !chargeAttemptIsMissing(\Stripe\Exception\InvalidRequestException::factory('bad', 400, null, null, null, 'parameter_invalid_empty')));
$ok('a plain exception is NOT missing', !chargeAttemptIsMissing(new RuntimeException('No such payment_intent')));
$ok('the lookup says missing only for the definite answer', chargeAttemptPiOps(function () use ($missing) { throw $missing; })('pi_old') === 'missing'
    && chargeAttemptPiOps(function () { throw \Stripe\Exception\ApiConnectionException::factory('timeout'); })('pi_old') === null);

// ---- claim: a guest intent Stripe no longer has frees the invoice
for ($i = 1; $i <= 6; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount, invoice_client_id, invoice_prefix, invoice_number) VALUES ($i,'Sent',100.00,1,'INV-',$i)"); }
$guest = function (int $inv, string $pi, string $age) use ($m) {
    $g = chargeAttemptClaim($m, $inv, 'guest', 0); chargeAttemptStoreIntent($m, $g['attempt_id'], $pi);
    $m->query("UPDATE invoice_charge_attempts SET attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age WHERE attempt_id = " . $g['attempt_id']);
    return $g['attempt_id'];
};
$st = fn (int $id) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_id = $id");
$lk = fn (?string $a) => fn (string $pi, bool $cancel = false, ?string $key = null, bool $bank = false) => $a;
$g1 = $guest(1, 'pi_gone', '2 DAY');
$r = chargeAttemptClaim($m, 1, 'cron', 7, $lk('missing'));
$ok('saved-card claim over a guest intent Stripe no longer has: attempt closed, claim succeeds', $r['ok'] && $st($g1) === 'failed');
$g2 = $guest(2, 'pi_flaky', '2 DAY');
$r = chargeAttemptClaim($m, 2, 'cron', 7, $lk(null));
$ok('a transient lookup error still refuses and keeps the row', !$r['ok'] && $st($g2) === 'processing');
// a stale non-guest row with a stored id that Stripe no longer has
$m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = 3, attempt_key = 'oldkey-3', attempt_balance_cents = 10000, attempt_method_id = 3, attempt_source = 'portal', attempt_status = 'processing', attempt_pi_id = 'pi_gone2', attempt_created_at = NOW() - INTERVAL 30 MINUTE, attempt_updated_at = NOW() - INTERVAL 30 MINUTE");
$r = chargeAttemptClaim($m, 3, 'agent', 5, $lk('missing'));
$ok('stale row whose intent is missing at Stripe is abandoned, charge allowed', $r['ok'] && $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'oldkey-3'") === 'failed');

// ---- expiry closes missing intents too
$g4 = $guest(4, 'pi_gone3', '3 HOUR'); $g5 = $guest(5, 'pi_flaky2', '3 HOUR');
$n = chargeAttemptExpireGuest($m, fn (string $pi, bool $c = false, ?string $k = null, bool $b = false) => $pi === 'pi_gone3' ? 'missing' : null);
$ok('expiry closes an attempt whose intent Stripe no longer has, leaves an unreachable one', $n === 1 && $st($g4) === 'failed' && $st($g5) === 'processing');

// ---- guest_ajax: a missing intent no longer 500s forever
$ga = $src('guest/guest_ajax.php');
$ok('guest_ajax: the reuse catch closes a missing intent and goes on to claim a new one', preg_match('/catch \(Exception \$e\) \{\s*if \(!chargeAttemptIsMissing\(\$e\)\) \{[^\n]*\n\s*http_response_code\(500\);.*?chargeAttemptFinish\(\$mysqli, \$reuse_attempt\[\'attempt_id\'\], \'failed\'/s', $ga) === 1);

// ---- admin clear action: function rules
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 100, attempt_invoice_id = 6, attempt_key = 'k100', attempt_balance_cents = 10000, attempt_method_id = 0, attempt_source = 'guest', attempt_status = 'processing', attempt_pi_id = 'pi_100'");
$ok('a reason is required', !chargeAttemptClear($m, 100, '   ', $lk('missing'))['ok'] && $st(100) === 'processing');
foreach (['succeeded', 'processing', 'requires_capture'] as $s) {
    $ok("never cleared while Stripe says $s", !chargeAttemptClear($m, 100, 'looks stuck', $lk($s))['ok'] && $st(100) === 'processing');
}
$ok('not cleared when Stripe cannot be asked', !chargeAttemptClear($m, 100, 'looks stuck', $lk(null))['ok'] && $st(100) === 'processing');
$r = chargeAttemptClear($m, 100, 'test key switched', $lk('missing'), 'Alice');
$ok('cleared when the intent is missing at Stripe, reason stored', $r['ok'] && $st(100) === 'failed' && str_contains((string) $one("SELECT attempt_error FROM invoice_charge_attempts WHERE attempt_id = 100"), 'test key switched'));
$ok('an invoice history row records who and why', intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 6 AND history_status = 'Charge attempt cleared' AND history_description LIKE '%Alice%test key switched%'")) === 1);
$ok('an already closed attempt cannot be cleared again', !chargeAttemptClear($m, 100, 'again', $lk('missing'))['ok']);
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 101, attempt_invoice_id = 6, attempt_key = 'k101', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'portal', attempt_status = 'processing', attempt_pi_id = ''");
$ok('no-PI row: cleared only when the search finds nothing', !chargeAttemptClear($m, 101, 'r', fn ($p, $c = false, $k = null) => $k !== null ? 'found' : null)['ok']
    && !chargeAttemptClear($m, 101, 'r', fn ($p, $c = false, $k = null) => null)['ok']
    && chargeAttemptClear($m, 101, 'r', fn ($p, $c = false, $k = null) => $k !== null ? 'none' : null)['ok']);
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 102, attempt_invoice_id = 6, attempt_key = 'k102', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'cron', attempt_status = 'processing', attempt_pi_id = 'pi_102'");
$ok('with no Stripe provider configured (no lookup possible) an admin can still clear it', chargeAttemptClear($m, 102, 'no provider', null)['ok']);

// ---- the REAL handler: admin-only, CSRF, reason, history
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1),(2,'Tech',0)");
$hash = password_hash('x', PASSWORD_DEFAULT);
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Adm','a@x.test','$hash',1,1,1),(2,'Tech','t@x.test','$hash',2,1,1)");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (1,'C')");
$mk = fn (int $id) => $m->query("INSERT INTO invoice_charge_attempts SET attempt_id = $id, attempt_invoice_id = 6, attempt_key = 'k$id', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'cron', attempt_status = 'processing', attempt_pi_id = ''");
$mk(200);
[$o, $alert] = runPostHandler('../post/invoice.php', 2, ['clear_charge_attempt' => 1, 'attempt_id' => 200, 'reason' => 'tech tries']);
$ok("a non-admin cannot clear ($alert)", $st(200) === 'processing');
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['clear_charge_attempt' => 1, 'attempt_id' => 200, 'reason' => '']);
$ok("an admin without a reason is refused ($alert)", $st(200) === 'processing' && stripos($alert, 'reason') !== false);
[$o, $alert] = runPostHandler('../post/invoice.php', 1, ['clear_charge_attempt' => 1, 'attempt_id' => 200, 'reason' => 'gone after key switch']);
$ok("an admin with a reason clears it ($alert)", $st(200) === 'failed' && intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 6 AND history_description LIKE 'Charge attempt 200 cleared by Adm: gone after key switch'")) === 1
    && intval($one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%cleared stuck charge attempt 200%'")) === 1);
$ok('the invoice page offers the admin-only action', str_contains($src('agent/invoice.php'), 'Clear this stuck charge attempt') && str_contains($src('agent/invoice.php'), '!empty($session_is_admin)'));
$ok('the handler checks CSRF and the admin role', preg_match('/isset\(\$_POST\[\'clear_charge_attempt\'\]\).*?validateCSRFToken.*?enforceAdminPermission\(\)/s', $src('agent/post/invoice.php')) === 1);
exit($fail ? 1 : 0);
