<?php
// Review R33 (an address with an apostrophe must hit the claim check: the helper gets the RAW address, not an already-escaped one) and
// R32 (the portal contact form gives one generic answer for every refusal reason).
// Run: SCRATCH_TABLES="contacts domains" bash tests/lib/with_scratch_db.sh php tests/msp_ds_review_R32_R33_portal_claim.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require $root . '/includes/inbound_routing.php';
$m->query("INSERT INTO contacts (contact_name, contact_email, contact_client_id) VALUES ('oneil', 'o\\'neil@gmail.com', 2)");
$ok('stored address really contains the apostrophe', $m->query("SELECT contact_email FROM contacts")->fetch_row()[0] === "o'neil@gmail.com");
$ok('R33: raw o\'neil@gmail.com of another client is a conflict', portalContactEmailConflict($m, "o'neil@gmail.com", 1) === 'contact');
$ok('R33: (documents the old bug) an already-escaped value misses', portalContactEmailConflict($m, $m->real_escape_string("o'neil@gmail.com"), 1) === '');
$src = file_get_contents($root . '/client/post.php');
$ok('R33: both callers pass the raw address', substr_count($src, 'portalContactEmailConflict($mysqli, $contact_email_raw,') === 2 && !preg_match('/portalContactEmailConflict\(\$mysqli, \$contact_email[,)]/', $src));
$ok('R33: the raw value mirrors sanitizeInput minus escaping (strip_tags + trim)', substr_count($src, "\$contact_email_raw = trim(strip_tags((string) (\$_POST['contact_email'] ?? '')));") === 2);
$ok('R32: the old distinguishing wording is gone', !str_contains($src, 'as that email address is already in use'));
preg_match_all('/flash_alert\("(This email address cannot be used[^"]*)", \'danger\'\);/', $src, $mm);
$ok('R32: all four refusals (users-table and claim, add and edit) show the same sentence', count($mm[1]) === 4 && count(array_unique($mm[1])) === 1);
$ok('R32: claim refusals are logged for review', substr_count($src, "'Contact Address Refused'") === 2);
exit($fail ? 1 : 0);
