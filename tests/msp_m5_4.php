<?php
// MSP-DS-166/32 (re-review M5-4): a Stripe search hit counted as "may have been charged" whatever the found PaymentIntent's status, and Clear refused every hit, so the
// invoice was blocked for good (a guest create that timed out but reached Stripe leaves PI_A at requires_payment_method; or Stripe Search unavailable, e.g. India accounts).
// Now a hit is acted on by its status like a stored id (id stored on the row, canceled when cancelable, freed on canceled, refused on succeeded/processing), and when
// Search is unavailable an admin can Clear with a reason plus an explicit logged "I checked Stripe" confirmation.
// REAL chargeAttemptClaim()/chargeAttemptClear()/chargeAttemptPiOps() and the REAL agent/post/invoice.php handler on a scratch DB.
// Run: SCRATCH_TABLES="users user_roles user_settings user_client_permissions logs clients invoices payments invoice_charge_attempts history payment_providers" bash tests/lib/with_scratch_db.sh php tests/msp_m5_4.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
require __DIR__ . '/../includes/charge_attempts.php';
require __DIR__ . '/lib/handler_run.php';
require_once __DIR__ . '/../plugins/stripe-php/init.php';
require __DIR__ . '/lib/m5_stripe_fakes.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $m->query("SET SESSION sql_mode=''");
$one = fn (string $s) => ($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : null;
for ($i = 1; $i <= 8; $i++) { $m->query("INSERT INTO invoices (invoice_id, invoice_status, invoice_amount, invoice_client_id, invoice_prefix, invoice_number) VALUES ($i,'Sent',100.00,1,'INV-',$i)"); }
$old = function (int $inv, string $age = '30 MINUTE', string $src = 'portal') use ($m) {
    $m->query("INSERT INTO invoice_charge_attempts SET attempt_invoice_id = $inv, attempt_key = 'key-$inv', attempt_balance_cents = 10000, attempt_method_id = 3, attempt_source = '$src', attempt_status = 'processing', attempt_pi_id = '', attempt_created_at = NOW() - INTERVAL $age, attempt_updated_at = NOW() - INTERVAL $age");
};
$st = fn (int $inv) => $one("SELECT attempt_status FROM invoice_charge_attempts WHERE attempt_key = 'key-$inv'");
$pi = fn (int $inv) => $one("SELECT attempt_pi_id FROM invoice_charge_attempts WHERE attempt_key = 'key-$inv'");

// ---- claim: the search hit's status decides
$old(1); $s = new M5Stripe; $s->add('pi_A', 'requires_payment_method', 'key-1');
$r = chargeAttemptClaim($m, 1, 'agent', 5, $s->lookup());
$ok('a hit at requires_payment_method (guest create that timed out) is canceled, its id stored, the row freed and the claim goes ahead', $r['ok'] && $s->pis['pi_A']->status === 'canceled' && $st(1) === 'failed' && $pi(1) === 'pi_A');
$old(2); $s = new M5Stripe; $s->add('pi_B', 'succeeded', 'key-2');
$r = chargeAttemptClaim($m, 2, 'agent', 5, $s->lookup());
$ok('a hit that succeeded: refused (needs_review), its id stored so the webhook can settle the row, row kept', !$r['ok'] && $r['reason'] === 'needs_review' && $st(2) === 'processing' && $pi(2) === 'pi_B');
$old(3); $s = new M5Stripe; $s->add('pi_C', 'processing', 'key-3');
$r = chargeAttemptClaim($m, 3, 'agent', 5, $s->lookup());
$ok('a hit that is processing: refused, row kept, id stored', !$r['ok'] && $st(3) === 'processing' && $pi(3) === 'pi_C' && $s->pis['pi_C']->cancels === 0);
$old(4); $s = new M5Stripe;
$r = chargeAttemptClaim($m, 4, 'agent', 5, $s->lookup());
$ok('no hit: abandoned as before', $r['ok'] && $st(4) === 'failed' && $s->searches === ['key-4']);
$old(5); $s = new M5Stripe; $s->searchDown = true;
$r = chargeAttemptClaim($m, 5, 'agent', 5, $s->lookup());
$ok('search unavailable: refused, row kept', !$r['ok'] && $st(5) === 'processing');

// ---- Clear: a hit is canceled/refused, an unavailable search needs the explicit confirmation
$old(6, '2 HOUR'); $s = new M5Stripe; $s->add('pi_D', 'requires_payment_method', 'key-6');
$r = chargeAttemptClear($m, intval($one("SELECT attempt_id FROM invoice_charge_attempts WHERE attempt_key = 'key-6'")), 'timed out', $s->lookup(), 'Alice');
$ok('Clear on a no-id attempt whose search finds a cancelable intent: canceled, id stored, cleared', $r['ok'] && $s->pis['pi_D']->status === 'canceled' && $st(6) === 'failed' && $pi(6) === 'pi_D');
$old(7, '2 HOUR'); $s = new M5Stripe; $s->add('pi_E', 'succeeded', 'key-7');
$id7 = intval($one("SELECT attempt_id FROM invoice_charge_attempts WHERE attempt_key = 'key-7'"));
$r = chargeAttemptClear($m, $id7, 'timed out', $s->lookup(), 'Alice');
$ok('... a hit that succeeded is never cleared (its id is stored for the webhook)', !$r['ok'] && $st(7) === 'processing' && $pi(7) === 'pi_E');
$old(8, '2 HOUR'); $s = new M5Stripe; $s->searchDown = true;
$id8 = intval($one("SELECT attempt_id FROM invoice_charge_attempts WHERE attempt_key = 'key-8'"));
$r = chargeAttemptClear($m, $id8, 'India account, no search', $s->lookup(), 'Alice');
$ok('search unavailable: refused without the confirmation (message says to check Stripe)', !$r['ok'] && stripos($r['message'], 'I checked Stripe') !== false && $st(8) === 'processing');
$r = chargeAttemptClear($m, $id8, 'India account, no search', $s->lookup(), 'Alice', true);
$ok('search unavailable + "I checked Stripe": cleared, the confirmation is logged on the attempt and in the invoice history', $r['ok'] && $st(8) === 'failed'
    && str_contains((string) $one("SELECT attempt_error FROM invoice_charge_attempts WHERE attempt_id = $id8"), 'checked')
    && intval($one("SELECT COUNT(*) FROM history WHERE history_invoice_id = 8 AND history_status = 'Charge attempt cleared' AND history_description LIKE '%India account%checked%'")) === 1);
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 901, attempt_invoice_id = 1, attempt_key = 'key-9', attempt_balance_cents = 10000, attempt_method_id = 3, attempt_source = 'portal', attempt_status = 'processing', attempt_pi_id = 'pi_flaky'");
$idx = 901;
$ok('a stored-id attempt that Stripe cannot be asked about is NOT cleared by the confirmation', !chargeAttemptClear($m, $idx, 'x', fn ($p, $c = false, $k = null, $b = false) => null, 'Alice', true)['ok']);

// ---- the REAL handler passes the checkbox through
$m->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1)");
$hash = password_hash('x', PASSWORD_DEFAULT);
$m->query("INSERT INTO users (user_id,user_name,user_email,user_password,user_role_id,user_type,user_status) VALUES (1,'Adm','a@x.test','$hash',1,1,1)");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (1,'C')");
$m->query("INSERT INTO invoice_charge_attempts SET attempt_id = 900, attempt_invoice_id = 3, attempt_key = 'k900', attempt_balance_cents = 10000, attempt_method_id = 5, attempt_source = 'cron', attempt_status = 'processing', attempt_pi_id = ''");
$page = file_get_contents(__DIR__ . '/../agent/post/invoice.php');
$ok('the handler passes the stripe_checked field to chargeAttemptClear', preg_match('/chargeAttemptClear\(.*!empty\(\$_POST\[\'stripe_checked\'\]\)\)/', $page) === 1);
$inv = file_get_contents(__DIR__ . '/../agent/invoice.php');
$ok('the invoice page offers the "I checked Stripe" box', str_contains($inv, 'name="stripe_checked"') && str_contains($inv, 'I checked Stripe'));
exit($fail ? 1 : 0);
