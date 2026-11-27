<?php
// Re-review RR6: credential CSV export and client PDF must call the bulk vaultGate ONCE (full row count) before the row loop;
// a gate per row re-counted the whole export each iteration and died at the reveal limit for exports of 6+ credentials. Run:
//   SCRATCH_TABLES="logs users security_settings" tests/lib/with_scratch_db.sh php tests/msp_ds_review_RR6_bulk_gate_once.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $mysqli = $m;
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
function logAction($type, $action, $desc, $client = 0, $entity = 0) {
    global $m, $session_user_id; $t = $m->real_escape_string($type); $a = $m->real_escape_string($action); $d = $m->real_escape_string($desc);
    $m->query("INSERT INTO logs (log_type, log_action, log_description, log_user_id, log_created_at) VALUES ('$t','$a','$d'," . (int) $session_user_id . ", NOW())");
}
require dirname(__DIR__) . '/includes/security_policy.php';
require dirname(__DIR__) . '/includes/vault_reveal.php';
$session_user_id = 2; $session_name = "u2"; $session_is_admin = 0; $_SESSION = ["vault_stepup_at" => time()];
$root = dirname(__DIR__);

// 1. behaviour of the real gate check: the old per-row pattern dies for N=10, the once-per-request pattern does not
$m->query("DELETE FROM logs"); $N = 10; $refusedAt = null;
for ($i = 1; $i <= $N; $i++) { if (is_string(vaultDisclosureCheck($m, 'credential export', $N, true))) { $refusedAt = $i; break; } }
$ok("old per-row pattern: refused at row " . var_export($refusedAt, true) . " of $N (this is the bug)", $refusedAt !== null);
$m->query("DELETE FROM logs"); unset($GLOBALS['vault_row_refused']);
$ok("new pattern: one bulk check with values=$N is allowed", vaultDisclosureCheck($m, 'credential export', $N, true) === null);
$n = (int) $m->query("SELECT COUNT(*) FROM logs WHERE log_type='Credential' AND log_action='Reveal'")->fetch_row()[0];
$ok("and still counts $N reveals ($n lines)", $n === $N);
$m->query("DELETE FROM logs");


// 2. source: exactly one vaultGate per file path, and it sits before the row loop
foreach ([['agent/post/credential.php', 'credential export', '/while\s*\(\s*\$row\s*=\s*mysqli_fetch_assoc\(\$sql\)\s*\)/'], ['agent/post/client.php', 'client pdf', '/while\s*\(\s*\$row\s*=\s*mysqli_fetch_assoc\(\$sql_credentials\)\s*\)/']] as [$f, $path, $loop]) {
    $s = file_get_contents("$root/$f");
    $c = preg_match_all("/vaultGate\('" . preg_quote($path, '/') . "'/", $s, $mm, PREG_OFFSET_CAPTURE);
    $ok("$f: exactly one vaultGate('$path') call", $c === 1);
    $ok("$f: it sits before the row loop (not inside it)", $c === 1 && preg_match($loop, $s, $lm, PREG_OFFSET_CAPTURE) && $mm[0][0][1] < $lm[0][1]);
    $ok("$f: still a bulk gate with the full row count", (bool) preg_match("/vaultGate\('" . preg_quote($path, '/') . "', max\(1, [^)]*\)\)?, true\)/", $s));
}
exit($fail ? 1 : 0);
