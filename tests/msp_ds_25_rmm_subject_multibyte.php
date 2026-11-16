<?php
// MSP-DS-25: an RMM alert subject cut at 200 characters never splits a UTF-8 character. Run: SCRATCH_TABLES="tickets" tests/lib/with_scratch_db.sh php tests/msp_ds_25_rmm_subject_multibyte.php
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
$m->set_charset('utf8mb4');
$m->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(__DIR__ . '/../includes/rmm_functions.php');
preg_match('/^\s*\$subject\s*= (\'RMM Alert: \' \. [^;]+);/m', $src, $e);
$ok('subject expression found', !empty($e[1]));
$try = function (string $msg) use ($m, $e): array {
    $alert = ['message' => $msg];
    $subject = eval('return ' . $e[1] . ';');
    $esc = $m->real_escape_string($subject);
    $r = $m->query("INSERT INTO tickets SET ticket_prefix='T', ticket_number=" . ($GLOBALS['ds25_n'] = ($GLOBALS['ds25_n'] ?? 0) + 1) . ", ticket_subject='$esc', ticket_details='x', ticket_status=1, ticket_priority='Low', ticket_url_key='k', ticket_client_id=0, ticket_created_by=0, ticket_assigned_to=0");
    return [$subject, $r === true ? '' : $m->error];
};
foreach (['accent e-acute' => 'é', 'cyrillic' => 'Ж', 'cjk' => '漢', 'emoji' => '😀'] as $label => $ch) {
    for ($pad = 180; $pad <= 192; $pad++) {   // one of these puts a multibyte character across byte 200
        [$subj, $err] = $try(str_repeat('A', $pad) . str_repeat($ch, 40));
        if ($err !== '') { $ok("$label pad $pad inserts: $err", false); continue 2; }
        if (!mb_check_encoding($subj, 'UTF-8')) { $ok("$label pad $pad valid UTF-8", false); continue 2; }
    }
    $ok("$label: every cut position inserts under strict mode", true);
}
[$subj, $err] = $try(str_repeat('A', 199) . 'é' . 'tail');
$ok('the exact straddle case from the report inserts', $err === '');
[$subj, ] = $try(str_repeat('é', 300));
$ok('cut is 200 characters (11-char prefix + 200)', mb_strlen($subj, 'UTF-8') === 11 + 200);
[$subj, $err] = $try("bad \xC3 byte");
$ok('invalid UTF-8 input is scrubbed, not thrown at the INSERT', $err === '');
$cron = file_get_contents(__DIR__ . '/../cron/cron.php');
$ok('cron wraps the per-alert auto-ticketing in try/catch', preg_match('/try \{\s*createTicketFromRmmAlert\(/', $cron) === 1);
exit($fail ? 1 : 0);
