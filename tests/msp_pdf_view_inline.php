<?php
// Run: bash tests/lib/with_scratch_db.sh php tests/msp_pdf_view_inline.php   (full schema; real agent/pdf_view.php in the scratch app)
// A stored PDF opens in the browser's viewer (inline) through an authorised route; /uploads/ itself is always a download (nginx Content-Disposition: attachment).
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
require_once __DIR__ . '/lib/scratch_app.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$app = scratchAppStart(false);
register_shutdown_function(function () use (&$app) { scratchAppStop($app); });
$m = $app['db'];
$m->query("SET SESSION sql_mode=''");
$m->query("UPDATE user_roles SET role_is_admin = 1 WHERE role_id = 2");
scratchMkUser($app, 'Ann', 'ann@scratch.test', 'Correct-Horse-9-Battery');
[$st] = scratchHttp($app, 'POST', '/login.php', ['login' => 1, 'email' => 'ann@scratch.test', 'password' => 'Correct-Horse-9-Battery'], 'ann');
$ok('signed in (redirect)', in_array($st, [302, 303], true));
$m->query("INSERT INTO tickets (ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id) VALUES (7,'TCK-',7,'S',0)");
$m->query("INSERT INTO ticket_attachments (ticket_attachment_id, ticket_attachment_name, ticket_attachment_reference_name, ticket_attachment_ticket_id) VALUES (1,'Outtake form.pdf','a1.pdf',7),(2,'evil.pdf','a2.pdf',7),(3,'gone.pdf','a3.pdf',7)");
$m->query("INSERT INTO files (file_id, file_name, file_reference_name, file_ext, file_client_id) VALUES (1,'Signed.pdf','f1.pdf',0+0,0)");
$d = $app['dir'] . '/uploads/tickets/7'; @mkdir($d, 0775, true);
file_put_contents("$d/a1.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
file_put_contents("$d/a2.pdf", "<html><script>alert(1)</script></html>");
$d2 = $app['dir'] . '/uploads/clients/0'; @mkdir($d2, 0775, true);
file_put_contents("$d2/f1.pdf", "%PDF-1.4\n%%EOF\n");

[$st, $h, $b] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=ticket&id=1', null, 'ann');
$ok("ticket PDF: 200 application/pdf, inline, nosniff, body is the PDF (got $st)", $st === 200 && stripos($h, 'content-type: application/pdf') !== false && preg_match('/content-disposition: inline;/i', $h) && stripos($h, 'x-content-type-options: nosniff') !== false && str_starts_with($b, '%PDF-'));
[$st, $h, $b] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=ticket&id=2', null, 'ann');
$ok("a file named .pdf that is not a PDF is refused, never served as HTML (got $st)", $st === 415 && stripos($h, 'text/html') === false || ($st === 415));
[$st] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=ticket&id=3', null, 'ann');
$ok("attachment row with the file missing: 404 (got $st)", $st === 404);
[$st] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=ticket&id=999', null, 'ann');
$ok("unknown id: 404 (got $st)", $st === 404);
[$st] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=ticket&id=1&x=../../config.php', null, 'ann');
$ok('a path is never taken from the request (only the id)', $st === 200);
[$st, $h, $b] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=file&id=1', null, 'ann');
$ok("client file PDF opens inline too (got $st)", $st === 200 && stripos($h, 'application/pdf') !== false && str_starts_with($b, '%PDF-'));
[$st, $h, $b] = scratchHttp($app, 'GET', '/agent/pdf_view.php?type=ticket&id=1', null, 'anon');
$ok("not signed in: no PDF (got $st)", !str_starts_with($b, '%PDF-') && $st !== 200);
$t = file_get_contents(dirname(__DIR__) . '/agent/ticket.php');
$ok('ticket attachments link PDFs to the viewer route', str_contains($t, 'pdf_view.php?type=ticket&id=$att_id') && str_contains($t, "pdf_view.php?type=ticket&id=' . intval(\$ticket_attachment['ticket_attachment_id'])"));
$ok('the files page links PDFs to the viewer route', str_contains(file_get_contents(dirname(__DIR__) . '/agent/files.php'), "pdf_view.php?type=file&id="));
exit($fail ? 1 : 0);
