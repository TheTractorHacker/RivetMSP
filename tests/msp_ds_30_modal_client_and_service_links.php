<?php
// MSP-DS-30: agent pop-ups refuse ?client_id= of a client the restricted user may not access; service links must be the service's client's. Run:
//   SCRATCH_TABLES="documents credentials domains certificates contacts assets vendors" tests/lib/with_scratch_db.sh php tests/msp_ds_30_modal_client_and_service_links.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require dirname(__DIR__) . '/includes/same_client.php';
$m->query("INSERT INTO documents (document_id, document_name, document_client_id) VALUES (11,'a',1),(22,'b',2)");
$m->query("INSERT INTO credentials (credential_id, credential_name, credential_client_id) VALUES (11,'a',1),(22,'b',2)");
$m->query("INSERT INTO domains (domain_id, domain_name, domain_client_id) VALUES (11,'a.test',1),(22,'b.test',2)");
$m->query("INSERT INTO certificates (certificate_id, certificate_name, certificate_client_id) VALUES (11,'a',1),(22,'b',2)");
foreach (['document', 'credential', 'domain', 'certificate'] as $k) {
    $ok("$k of the service's client is accepted", childrenBelongToClient($m, $k, [11], 1));
    $ok("$k of another client is refused", !childrenBelongToClient($m, $k, [11, 22], 1));
}
$root = dirname(__DIR__);
$s = file_get_contents("$root/agent/post/service.php");
foreach (['add_service', 'edit_service'] as $h) {
    $i = strpos($s, "isset(\$_POST['$h'])"); $j = strpos($s, "\nif (isset(", $i + 10); $b = substr($s, $i, ($j ?: strlen($s)) - $i);
    $a = strpos($b, 'assertSameClient('); $w = strpos($b, 'INSERT INTO services');
    $w = $w === false ? strpos($b, 'UPDATE services') : $w;
    $ok("$h validates the links before it writes", $a !== false && $a < $w && strpos($b, "'certificates'") !== false);
}
$h = file_get_contents("$root/includes/modal_header.php");
$a = strpos($h, '$_GET[\'client_id\']');
$ok('modal_header checks ?client_id= for restricted users', $a !== false && strpos($h, 'user_client_permissions', $a) !== false && strpos($h, "You do not have access to this client.", $a) !== false);
$ok('the client_id check applies to every agent modal folder, not only ticket', $a < strpos($h, "\$mh_dir === 'ticket' && isset"));
exit($fail ? 1 : 0);
