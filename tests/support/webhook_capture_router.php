<?php
/*
 * Minimal webhook receiver for tests (run via `php -S` as a child process, same pattern as
 * tests/rmm_golden/router.php): answers every request 200 OK and writes the raw request body to the file
 * named by WEBHOOK_CAPTURE_FILE, so a test can assert on exactly what a real delivery sent. Never a real
 * destination - a stand-in for Slack's/Teams' own webhook endpoint.
 */
$capture = getenv('WEBHOOK_CAPTURE_FILE');
if ($capture) {
    file_put_contents($capture, (string) file_get_contents('php://input'));
}
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
