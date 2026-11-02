<?php
/**
 * GitHub issue #7 ("KB v2" media attachments + embeds): cross-tenant denial test.
 *
 * Proves, against a schema-only scratch database (built from this worktree's own db.sql - no live rows, no
 * live secrets), that:
 *   - a client-portal contact of Client B cannot fetch Client A's KB attachment or embed, by id, directly or
 *     by guessing/incrementing the id, through client/kb_media.php or client/kb_embed.php;
 *   - a client-portal contact of either client CAN fetch a global/company-wide (client_id = 0) article's
 *     attachment/embed;
 *   - a technician restricted (via user_client_permissions) to Client A cannot fetch Client B's attachment or
 *     embed through agent/kb_media.php or agent/kb_embed.php;
 *   - an MSP admin technician (no user_client_permissions restriction, admin role) CAN fetch every client's
 *     attachment/embed, matching the existing enforceClientAccess()/agent/kb_article.php behaviour this port
 *     must not be stricter than.
 *
 * Each case is driven through the REAL endpoint file in a fresh PHP CLI subprocess (tests/support/run_endpoint.php),
 * with $_SESSION pre-seeded to look exactly like a real cookie session would (see that file's header for why this
 * is equivalent to - not a stand-in for - a real session), so what is under test is this port's own SQL/branching
 * logic, not a reimplementation of it.
 *
 * REQUIRES: RIVETIT_TEST_DB=1 and a config.php pointed at the scratch DB (see
 * tests/support/scratch_env.sh, which builds both). Run:
 *   RIVETIT_TEST_DB=1 php tests/kb_media_embeds.php
 */

if (getenv('RIVETIT_TEST_DB') !== '1') {
    fwrite(STDERR, "Refusing to run: RIVETIT_TEST_DB=1 is required (this test's config.php must point at a scratch DB, never a live one).\n");
    exit(1);
}

$root = dirname(__DIR__);
$runner = $root . '/tests/support/run_endpoint.php';

$failures = [];
$passed = 0;

/**
 * @param array<string,mixed> $get
 * @param array<string,mixed> $session
 */
function kbTestRequest(string $runner, string $root, string $file, array $get, array $session): array
{
    // Real $_GET values are always strings (or arrays of strings) - PHP's own query-string parser never
    // produces an int. json_encode(['att' => 1]) would produce a JSON number, and the run_endpoint.php side
    // would decode it back as a PHP int, which kbMediaParam()/kbMediaPortalParam() in the endpoints under test
    // deliberately treat as "not a string" => null => deny. Casting here keeps every call site's `['att' => 1]`
    // shorthand working while still exercising the endpoints with the same *type* a real HTTP request sends.
    $get = array_map('strval', $get);

    $payload = json_encode([
        'root'    => $root,
        'file'    => $file,
        'get'     => $get,
        'session' => $session,
    ]);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', $runner], $descriptors, $pipes, $root);
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => '', 'stderr' => 'proc_open failed'];
    }
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // The status rides on stderr as a sentinel line (see run_endpoint.php's header for why stdout cannot carry
    // it): kbMediaServe() drains every output buffer immediately before fpassthru(), so stdout holds the raw
    // response body - exactly as a real client would receive it - with nothing else mixed in.
    $status = 0;
    if (preg_match('/KB_TEST_STATUS:(\d+)/', (string) $err, $m)) {
        $status = (int) $m[1];
        $err = trim((string) preg_replace('/KB_TEST_STATUS:\d+/', '', $err));
    }

    return ['status' => $status, 'body' => (string) $body, 'stderr' => $err];
}

function kbAssert(string $label, bool $ok, string $detail, array &$failures, int &$passed): void
{
    if ($ok) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures[] = "$label -- $detail";
        echo "FAIL: $label -- $detail\n";
    }
}

// Session shapes. Real sessions carry more keys than these; check_login.php's own queries (load_user_session.php,
// client/includes/check_login.php) re-derive everything else from the database, so only the identity key each
// lane actually reads is seeded here - which is the point: nothing about the authorization result is supplied
// by the test, only "who is asking".
$session_admin_tech = ['logged' => true, 'user_id' => 1, 'csrf_token' => 'x'];
$session_tech_a     = ['logged' => true, 'user_id' => 2, 'csrf_token' => 'x'];
$session_contact_a  = ['client_logged_in' => true, 'client_id' => 10, 'contact_id' => 100, 'user_id' => 10001];
$session_contact_b  = ['client_logged_in' => true, 'client_id' => 20, 'contact_id' => 200, 'user_id' => 10002];

// ---------------------------------------------------------------------------
// Client portal: cross-tenant denial (Client B contact against Client A media/embeds)
// ---------------------------------------------------------------------------

$r = kbTestRequest($runner, $root, 'client/kb_media.php', ['att' => 1], $session_contact_b);
kbAssert(
    'Contact B denied Client A attachment (att=1) via client/kb_media.php',
    $r['status'] === 404,
    "expected 404, got {$r['status']} body=" . substr((string) $r['body'], 0, 200) . " stderr=" . substr((string) ($r['stderr'] ?? ''), 0, 400),
    $failures, $passed
);

