<?php
// MSP-DS-86 (signed worksheets/outtakes cannot be re-signed, edited or deleted through the API) and MSP-DS-85 (no doubled escaping in sign replies). Run:
// SCRATCH_TABLES="tickets ticket_worksheets ticket_worksheet_responses worksheet_templates worksheet_template_fields ticket_outtake_forms ticket_replies user_client_permissions users user_roles companies settings clients contacts folders files ticket_attachments" tests/lib/with_scratch_db.sh php tests/msp_ds_85_86_signed_lock.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$mysqli->query("SET SESSION sql_mode=''");
define('FROM_API', 1);
class Done extends Exception { public $code2; public $data; }
function api_error($c, $m) { $e = new Done($m); $e->code2 = $c; throw $e; }
function api_response($c, $d) { $e = new Done('ok'); $e->code2 = $c; $e->data = $d; throw $e; }
// php://input stub
class InputStub { public static $body = ''; private $pos = 0; public $context;
    function stream_open($p, $m, $o, &$op) { return true; } function stream_read($n) { $r = substr(self::$body, $this->pos, $n); $this->pos += strlen($r); return $r; }
    function stream_eof() { return $this->pos >= strlen(self::$body); } function stream_stat() { return []; } }
stream_wrapper_unregister('php'); stream_wrapper_register('php', 'InputStub');
if (($argv[1] ?? '') === 'child') {   // one API call per process: the endpoint files declare functions
    [, , $file, $resource, $id, $sub, $method, $bodyJson] = $argv;
    // the phone signs through includes/outtake_functions.php (PDF + file + ticket attachment + Internal note): give it the few framework helpers it uses, and a temp webroot
    function sanitizeInput($v) { global $mysqli; return mysqli_real_escape_string($mysqli, trim(strip_tags((string) $v))); }
    function sanitizeInputRaw($v) { return trim(strip_tags((string) $v)); }
    function mkdirMissing($d) { if (!is_dir($d)) { mkdir($d, 0775, true); } }
    function logAction(...$a) {}
    $id = (int) $id; $sub = $sub === '-' ? null : $sub; $api_user_id = 1; $api_key_client_id = null; InputStub::$body = $bodyJson;
    try { require $file; } catch (Done $d) { echo $d->code2; } exit(0);
}
// the api_permissions helper is loaded for real; the user is an admin so the module gate passes
$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1)");
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_role_id) VALUES (1,'a','a@x',1)");
$mysqli->query("INSERT INTO tickets (ticket_id, ticket_prefix, ticket_number, ticket_client_id) VALUES (10,'TCK-',10,1)");
$mysqli->query("INSERT INTO companies (company_id, company_name) VALUES (1,'Co')"); $mysqli->query("INSERT INTO settings (company_id) VALUES (1)"); $mysqli->query("INSERT INTO clients (client_id, client_name) VALUES (1,'Cl')");
$mysqli->query("INSERT INTO ticket_worksheets (worksheet_id, worksheet_ticket_id, worksheet_signed_name, worksheet_signed_at) VALUES (1,10,'Jane',NOW()),(2,10,NULL,NULL),(3,10,NULL,NULL)");
$mysqli->query("INSERT INTO ticket_outtake_forms (outtake_id, outtake_ticket_id, outtake_signed_name, outtake_signed_at) VALUES (1,10,'Jane',NOW()),(2,10,NULL,NULL),(3,10,NULL,NULL)");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
function call(string $file, string $resource, int $id, ?string $sub, string $method, array $body = []) {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($file) . ' ' . escapeshellarg($resource) . ' ' . $id . ' ' . escapeshellarg($sub ?? '-') . ' ' . $method . ' ' . escapeshellarg(json_encode($body));
    return (int) trim((string) shell_exec($cmd . ' 2>/dev/null'));
}
$ws = __DIR__ . '/../api/v1/worksheets.php'; $ot = __DIR__ . '/../api/v1/outtakes.php';
$sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$ok('worksheet: re-sign a signed worksheet -> 409', call($ws, 'worksheets', 1, 'sign', 'POST', ['signed_name' => 'Forged', 'signature' => $sig]) === 409);
$r = $mysqli->query("SELECT worksheet_signed_name FROM ticket_worksheets WHERE worksheet_id = 1")->fetch_row();
$ok('worksheet: signer name unchanged', $r[0] === 'Jane');
$ok('worksheet: responses on a signed worksheet -> 409', call($ws, 'worksheets', 1, 'responses', 'POST', ['responses' => [['field_id' => 5, 'value' => 'x']]]) === 409);
$ok('worksheet: no response row written', (int) $mysqli->query("SELECT COUNT(*) FROM ticket_worksheet_responses")->fetch_row()[0] === 0);
$ok('worksheet: delete a signed worksheet -> 409', call($ws, 'worksheets', 1, null, 'DELETE') === 409);
$ok('worksheet: signed worksheet still exists', (int) $mysqli->query("SELECT COUNT(*) FROM ticket_worksheets WHERE worksheet_id = 1")->fetch_row()[0] === 1);
$ok('worksheet: an unsigned one signs once (200), apostrophe not doubled', call($ws, 'worksheets', 2, 'sign', 'POST', ['signed_name' => "O'Brien", 'signature' => $sig]) === 200);
$rep = $mysqli->query("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply LIKE 'Worksheet signed by%'")->fetch_row();
$ok('worksheet: reply reads O\'Brien without backslash (' . ($rep[0] ?? '') . ')', ($rep[0] ?? '') === "Worksheet signed by O'Brien");
$ok('worksheet: second sign of that one -> 409', call($ws, 'worksheets', 2, 'sign', 'POST', ['signed_name' => 'Again']) === 409);
$ok('worksheet: unsigned worksheet can still be deleted', call($ws, 'worksheets', 3, null, 'DELETE') === 200);
// MSP-DS-87: responses update in place, only template fields accepted
$mysqli->query("INSERT INTO worksheet_templates (worksheet_template_id, worksheet_template_name) VALUES (1,'T')");
$mysqli->query("INSERT INTO worksheet_template_fields (field_id, field_template_id, field_name) VALUES (5,1,'F5'),(6,2,'other template')");
$mysqli->query("INSERT INTO ticket_worksheets (worksheet_id, worksheet_ticket_id, worksheet_template_id) VALUES (4,10,1)");
call($ws, 'worksheets', 4, 'responses', 'POST', ['responses' => [['field_id' => 5, 'value' => 'one'], ['field_id' => 6, 'value' => 'foreign']]]);
call($ws, 'worksheets', 4, 'responses', 'POST', ['responses' => [['field_id' => 5, 'value' => 'two']]]);
$rows = $mysqli->query("SELECT response_field_id, response_value FROM ticket_worksheet_responses WHERE response_worksheet_id = 4")->fetch_all();
$ok('MSP-DS-87: two saves leave one row with the latest value', count($rows) === 1 && $rows[0][0] == 5 && $rows[0][1] === 'two');
$ok('outtake: re-sign a signed outtake -> 409', call($ot, 'outtakes', 1, 'sign', 'POST', ['signed_name' => 'Forged', 'signature' => $sig]) === 409);
$ok('outtake: delete a signed outtake -> 409', call($ot, 'outtakes', 1, null, 'DELETE') === 409);
$ok('outtake: signed outtake still exists', (int) $mysqli->query("SELECT COUNT(*) FROM ticket_outtake_forms WHERE outtake_id = 1")->fetch_row()[0] === 1);
$ok('outtake: unsigned signs once (200)', call($ot, 'outtakes', 2, 'sign', 'POST', ['signed_name' => "O'Brien", 'signature' => $sig]) === 200);
$rep = $mysqli->query("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply LIKE 'Outtake form signed by%'")->fetch_row();
$ok('outtake: reply reads O\'Brien without backslash (' . ($rep[0] ?? '') . ')', str_starts_with((string) ($rep[0] ?? ''), "Outtake form signed by O'Brien on "));
// TCK-144: signing on the phone must leave what the web signing leaves: the signed PDF in the client's files and as a ticket attachment, plus the Internal note
$ok('outtake: the phone signature produced the PDF file record and the ticket attachment (TCK-144)', (int) $mysqli->query("SELECT COUNT(*) FROM files WHERE file_client_id = 1 AND file_ext = 'pdf'")->fetch_row()[0] === 1 && (int) $mysqli->query("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = 10")->fetch_row()[0] === 1);
$ok('outtake: second sign refused', call($ot, 'outtakes', 2, 'sign', 'POST', ['signed_name' => 'x', 'signature' => $sig]) === 409);
$ok('outtake: unsigned form can still be deleted', call($ot, 'outtakes', 3, null, 'DELETE') === 200);
$t = file_get_contents(__DIR__ . '/../api/v1/tickets.php');
$ok('MSP-DS-85: ticket notifications use the raw subject', str_contains($t, '$subject_raw has been created via API') && str_contains($t, '$subject_raw has been assigned to you'));
exit($fail ? 1 : 0);
