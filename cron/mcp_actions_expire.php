<?php
/*
 * mcp_actions_expire.php - expire stale MCP write proposals (GitHub #28)
 *
 * A proposal an MCP client queued (mcp_pending_actions) is only approvable until its expires_at. Approval itself refuses an
 * overdue row, so this job is housekeeping: it flips overdue 'pending' rows to 'expired' (audited) and fails an 'approved'
 * claim that never finished. It changes no ticket or other business data and is a no-op while the table does not exist.
 *
 * WIRING: required from cron/cron.php on its normal cadence. Also runnable standalone from the CLI:  php cron/mcp_actions_expire.php
 */

$mcp_expire_standalone = !isset($mysqli);

if ($mcp_expire_standalone) {
    chdir(dirname(__FILE__));

    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line.\n");
    }

    require_once "../config.php";
    require_once "../includes/inc_set_timezone.php";
    require_once "../functions.php";
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    // Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
    require_once dirname(__DIR__) . '/includes/redis_guards.php';
    rivetCronGuard('mcp_actions_expire', 300);
}

$mcp_expired = 0;
if (\RivetMSP\Mcp\WriteConfig::tableReady($mysqli)) {
    $mcp_expired = (new \RivetMSP\Mcp\PendingActions($mysqli))->expireStale();
}

if ($mcp_expire_standalone && php_sapi_name() === 'cli') {
    echo "mcp_actions_expire: expired $mcp_expired pending action(s)\n";
}