// "by guessing/incrementing the id": sweep every attachment id in the fixture set from Contact B's session and
// confirm the ONLY ones that resolve are Contact B's own (att=3, Client B's own attachment) and the global one
// (att=2) - never att=1, which belongs to Client A.
foreach ([1, 2, 3] as $att_id) {
    $r = kbTestRequest($runner, $root, 'client/kb_media.php', ['att' => $att_id], $session_contact_b);
    $should_allow = ($att_id === 2 || $att_id === 3); // global, or Client B's own
    kbAssert(
        "Contact B sweeping att=$att_id " . ($should_allow ? '(expect allow)' : '(expect deny, belongs to Client A)'),
        $should_allow ? $r['status'] === 200 : $r['status'] === 404,
        "got status {$r['status']}",
        $failures, $passed
    );
}

$r = kbTestRequest($runner, $root, 'client/kb_embed.php', ['id' => 1], $session_contact_b);
kbAssert(
    'Contact B denied Client A embed (id=1) via client/kb_embed.php',
    $r['status'] === 404,
    "expected 404, got {$r['status']} stderr=" . substr((string) ($r['stderr'] ?? ''), 0, 400),
    $failures, $passed
);

$r = kbTestRequest($runner, $root, 'client/kb_embed.php', ['id' => 3], $session_contact_a);
kbAssert(
    'Contact A denied Client B embed (id=3) via client/kb_embed.php',
    $r['status'] === 404,
    "expected 404, got {$r['status']}",
    $failures, $passed
);

// ---------------------------------------------------------------------------
// Client portal: legitimate access (own client's media, and the global article's media)
// ---------------------------------------------------------------------------

$r = kbTestRequest($runner, $root, 'client/kb_media.php', ['att' => 1], $session_contact_a);
kbAssert(
    'Contact A allowed own Client A attachment (att=1)',
    $r['status'] === 200,
    "expected 200, got {$r['status']} body=" . substr((string) $r['body'], 0, 200),
    $failures, $passed
);

$r = kbTestRequest($runner, $root, 'client/kb_media.php', ['att' => 2], $session_contact_b);
kbAssert(
    'Contact B allowed the GLOBAL attachment (att=2)',
    $r['status'] === 200,
    "expected 200, got {$r['status']}",
    $failures, $passed
);

$r = kbTestRequest($runner, $root, 'client/kb_embed.php', ['id' => 2], $session_contact_b);
kbAssert(
    'Contact B allowed the GLOBAL embed (id=2)',
    $r['status'] === 200 && str_contains((string) $r['body'], 'global embed'),
    "expected 200 with body, got {$r['status']}",
    $failures, $passed
);

// ---------------------------------------------------------------------------
// Agent/technician side: Tech A (restricted to Client A) denied Client B's media/embeds
// ---------------------------------------------------------------------------

$r = kbTestRequest($runner, $root, 'agent/kb_media.php', ['att' => 3], $session_tech_a);
kbAssert(
    'Tech A (restricted to Client A) denied Client B attachment (att=3) via agent/kb_media.php',
    $r['status'] === 403,
    "expected 403, got {$r['status']} body=" . substr((string) $r['body'], 0, 200) . " stderr=" . substr((string) ($r['stderr'] ?? ''), 0, 400),
    $failures, $passed
);

$r = kbTestRequest($runner, $root, 'agent/kb_embed.php', ['id' => 3], $session_tech_a);
kbAssert(
    'Tech A denied Client B embed (id=3) via agent/kb_embed.php',
    $r['status'] === 404,
    "expected 404, got {$r['status']}",
    $failures, $passed
);

// Tech A's OWN client (A) and the global article must still work - the restriction must not be stricter than
// enforceClientAccess() itself.
$r = kbTestRequest($runner, $root, 'agent/kb_media.php', ['att' => 1], $session_tech_a);
kbAssert(
    'Tech A allowed own Client A attachment (att=1)',
    $r['status'] === 200,
    "expected 200, got {$r['status']}",
    $failures, $passed
);

$r = kbTestRequest($runner, $root, 'agent/kb_media.php', ['att' => 2], $session_tech_a);
kbAssert(
    'Tech A allowed the GLOBAL attachment (att=2)',
    $r['status'] === 200,
    "expected 200, got {$r['status']}",
    $failures, $passed
);

// ---------------------------------------------------------------------------
// Agent/technician side: MSP admin (no department restriction) reaches every client
// ---------------------------------------------------------------------------

foreach ([1, 2, 3] as $att_id) {
    $r = kbTestRequest($runner, $root, 'agent/kb_media.php', ['att' => $att_id], $session_admin_tech);
    kbAssert(
        "Admin tech allowed attachment att=$att_id (cross-client visibility)",
        $r['status'] === 200,
        "expected 200, got {$r['status']}",
        $failures, $passed
    );
}

foreach ([1, 2, 3] as $embed_id) {
    $r = kbTestRequest($runner, $root, 'agent/kb_embed.php', ['id' => $embed_id], $session_admin_tech);
    kbAssert(
        "Admin tech allowed embed id=$embed_id (cross-client visibility)",
        $r['status'] === 200,
        "expected 200, got {$r['status']}",
        $failures, $passed
    );
}

// ---------------------------------------------------------------------------
// Unauthenticated: must not reach a single byte either lane.
// ---------------------------------------------------------------------------

$r = kbTestRequest($runner, $root, 'client/kb_media.php', ['att' => 2], []);
kbAssert(
    'Unauthenticated request to client/kb_media.php is refused (no session => redirected/killed, never 200)',
    $r['status'] !== 200,
    "expected non-200, got {$r['status']}",
    $failures, $passed
);

echo "\n$passed passed, " . count($failures) . " failed.\n";
if ($failures) {
    echo "\nFAILURES:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
    exit(1);
}
exit(0);
