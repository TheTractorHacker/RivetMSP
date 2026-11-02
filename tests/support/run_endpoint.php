<?php
/**
 * One-shot CLI runner for tests/kb_media_embeds.php. Reads a JSON job from stdin:
 *   {"root": "<document root>", "file": "<relative path under root>", "get": {...}, "session": {...}}
 * Prints the response BODY verbatim to stdout (raw bytes - see the note below on why this cannot be JSON),
 * and the HTTP status code to stderr as a single sentinel line: "KB_TEST_STATUS:<code>".
 *
 * WHY $_SESSION IS SET DIRECTLY, NOT VIA A REAL COOKIE/session_start(): includes/session_init.php (agent) and
 * client/includes/check_login.php's own `if (!isset($_SESSION)) { session_start(); }` guard only call
 * session_start() when $_SESSION is not already an array. Pre-populating $_SESSION here before the target file
 * is required makes that guard a no-op - session_start() is skipped entirely, nothing is read from or written
 * to a session store - while every DOWNSTREAM check the target file actually runs (load_user_session.php's
 * query against `users`/`user_roles`, the user_client_permissions lookup, lookupUserPermission(),
 * enforceClientAccess(), kbMediaClientAccessOk(), client/includes/check_login.php's own `contacts`/`clients`
 * queries) executes for real against the scratch database, keyed off the identity this script seeds. So this
 * is not a stand-in for session-based auth - it is the same auth code path with the one part that needs a real
 * browser/cookie (session storage) short-circuited, which is exactly the part this test has no need to exercise
 * (RivetMSP's own session mechanism is not what issue #7 is testing).
 *
 * Runs ONLY under RIVETIT_TEST_DB=1 (re-checked here, independently of the caller, so this file can never be
 * pointed at a live site by mistake).
 */

if (getenv('RIVETIT_TEST_DB') !== '1') {
    fwrite(STDERR, "run_endpoint.php refuses to run without RIVETIT_TEST_DB=1\n");
    exit(1);
}

$job = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($job)) {
    fwrite(STDOUT, json_encode(['status' => 0, 'body' => '']));
    exit(0);
}

$root = (string) $job['root'];
$file = (string) $job['file'];
$get = is_array($job['get'] ?? null) ? $job['get'] : [];
$session = is_array($job['session'] ?? null) ? $job['session'] : [];

$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'kb-media-embeds-test';
$_SERVER['SCRIPT_NAME'] = '/' . ltrim($file, '/');
$_GET = $get;

if ($session !== []) {
    // Deliberately assigning the superglobal directly (not via session_start()) - see this file's header.
    $_SESSION = $session;
} else {
    // An explicitly-empty session: still assign the array so session_init.php's `isset($_SESSION)` guard skips
    // session_start() (there is no cookie jar in this CLI process for it to use), leaving check_login.php's
    // own "$_SESSION['logged']" test to fail honestly, exactly as it would for a real anonymous request.
    $_SESSION = [];
}

// NOT output buffering for the body: kbMediaServe() deliberately drains every output buffer
// (`while (ob_get_level() > 0) { ob_end_clean(); }`) immediately before fpassthru(), on purpose, so that
// serving a large attachment never has to hold the whole file in a PHP buffer. An ob_start() here would
// just get silently discarded by that line, so the file's raw bytes go straight to real STDOUT - which this
// script treats as the actual response body, not an error. The status code cannot ride along in that same
// stream, so it goes to STDERR instead, as a single sentinel line, and the test harness reads status from
// stderr and body from stdout separately.
register_shutdown_function(function () {
    $status = http_response_code();
    if ($status === false) {
        $status = 200;
    }
    fwrite(STDERR, "\nKB_TEST_STATUS:" . $status . "\n");
});

chdir($root . '/' . dirname($file));
require $root . '/' . $file;
