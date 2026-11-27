<?php
// MSP-DS-162 (complete): the REAL guest/guest_post.php quote upload: status/expiry gate, 5 files per request and 20 per quote, ONE notification per batch
// (no file name in it), and a per-address request limit (10 per 10 minutes) that holds without Redis. 'Viewed' notifications are de-duplicated hourly.
// Run: SCRATCH_TABLES="settings companies clients files folders quotes quote_files history logs notifications user_roles users user_settings user_client_permissions" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_162.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$n = fn (string $s) => (int) (($r = $m->query($s)) && ($x = $r->fetch_row()) ? $x[0] : -1);
$m->query("INSERT INTO settings (company_id, config_core_redis_enabled) VALUES (1, 0)");
$m->query("INSERT INTO companies (company_id, company_name, company_locale, company_currency, company_country) VALUES (1, 'MSP', 'en_US', 'USD', 'US')");
$m->query("INSERT INTO clients (client_id, client_name) VALUES (7, 'Acme')");
$m->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status) VALUES (1, 'Agent', 'a@x.test', 'x', 1, 1)");
$q = fn (int $id, string $status, ?string $expire = null) => $m->query("INSERT INTO quotes (quote_id, quote_prefix, quote_number, quote_status, quote_expire, quote_client_id, quote_url_key, quote_date) VALUES ($id, 'QUO-', $id, '$status', " . ($expire ? "'$expire'" : 'NULL') . ", 7, 'key$id', CURDATE())");
foreach ([[1, 'Draft'], [2, 'Declined'], [3, 'Sent'], [4, 'Sent'], [5, 'Accepted']] as [$i, $st]) { $q($i, $st); }
$q(6, 'Sent', '2000-01-01');
$pdfs = fn (int $k) => array_map(fn ($i) => ['name' => "URGENT call 555-0100 $i.pdf", 'content' => '%PDF-1.4 ' . $i], range(1, $k));
$run = function (int $quote, int $nfiles, string $ip = '127.0.0.1') use ($pdfs) {
    $p = proc_open([PHP_BINARY, __DIR__ . '/lib/run_guest_post.php', json_encode(['post' => ['guest_quote_upload_file' => 1, 'quote_id' => $quote, 'url_key' => "key$quote"], 'files' => $pdfs($nfiles), 'ip' => $ip])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);

    return preg_match('/::END::(.*)$/m', $out, $x) ? $x[1] : 'ERR ' . $out;
};
$files = fn (int $quote) => $n("SELECT COUNT(*) FROM quote_files WHERE quote_id = $quote");
$notes = fn () => $n("SELECT COUNT(*) FROM notifications");

$a = $run(1, 2, '10.0.0.1');
$ok("Draft quote: refused, nothing stored ($a)", $files(1) === 0 && str_contains($a, 'no longer accepting'));
$run(2, 2, '10.0.0.1');
$ok('Declined quote: refused', $files(2) === 0);
$run(6, 2, '10.0.0.1');
$ok('expired Sent quote: refused', $files(6) === 0);
$run(3, 20, '10.0.0.2');
$ok('Sent quote: at most 5 files in one request', $files(3) === 5);
$ok('...and one notification for the batch, with no file name in it', $notes() === 1 && $n("SELECT COUNT(*) FROM notifications WHERE notification LIKE '%URGENT%' OR notification LIKE '%555%'") === 0);
$run(5, 1, '10.0.0.3');
$ok('Accepted quote still takes files (customer sends the signed PDF)', $files(5) === 1);
for ($i = 0; $i < 4; $i++) { $run(4, 5, '10.0.0.4'); }
$ok('a quote never holds more than 20 files', $files(4) === 20);
$run(4, 5, '10.0.0.4');
$ok('...a fifth full request adds none', $files(4) === 20);

// per-IP request limit: 10 requests per 10 minutes per address, counted in logs (works without Redis); another address is unaffected
$before = $files(3);
for ($i = 0; $i < 12; $i++) { $m->query("DELETE FROM quote_files WHERE quote_id = 3"); $run(3, 1, '10.0.0.9'); }
$ok('requests beyond 10 per 10 minutes from one address store nothing', $n("SELECT COUNT(*) FROM logs WHERE log_type = 'Guest' AND log_action = 'Quote Upload Attempt' AND log_ip = '10.0.0.9'") === 10 && $files(3) === 0);
$a = $run(3, 1, '10.0.0.9');
$ok("the throttled request says so ($a)", str_contains($a, 'Too many uploads') && $files(3) === 0);
$run(3, 1, '10.0.0.10');
$ok('a different address is not affected', $files(3) === 1);
$m->query("UPDATE logs SET log_created_at = NOW() - INTERVAL 11 MINUTE WHERE log_ip = '10.0.0.9'");
$run(3, 1, '10.0.0.9');
$ok('after the window passes the address can upload again', $files(3) === 2);

$g = file_get_contents(__DIR__ . '/../guest/guest_post.php');
$ok('Redis bucket is consulted too (fails open without Redis)', str_contains($g, "rivetRateLimit('guest-quote-upload:ip:'"));
foreach (['guest_view_quote', 'guest_view_invoice'] as $f) {
    $s = file_get_contents(__DIR__ . "/../guest/$f.php");
    $ok("$f: the 'viewed' notification is de-duplicated hourly", str_contains($s, '$recently_viewed') && str_contains($s, 'INTERVAL 1 HOUR') && str_contains($s, '!$recently_viewed'));
}
exit($fail ? 1 : 0);
