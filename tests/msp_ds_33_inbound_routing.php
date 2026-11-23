<?php
// MSP-DS-33 / MSP-DS-16: inbound mail is auto-filed only on an unambiguous contact/domain claim; portal contacts cannot claim another
// client's address or domain. Run: SCRATCH_TABLES="contacts domains" tests/lib/with_scratch_db.sh php tests/msp_ds_33_inbound_routing.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require dirname(__DIR__) . '/includes/inbound_routing.php';
// clients: 1 = A (attacker), 2 = B (victim), 3 = C, 4 = D (shares a domain with C)
$m->query("INSERT INTO domains (domain_name, domain_client_id) VALUES ('victim-b.example',2),('gmail.com',1),('shared.example',3),('shared.example',4),('a.example',1),('gone.example',2)");
$m->query("UPDATE domains SET domain_archived_at = NOW() WHERE domain_name = 'gone.example'");
$m->query("INSERT INTO contacts (contact_name, contact_email, contact_client_id) VALUES
  ('b1','existing@victim-b.example',2), ('claim','newhire@victim-b.example',1), ('a-own','own@a.example',1), ('g','bob@gmail.com',1),
  ('dup1','dup@multi.example',1), ('dup2','dup@multi.example',2)");
$m->query("INSERT INTO contacts (contact_name, contact_email, contact_client_id, contact_archived_at) VALUES ('old','oldb@elsewhere.example',2,NOW())");
$ok('a domain listed by one client routes to it', inboundDomainOwnerClient($m, 'victim-b.example') === 2);
$ok('domain match is case-insensitive', inboundDomainOwnerClient($m, 'Victim-B.Example') === 2);
$ok('gmail.com added to a client routes nowhere', inboundDomainOwnerClient($m, 'gmail.com') === 0);
$ok('a domain listed by two clients routes nowhere', inboundDomainOwnerClient($m, 'shared.example') === 0);
$ok('an archived domain routes nowhere', inboundDomainOwnerClient($m, 'gone.example') === 0);
$ok('an unlisted domain routes nowhere', inboundDomainOwnerClient($m, 'nobody.example') === 0);
$ok('freemail check', inboundIsFreemailDomain('GMAIL.com') && !inboundIsFreemailDomain('victim-b.example'));
$ok('contact on its own client\'s domain is trusted', ($r = inboundTrustedContact($m, 'own@a.example')) && (int) $r['contact_client_id'] === 1);
$ok('contact of the domain owner is trusted', ($r = inboundTrustedContact($m, 'existing@victim-b.example')) && (int) $r['contact_client_id'] === 2);
$ok('contact planted in client A on B\'s domain is NOT trusted (goes to review)', inboundTrustedContact($m, 'newhire@victim-b.example') === null);
$ok('same address held by two clients is NOT trusted', inboundTrustedContact($m, 'dup@multi.example') === null);
$ok('freemail contact in one client is trusted', ($r = inboundTrustedContact($m, 'bob@gmail.com')) && (int) $r['contact_client_id'] === 1);
$ok('unknown address has no contact', inboundTrustedContact($m, 'who@nowhere.example') === null);
// portal claims
$ok('portal: claiming a contact address of another client is refused', portalContactEmailConflict($m, 'existing@victim-b.example', 1) === 'contact');
$ok('portal: claiming an ARCHIVED contact address of another client is refused', portalContactEmailConflict($m, 'oldb@elsewhere.example', 1) === 'contact');
$ok('portal: claiming a new address on another client\'s domain is refused', portalContactEmailConflict($m, 'fresh@victim-b.example', 1) === 'domain');
$ok('portal: an address on your own domain is fine', portalContactEmailConflict($m, 'fresh@a.example', 1) === '');
$ok('portal: an unlisted outside address is fine', portalContactEmailConflict($m, 'x@unlisted.example', 1) === '');
$ok('portal: editing your own contact keeps its address', portalContactEmailConflict($m, 'own@a.example', 1, 3) === '');
$ok('portal: another client\'s listing of a freemail domain does not block gmail', portalContactEmailConflict($m, 'new@gmail.com', 2) === '');
$p = file_get_contents(dirname(__DIR__) . '/cron/ticket_email_parser.php');
$ok('parser no longer does the raw LIMIT 1 domain/contact lookups', strpos($p, "FROM domains WHERE domain_name = '\$from_domain_esc'") === false && strpos($p, "FROM contacts WHERE contact_email = '\$from_email_esc'") === false);
$c = file_get_contents(dirname(__DIR__) . '/client/post.php');
$ok('portal add and edit contact call the claim check', substr_count($c, 'portalContactEmailConflict(') === 2);
$d = file_get_contents(dirname(__DIR__) . '/agent/post/domain.php');
$ok('agent add and edit domain refuse freemail names', substr_count($d, 'inboundIsFreemailDomain(') === 2);
exit($fail ? 1 : 0);
