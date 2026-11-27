<?php
// Review R9: list pages that decrypt a credential per row must degrade (masked rows + notice) instead of dying mid-table when the reveal limit is hit,
// while the limit still counts per credential. Run:
//   SCRATCH_TABLES="logs users security_settings" tests/lib/with_scratch_db.sh php tests/msp_ds_review_R9_vault_row_soft.php
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
$session_user_id = 2; $session_name = 'u2'; $session_is_admin = 0; $_SESSION = [];
$m->query("DELETE FROM logs");
$limit = secSettingInt('vault_reveal_limit', $m);
// a "page" with limit+12 favourite credentials: every row call returns, none exits
$allowed = 0; $masked = 0;
for ($i = 0; $i < $limit + 12; $i++) { if (vaultDisclosureRow($m, 'client overview')) { $allowed++; } else { $masked++; } }
$ok("the loop completes (no exit); first $limit rows allowed, the rest masked ($allowed/$masked)", $allowed === $limit && $masked === 12);
$n = (int) $m->query("SELECT COUNT(*) FROM logs WHERE log_type='Credential' AND log_action='Reveal'")->fetch_row()[0];
$ok("the limit still counts per credential ($n reveal lines for $limit rows)", $n === $limit);
$n = (int) $m->query("SELECT COUNT(*) FROM logs WHERE log_type='Credential' AND log_action='Reveal Limit'")->fetch_row()[0];
$ok("only the first refusal in a request is logged ($n)", $n === 1);
// a fresh request (flag reset) is still refused while the window is full: the old bypass was NOT restored
unset($GLOBALS['vault_row_refused']);
$ok('a second page load in the same window is still refused', vaultDisclosureRow($m, 'client overview') === false);
// the hard gate is unchanged for single-credential paths
$ok('vaultDisclosureCheck still refuses with a sentence once the limit is used', is_string(vaultDisclosureCheck($m, 'credential edit form')));
// source: the soft variant exists and every list page uses it
$root = dirname(__DIR__);
foreach (['agent/global_search.php', 'agent/asset_details.php', 'agent/contact_details.php', 'agent/client_overview.php', 'agent/modals/asset/asset_details.php', 'agent/modals/contact/contact_details.php'] as $f) {
    $s = file_get_contents("$root/$f");
    $ok("$f: row loop uses vaultGateRow() and prints a masked row, not vaultGate()", str_contains($s, 'vaultGateRow(') && str_contains($s, 'vaultRowDeniedHtml(') && !preg_match('/vaultGate\(/', $s));
}
$fs = file_get_contents("$root/functions.php");
$ok('vaultGateRow() never calls denyAccess/exit', preg_match('/function vaultGateRow.*?\n}\n/s', $fs, $mm) && !str_contains($mm[0], 'denyAccess') && !str_contains($mm[0], 'exit'));
exit($fail ? 1 : 0);
