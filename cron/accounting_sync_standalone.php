<?php
/*
 * ITFlow beta - standalone entry point for the accounting (QuickBooks) sync
 * worker, scheduled independently via /etc/cron.d/itflow-beta.
 *
 * Unlike production, beta has no cron schedule wired to the full cron/cron.php
 * bundle (RMM sync, backups, automation rules, etc.) - only this narrower,
 * lower-risk job runs automatically here: it pushes invoices/payments/quotes
 * that a human already marked Sent/finalized to the connected QuickBooks
 * company. See cron/accounting_sync.php for the actual worker logic.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

chdir(__DIR__);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('accounting_sync_standalone', 1800);

require_once __DIR__ . '/accounting_sync.php';
