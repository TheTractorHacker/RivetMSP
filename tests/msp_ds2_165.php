<?php
// MSP-DS-165: creates sent to QuickBooks/Xero carry a stable idempotency token (QBO ?requestid=, Xero Idempotency-Key), so a retried create
// whose first response was lost cannot make a second invoice/estimate/payment; a total that differs from RivetMSP's is flagged in the sync log.
// Runs the REAL QboClient and XeroClient against a local capture server. Run: php tests/msp_ds2_165.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
function decryptSetting($v) { return $v; }   // the clients only need the stored token back
require $root . '/includes/interface_accounting_client.php';
require $root . '/includes/class_qbo_client.php';
require $root . '/includes/class_xero_client.php';
$src = file_get_contents($root . '/includes/accounting_functions.php');
preg_match('/function accountingRequestKey.*?\n\}\n/s', $src, $a); preg_match('/function accountingTotalsDriftNote.*?\n\}\n/s', $src, $b);
eval($a[0] . $b[0]);

$ok('request key is stable for the same document and differs per document/type', accountingRequestKey(1, 'invoice', 7) === accountingRequestKey(1, 'invoice', 7) && accountingRequestKey(1, 'invoice', 7) !== accountingRequestKey(1, 'invoice', 8) && accountingRequestKey(1, 'invoice', 7) !== accountingRequestKey(1, 'payment', 7));
$p = ['_request_key' => "rivet-1-invoice-7; DROP\r\nX: y", 'a' => 1]; $k = accountingTakeRequestKey($p);
$ok('the key is stripped from the payload and sanitised (no header injection)', $p === ['a' => 1] && !preg_match('/[\r\n;: ]/', $k) && strlen($k) <= 50);
$ok('no drift note when totals match', accountingTotalsDriftNote(110.0, 110.0) === '' && accountingTotalsDriftNote(110.0, '110.004') === '' && accountingTotalsDriftNote(110.0, null) === '');
$ok('drift note when the remote total differs (tax/discount missing)', str_contains(accountingTotalsDriftNote(110.0, 100.0), 'WARNING') && str_contains(accountingTotalsDriftNote(100.0, 110.0), '110.00'));

// capture server: records method, URI and the Idempotency-Key header, answers with the minimum each client needs
$d = sys_get_temp_dir() . '/ds165_' . bin2hex(random_bytes(4)); mkdir($d);
file_put_contents("$d/r.php", '<?php file_put_contents(' . var_export("$d/log", true) . ', json_encode(["m" => $_SERVER["REQUEST_METHOD"], "u" => $_SERVER["REQUEST_URI"], "k" => $_SERVER["HTTP_IDEMPOTENCY_KEY"] ?? null, "b" => json_decode(file_get_contents("php://input"), true)]) . "\n", FILE_APPEND);
header("Content-Type: application/json");
$u = $_SERVER["REQUEST_URI"];
if (str_contains($u, "/invoice")) echo json_encode(["Invoice" => ["Id" => "9", "SyncToken" => "0", "DocNumber" => "X", "TotalAmt" => 100.0]]);
elseif (str_contains($u, "/estimate")) echo json_encode(["Estimate" => ["Id" => "8", "SyncToken" => "0"]]);
elseif (str_contains($u, "/payment")) echo json_encode(["Payment" => ["Id" => "7"]]);
elseif (str_contains($u, "Invoices")) echo json_encode(["Invoices" => [["InvoiceID" => "i1", "InvoiceNumber" => "X", "Total" => 100.0]]]);
elseif (str_contains($u, "Quotes")) echo json_encode(["Quotes" => [["QuoteID" => "q1", "QuoteNumber" => "Q"]]]);
else echo json_encode(["Payments" => [["PaymentID" => "p1"]], "Accounts" => [["Code" => "090", "Type" => "BANK", "Status" => "ACTIVE", "EnableAccountsPayments" => true]]]);');
$port = 21000 + random_int(0, 9000);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$d/r.php"], [1 => ['file', "$d/srv.out", 'w'], 2 => ['file', "$d/srv.err", 'w']], $pipes);
for ($i = 0; $i < 30 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
$last = fn () => ($l = file("$d/log", FILE_IGNORE_NEW_LINES)) ? json_decode(end($l), true) : null;

$integ = ['accounting_id' => 1, 'accounting_realm_id' => 'realm1', 'accounting_client_id' => 'c', 'accounting_client_secret' => 's', 'accounting_access_token' => 'tok',
          'accounting_token_expires_at' => date('Y-m-d H:i:s', time() + 3600), 'accounting_environment' => 'sandbox'];
$db = (new ReflectionClass('mysqli'))->newInstanceWithoutConstructor();
$qbo = new QboClient($db, $integ, ['api_base' => "http://127.0.0.1:$port"]);
$key = accountingRequestKey(1, 'invoice', 7);
$r = $qbo->createInvoice(['_request_key' => $key, 'CustomerRef' => ['value' => '1'], 'Line' => []]);
$l = $last();
$ok('QBO invoice create sends ?requestid=<key>', $l && str_contains($l['u'], 'requestid=' . $key) && $r['Id'] === '9');
$ok('QBO payload does not carry the internal key', $l && !isset($l['b']['_request_key']));
$qbo->createEstimate(['_request_key' => accountingRequestKey(1, 'quote', 3), 'CustomerRef' => ['value' => '1'], 'Line' => []]);
$ok('QBO estimate create sends the key', str_contains($last()['u'], 'requestid=rivet-1-quote-3'));
$qbo->createPayment(['_request_key' => accountingRequestKey(1, 'payment', 5), 'TotalAmt' => 1, 'CustomerRef' => ['value' => '1'], 'Line' => []]);
$ok('QBO payment create sends the key', str_contains($last()['u'], 'requestid=rivet-1-payment-5'));
$qbo->createInvoice(['CustomerRef' => ['value' => '1'], 'Line' => []]);
$ok('without a key nothing extra is sent', !str_contains($last()['u'], 'requestid'));

$xero = new XeroClient($db, $integ, ['api_base' => "http://127.0.0.1:$port"]);
$x = $xero->createInvoice(['_request_key' => $key, 'CustomerRef' => ['value' => 'c1'], 'TxnDate' => '2026-01-01', 'DueDate' => '2026-02-01', 'DocNumber' => 'X', 'Line' => []]);
$l = $last();
$ok('Xero invoice create sends the Idempotency-Key header', $l && $l['k'] === $key && !isset($l['b']['_request_key']));
$ok('Xero create result now carries TotalAmt for the drift check', ($x['TotalAmt'] ?? null) === 100.0);
$xero->createEstimate(['_request_key' => accountingRequestKey(1, 'quote', 3), 'CustomerRef' => ['value' => 'c1'], 'TxnDate' => '2026-01-01', 'Line' => []]);
$ok('Xero quote create sends the header', $last()['k'] === 'rivet-1-quote-3');

// the sync orchestration passes the keys (static: it needs a whole DB to run)
foreach (['invoice', 'payment'] as $t) { $ok("accounting_functions.php passes a request key for the $t create", str_contains($src, "accountingRequestKey(\$acc_id, '$t', \$job_local_id)")); }
$ok('accounting_functions.php passes a request key for the quote create', str_contains($src, "accountingRequestKey(\$acc_id, 'quote', \$job_local_id)"));
$ok('accounting_functions.php logs a total drift on the invoice push', str_contains($src, 'accountingTotalsDriftNote(floatval($invoice[\'invoice_amount\'])'));

proc_terminate($proc); shell_exec('rm -rf ' . escapeshellarg($d));
exit($fail ? 1 : 0);
