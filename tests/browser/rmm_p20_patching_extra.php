<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fixtures for a browser look at patch management (RivetCore 1.0.0-rc.20), run on the same throwaway install after tests/browser/rmm_seed.php:
 *
 *   php tests/browser/rmm_p20_patching_extra.php <seed.json>   switch the `patching` switch on through RmmAdmin, write scan results for WIN1 (Client A) and LNX1 (Client B)
 *                                                              straight into the patch tables (the agent side is Core's own proof), assign one policy to Client A, and give
 *                                                              user 16 (a technician limited to Client B) the login clientb@scratch.test / Scratch-Clientb-1234
 *
 * Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$S = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (!is_array($S)) { fwrite(STDERR, "seed json missing\n"); exit(1); }
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$feat = $rmm->settings()->features();
$feat['patching'] = true;
$r = $rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
if (!$r->ok) { fwrite(STDERR, 'could not switch patching on: ' . $r->message . "\n"); exit(1); }
$now = gmdate('Y-m-d H:i:s');
$ago = fn (int $d) => gmdate('Y-m-d H:i:s', time() - $d * 86400);
$win = (int) $S['dev']['WIN1']; $lnx = (int) $S['dev']['LNX1'];
$q("DELETE FROM rmm_patch_available"); $q("DELETE FROM rmm_patch_scans"); $q("DELETE FROM rmm_patch_history");
$q("INSERT INTO rmm_patch_scans SET device_id=$win, client_id=1, scanned_at='$now', manager='wua', scan_state='ok', wu_state='running', total_count=4, reboot_pending=1");
$q("INSERT INTO rmm_patch_scans SET device_id=$lnx, client_id=2, scanned_at='$now', manager='apt', scan_state='ok', total_count=1");
$rows = [[$win, 1, '11111111-1111-4111-8111-111111111111', '2026-10 Cumulative Update for Windows 11 23H2 for x64-based Systems', 'KB5044285', 'security', 'available', 31, 1],
    [$win, 1, '22222222-2222-4222-8222-222222222222', 'Security Intelligence Update for Microsoft Defender Antivirus', 'KB2267602', 'critical', 'approved', 3, 0],
    [$win, 1, '33333333-3333-4333-8333-333333333333', 'Intel - Net - 22.250.1.1', '', 'driver', 'declined', 40, 0],
    [$win, 1, '44444444-4444-4444-8444-444444444444', '<b>Hostile</b> "title" & <script>alert(1)</script>', 'KB1', 'optional', 'failed', 12, 0],
    [$lnx, 2, 'openssl', 'openssl 3.0.13 (Ubuntu security)', '', 'security', 'available', 20, 0]];
foreach ($rows as [$d, $c, $id, $t, $kb, $kind, $st, $age, $rb]) {
    $q("INSERT INTO rmm_patch_available SET device_id=$d, client_id=$c, update_id='" . $esc($id) . "', kb='" . $esc($kb) . "', title='" . $esc($t) . "', kind='$kind', severity='important', size_bytes=73400320, reboot_required=$rb,
        status='$st', fail_count=" . ($st === 'failed' ? 2 : 0) . ", first_seen_at='" . $ago($age) . "', last_seen_at='$now'");
}
$q("INSERT INTO rmm_patch_history SET device_id=$win, client_id=1, update_id='22222222-2222-4222-8222-222222222222', title='Security Intelligence Update', action='install', result='installed', detail='', at='" . $ago(3) . "'");
$q("INSERT INTO rmm_device_state SET device_id=$win, platform='windows', capabilities_json='[\"patch:scan\",\"patch:install\"]' ON DUPLICATE KEY UPDATE capabilities_json=VALUES(capabilities_json)");
$p = $rmm->patchActions()->savePolicy($admin, null, ['name' => 'Workstations: pilot first', 'enabled' => true, 'settings' => ['approval_mode' => 'auto', 'ring' => 'pilot', 'auto_days_security' => 3, 'install_enabled' => true, 'window_mode' => 'daily', 'window_days' => 'mon,tue,wed', 'window_start' => '02:00', 'window_minutes' => 120, 'window_tz' => 'UTC']]);
$pid = (int) ($p->data['policy']['policy_id'] ?? 0);
if ($pid > 0) { $rmm->patchActions()->assignPolicy($admin, $pid, ['scope_type' => 'client', 'scope_id' => 1]); }
$rmm->patchActions()->saveRule($admin, null, ['name' => 'Security after 3 days', 'kinds' => ['security', 'critical'], 'after_days' => 3, 'ring' => 'broad']);
$q("UPDATE users SET user_email='clientb@scratch.test', user_name='Scratch ClientB', user_password='" . $db->real_escape_string(password_hash('Scratch-Clientb-1234', PASSWORD_DEFAULT)) . "' WHERE user_id=16");
$q("INSERT IGNORE INTO user_settings (user_id) VALUES (16)");
echo json_encode(['ok' => true, 'policy' => $pid]) . "\n";
