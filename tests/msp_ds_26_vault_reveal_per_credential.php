<?php
// MSP-DS-26: a page that decrypts N credentials counts N reveals (it used to count 1 per page). Run:
//   SCRATCH_TABLES="logs users security_settings" tests/lib/with_scratch_db.sh php tests/msp_ds_26_vault_reveal_per_credential.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME')); $mysqli = $m;
$m->query("SET SESSION sql_mode=''");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
function nullable_htmlentities($s) { return $s; }
function logAction($type, $action, $desc, $client = 0, $entity = 0) {
    global $m, $session_user_id; $t = $m->real_escape_string($type); $a = $m->real_escape_string($action); $d = $m->real_escape_string($desc);
    $m->query("INSERT INTO logs (log_type, log_action, log_description, log_user_id, log_created_at) VALUES ('$t','$a','$d'," . (int) $session_user_id . ", NOW())");
}
require dirname(__DIR__) . '/includes/security_policy.php';
require dirname(__DIR__) . '/includes/vault_reveal.php';
$session_user_id = 2; $session_name = 'u2'; $session_is_admin = 0; $_SESSION = [];
$m->query("DELETE FROM logs");
// one page showing 4 favourite credentials = 4 gate calls
$results = []; for ($i = 0; $i < 4; $i++) { $results[] = vaultDisclosureCheck($m, 'client overview'); }
$n = (int) $m->query("SELECT COUNT(*) FROM logs WHERE log_type='Credential' AND log_action='Reveal' AND log_user_id=2")->fetch_row()[0];
$ok("4 credentials on one page are 4 counted reveals ($n)", $n === 4 && $results === [null, null, null, null]);
// a bulk disclosure of 7 writes 7 lines
$_SESSION['vault_stepup_at'] = time(); $m->query("DELETE FROM logs");
vaultDisclosureCheck($m, 'credential export', 7, true);
$n = (int) $m->query("SELECT COUNT(*) FROM logs WHERE log_type='Credential' AND log_action='Reveal'")->fetch_row()[0];
$ok("a bulk disclosure of 7 values writes 7 lines ($n)", $n === 7);
// the limit now bites on credentials, not pages: 40 loop calls on one path get refused after the limit
$m->query("DELETE FROM logs"); $limit = secSettingInt('vault_reveal_limit', $m);
$allowed = 0; for ($i = 0; $i < $limit + 10; $i++) { if (vaultDisclosureCheck($m, 'client overview') === null) { $allowed++; } }
$ok("one path in one request is capped at the reveal limit ($allowed of " . ($limit + 10) . ", limit $limit)", $allowed === $limit);
exit($fail ? 1 : 0);
