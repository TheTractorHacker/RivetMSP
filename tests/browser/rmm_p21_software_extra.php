<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fixtures for a browser look at software deployment (RivetCore 1.0.0-rc.21), run on the same throwaway install after tests/browser/rmm_seed.php:
 *
 *   php tests/browser/rmm_p21_software_extra.php <seed.json> setup     switch the `software_deploy` switch on through RmmAdmin, let WIN1 (Client A) and LNX1 (Client B) announce the software
 *                                                                       capabilities, and give user 10 (a technician with RMM scripts level 3, who is NOT the administrator) the login
 *                                                                       tech@scratch.test / Scratch-Tech-1234 and user 16 (limited to Client B) clientb@scratch.test / Scratch-Clientb-1234
 *   php tests/browser/rmm_p21_software_extra.php <seed.json> answer    write what WIN1 "answered" to a detection (the package is not installed) straight into the package tables
 *                                                                       (the agent side is Core's own proof) and report the jobs the browser queued
 *   php tests/browser/rmm_p21_software_extra.php <seed.json> clear     remove the queued software jobs of WIN1 (as if the device had finished them)
 *
 * Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$S = json_decode((string) file_get_contents($argv[1] ?? ''), true);
$mode = $argv[2] ?? 'setup';
if (!is_array($S)) { fwrite(STDERR, "seed json missing\n"); exit(1); }
$win = (int) $S['dev']['WIN1']; $lnx = (int) $S['dev']['LNX1'];
if ($mode === 'setup') {
    $rmm = rivetRmmModule($db);
    $feat = $rmm->settings()->features();
    $feat['software_deploy'] = true;
    $r = $rmm->admin()->saveSettings(new RmmPrincipal(1, 'admin'), ['features_json' => $feat]);
    if (!$r->ok) { fwrite(STDERR, 'could not switch software deployment on: ' . $r->message . "\n"); exit(1); }
    foreach ([[$win, 'windows'], [$lnx, 'linux']] as [$d, $plat]) {
        $q("INSERT INTO rmm_device_state SET device_id=$d, platform='$plat', capabilities_json='[\"software:detect\",\"software:install\"]' ON DUPLICATE KEY UPDATE capabilities_json=VALUES(capabilities_json)");
    }
    foreach ([[10, 'tech@scratch.test', 'Scratch Tech', 'Scratch-Tech-1234'], [16, 'clientb@scratch.test', 'Scratch ClientB', 'Scratch-Clientb-1234']] as [$uid, $mail, $name, $pw]) {
        $q("UPDATE users SET user_email='$mail', user_name='$name', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=$uid");
        $q("INSERT IGNORE INTO user_settings (user_id) VALUES ($uid)");
    }
    echo json_encode(['ok' => true, 'win' => $win, 'lnx' => $lnx]) . "\n";
    exit(0);
}
if ($mode === 'answer') {
    $now = gmdate('Y-m-d H:i:s');
    $q("UPDATE rmm_package_installs SET installed=0, installed_version='', satisfied=0, detected_at='$now' WHERE device_id=$win");
    $jobs = [];
    foreach ($rows("SELECT type, params_json FROM endpoint_agent_jobs WHERE device_id=$win AND type IN ('software_detect','software_install') ORDER BY created_at") as $j) {
        $p = json_decode((string) $j['params_json'], true) ?: [];
        $jobs[] = $j['type'] . ':' . ($p['mode'] ?? '') . ':' . ($p['fmt'] ?? '');
    }
    echo json_encode(['ok' => true, 'jobs' => $jobs, 'expected' => (int) $one("SELECT COUNT(*) FROM rmm_package_installs WHERE device_id=$win AND expected_action IS NOT NULL")]) . "\n";
    exit(0);
}
if ($mode === 'clear') {   // the device "finished" its queued software jobs (one install job at a time is allowed per device)
    $q("DELETE FROM endpoint_agent_jobs WHERE device_id=$win AND type IN ('software_detect','software_install')");
    $q("DELETE FROM rmm_package_jobs WHERE device_id=$win");
    echo json_encode(['ok' => true]) . "\n";
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(1);
