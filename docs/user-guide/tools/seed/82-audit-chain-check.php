<?php
/*
 * Group 13 (Access and compliance), seed 82: runs the audit-trail hash-chain check once, so Administration > Audit trail shows a
 * result ("Intact") instead of "Not checked yet". Safe to repeat. Needs seed 80/81 only for company (events to check).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/82-audit-chain-check.php
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "82-audit-chain-check: no config.php in '$app'.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../vendor/autoload.php';
try {
    $v = \RivetMSP\Platform\Nightly::verifyAuditChain($mysqli);
    echo "82-audit-chain-check: " . ($v['status'] ?? '?') . "\n";
} catch (\Throwable $e) {
    echo "82-audit-chain-check: skipped (" . $e->getMessage() . ")\n";
}
