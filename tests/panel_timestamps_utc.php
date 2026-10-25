<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Regression test for a real bug caught while adopting RivetCore 1.0.0-rc.38's conformance
 * addition (PanelTicketGatewayConformanceTestCase::testTimestampsAreUtcAndCurrent() - see
 * vendor/rivet/rivet-core/UPGRADING.md "Adopting the rc.38 hardening").
 *
 * src/Core/Adapter/Endpoint/EndpointPanelGateway.php's toUtc() used to build a DateTimeZone from
 * ini_get('date.timezone') and feed it to `new DateTime($datetime, $tz)` - but the Panel API's
 * unauthenticated device-token routes never run includes/inc_set_timezone.php (that only happens
 * on a logged-in user's session), so this trusted whatever PHP's bare ini default happened to be,
 * with no guarantee it matches the timezone MySQL's own NOW()/current_timestamp() wall clock
 * actually runs on. On THIS install they happen to agree (both America/Chicago) so the bug never
 * showed up by accident - until PHP's ambient default is forced to something else (UTC here),
 * at which point the old code was off by the full MySQL-to-UTC offset (5 hours on this install).
 *
 * The fix asks MySQL directly for its own current offset from UTC (TIMESTAMPDIFF(SECOND, NOW(),
 * UTC_TIMESTAMP())) instead of trusting PHP's ini state, and parses the raw wall-clock string with
 * DateTime::createFromFormat(..., new DateTimeZone('UTC')) - a fixed, explicit zone object used
 * purely as a neutral parser, not an ambient one - before adding that offset. (The first attempt
 * at this fix used strtotime(), which itself applies PHP's AMBIENT default timezone to a bare
 * string - exactly reproducing the double-offset bug this is supposed to fix. Kept as a comment in
 * the implementation so nobody "simplifies" it back to strtotime().)
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/panel_timestamps_utc.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/src/Core/Adapter/Endpoint/EndpointPanelGateway.php";

echo "\n--- fixtures ---\n";
$q("DELETE FROM tickets WHERE ticket_subject LIKE 'tzu-test-%'");
$q("DELETE FROM contacts WHERE contact_name LIKE 'tzu-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'tzu-test-%'");

function tzuMakeTicket(mysqli $db, string $label): array {
    mysqli_query($db, "INSERT INTO clients SET client_name='tzu-test-$label'");
    $clientId = (int) $db->insert_id;
    mysqli_query($db, "INSERT INTO contacts SET contact_client_id=$clientId, contact_name='tzu-test-$label', contact_email='tzu-$label@example.test', contact_primary=1");
    $contactId = (int) $db->insert_id;
    $gw = new \RivetMSP\Core\Adapter\Endpoint\EndpointPanelGateway($db);
    $ticketId = $gw->createTicket($clientId, $contactId, "tzu-test-$label subject", 'first message', null, '', []);
    $header = $gw->ticket($ticketId);
    return ['created_at' => $header['created_at'], 'ticket_id' => $ticketId];
}

echo "\n--- MySQL's own wall-clock vs UTC offset on this install (for context in the assertions below) ---\n";
$offset = (int) $one('SELECT TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP())');
echo "MySQL NOW() is $offset seconds behind UTC_TIMESTAMP() on this install.\n";

echo "\n--- ticket created_at claims to be UTC and must be within a few seconds of real UTC now ---\n";
$t1 = tzuMakeTicket($db, 'ambient-default');
$diff1 = abs(strtotime($t1['created_at']) - strtotime(gmdate('Y-m-d H:i:s')));
$ok($diff1 <= 5, "with PHP's ambient default timezone (whatever this process started with), created_at is within 5s of real UTC now (off by {$diff1}s)");

echo "\n--- the SAME gateway must be correct even when PHP's ambient default timezone does NOT match MySQL's wall-clock zone ---\n";
// Simulates the real risk this bug depended on: a server (or a later code path, e.g. one that DID
// run includes/inc_set_timezone.php with a company timezone different from the DB server's own)
// where PHP's default timezone and MySQL's wall clock disagree. Forcing it to UTC here only
// proves something if MySQL's own offset is non-zero - assert that precondition explicitly so a
// future scratch DB/container that happens to run MySQL in UTC doesn't make this check vacuous.
$ok($offset !== 0, 'precondition: this scratch MySQL does NOT run on UTC wall-clock time, so forcing PHP to UTC below is a genuine mismatch, not a no-op');
date_default_timezone_set('UTC');
$t2 = tzuMakeTicket($db, 'forced-utc');
$diff2 = abs(strtotime($t2['created_at']) - strtotime(gmdate('Y-m-d H:i:s')));
$ok($diff2 <= 5, "with PHP's ambient default timezone forced to UTC (deliberately mismatched from MySQL's wall clock), created_at is STILL within 5s of real UTC now (off by {$diff2}s) - proves the fix does not depend on PHP's ini state matching MySQL's");

echo "\n--- updated_at (the reply path) is also correct, not just created_at ---\n";
$gw = new \RivetMSP\Core\Adapter\Endpoint\EndpointPanelGateway($db);
$clientId = (int) $one("SELECT client_id FROM clients WHERE client_name = 'tzu-test-forced-utc'");
$contactId = (int) $one("SELECT contact_id FROM contacts WHERE contact_name = 'tzu-test-forced-utc'");
$gw->postMessage($t2['ticket_id'], $clientId, $contactId, 'a follow-up message', '', []);
$msgs = $gw->messages($t2['ticket_id'], 0, 50);
$ok(!empty($msgs), 'the follow-up message was stored');
foreach ($msgs as $m) {
    $diffM = abs(strtotime($m['created_at']) - strtotime(gmdate('Y-m-d H:i:s')));
    $ok($diffM <= 5, "message created_at is within 5s of real UTC now (off by {$diffM}s): {$m['created_at']}");
}
