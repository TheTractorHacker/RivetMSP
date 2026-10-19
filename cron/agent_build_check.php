<?php

/*
 * RivetMSP 26.10.31 - follow an agent build started from Administration > Endpoint agent > Agent updates > "Build a new version".
 *
 * Does something only while a build is running (or a finished one still has to be published automatically); otherwise it exits at once without touching the network.
 * It asks GitHub how the run is doing, refreshes the release list when the run finished, and publishes the release when "publish automatically when built" was ticked,
 * as the administrator who started the build (the card's publish rules and permissions apply). The card does the same while someone has the page open.
 *
 * Schedule: /etc/cron.d/itflow-<domain>   every 5 minutes, www-data /usr/bin/php <app>/cron/agent_build_check.php >> /var/log/itflow-agent-build-cron.log 2>&1
 * (the log file must exist and be writable by www-data). Must run as www-data. Prints one line when it did something.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('agent_build_check', 900);
require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__) . '/includes/rmm_agent_build.php';

mysqli_report(MYSQLI_REPORT_OFF);

$feed = new \RivetMsp\AgentFeed\Feed($mysqli);
if (!$feed->ready()) {
    exit(0);
}
$bld = new \RivetMsp\AgentFeed\Build($feed, static function (string $a, string $t): void {
    logAction('Endpoint Agent', $a, $t . ' (cron)');
});
$pending = $bld->pending();
if ($pending === null) {
    exit(0);
}
$rmm = rivetRmmModule($mysqli);
$publisher = null;
if ($pending['by_id'] > 0) {
    $publisher = \RivetMsp\AgentFeed\rivetAgentBuildPublisher($rmm, $feed, $rmm->admin(), rivetRmmPrincipal($pending['by_id'], $pending['by']), $pending['by_id'], $pending['by']);
}
$d = $bld->poll($publisher);
echo date('Y-m-d H:i:s') . ' agent build ' . ($d['version'] ?? '?') . ': ' . ($d['state'] ?? '?') . (!empty($d['published']) ? ', published' : '') . "\n";
