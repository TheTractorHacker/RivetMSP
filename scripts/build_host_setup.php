#!/usr/bin/env php
<?php

// Used by deploy/setup-build-host.sh (run as the web server user): create / rotate / delete the build host in this application and report the site URL.
//   sudo -u www-data php scripts/build_host_setup.php info   --name NAME
//   sudo -u www-data php scripts/build_host_setup.php create --name NAME [--rotate]     (prints the token on stdout, nothing else: capture it, never log it)
//   sudo -u www-data php scripts/build_host_setup.php delete --name NAME
// Not meant to be typed by hand; the Agent updates page shows the one-line command that runs deploy/setup-build-host.sh.
chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once "../config.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";
require_once "../includes/rmm_bootstrap.php";
require_once "../includes/rmm_agent_localbuild.php";
require_once "../includes/rmm_agent_buildhost_setup.php";

mysqli_report(MYSQLI_REPORT_OFF);
$session_user_id = 0;
$session_ip = '127.0.0.1';
$session_user_agent = 'build_host_setup.php';
$lb = \RivetMsp\AgentFeed\rivetLocalBuild($mysqli, rivetRmmModule($mysqli));
$base = '';
try {
    $base = (string) (rivetRmmModule($mysqli)->readModel()->settingsSummary()['service_base'] ?? '');
} catch (\Throwable) {
    $base = '';
}
if ($base === '' && !empty($config_base_url)) {
    $base = 'https://' . $config_base_url;
}
$res = \RivetMsp\AgentFeed\rivetBuildHostSetupRun($lb, $base, array_slice($argv, 1));
fwrite(STDOUT, $res['out']);
fwrite(STDERR, $res['err']);
exit($res['code']);
