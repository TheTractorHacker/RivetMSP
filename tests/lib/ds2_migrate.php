<?php
// Helpers for the msp_ds2_* DB tests (group A, DB 2.6.138). Needs the scratch DB from tests/lib/with_scratch_db.sh with these tables loaded:
//   SCRATCH_TABLES="settings users tickets invoices quotes ticket_replies logs"
// ds2_open() connects; ds2_downgrade() turns the scratch schema back into the 2.6.137 shape (what an upgrading install has);
// ds2_migrate() runs the REAL admin/database_updates.php 2.6.137 -> 2.6.138 step in a child process.
if (getenv('RIVETMSP_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) { fwrite(STDERR, "scratch DB only\n"); exit(2); }
$GLOBALS['ds2_fail'] = 0;
function ds2_t(string $name, bool $cond): void { echo ($cond ? 'ok   ' : 'FAIL ') . $name . "\n"; if (!$cond) { $GLOBALS['ds2_fail'] = 1; } }
function ds2_done(): void { exit($GLOBALS['ds2_fail']); }
function ds2_open(): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $m->query("SET SESSION sql_mode=''");
    return $m;
}
function ds2_one(mysqli $m, string $sql)
{
    $r = $m->query($sql);
    $x = $r ? $r->fetch_row() : null;
    return $x ? $x[0] : null;
}
function ds2_downgrade(mysqli $m): void
{
    foreach (['tickets' => 'uniq_tickets_prefix_number', 'invoices' => 'uniq_invoices_prefix_number', 'quotes' => 'uniq_quotes_prefix_number'] as $t => $i) {
        $m->query("ALTER TABLE `$t` DROP INDEX `$i`");
    }
    $m->query("ALTER TABLE users DROP INDEX uniq_users_staff_email, DROP INDEX idx_users_calendar_token");
    $m->query("ALTER TABLE users DROP COLUMN user_staff_email_key, DROP COLUMN user_calendar_token_hash, DROP COLUMN user_calendar_token_rotated_at, DROP COLUMN user_calendar_legacy_until");
    $m->query("DELETE FROM settings");
    $m->query("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.137', config_ticket_next_number = 1, config_invoice_next_number = 1, config_quote_next_number = 1");
}
/** Runs the migration; returns the child's combined output. */
function ds2_migrate(): string
{
    $code = '$root = ' . var_export(dirname(__DIR__, 2), true) . ';'
        . 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);'
        . '$mysqli = new mysqli("localhost", getenv("RIVETMSP_TEST_DB_USER"), getenv("RIVETMSP_TEST_DB_PASS"), getenv("RIVETMSP_TEST_DB_NAME"));'
        . '$mysqli->query("SET SESSION sql_mode=\'\'");'
        . 'define("LATEST_DATABASE_VERSION", "2.6.138"); define("CURRENT_DATABASE_VERSION", "2.6.137");'
        // Stop before the 2.6.138 -> 2.6.139 step: later groups' steps need tables this test does not load.
        . '$src = file_get_contents($root . "/admin/database_updates.php"); $cut = strpos($src, "if (\\$rivetit_db_version() == \'2.6.138\')");'
        . 'if ($cut === false) { echo "CUT_MARKER_MISSING\n"; exit(3); }'
        . '$tmp = $root . "/admin/.ds2_migration_tmp.php"; file_put_contents($tmp, substr($src, 0, $cut)); try { require $tmp; } finally { @unlink($tmp); }'
        . 'echo "MIGRATION_DONE\n";';
    $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return $out;
}
