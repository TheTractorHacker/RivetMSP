<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Hypervisor visibility (RivetCore 1.0.0-rc.29, migration 0036, Rmm\Hypervisor\*): the fleet-wide hosts page (agent/rmm_hypervisors.php), one host's
 * storage and VM table (agent/rmm_hypervisor_host.php), one VM's facts/alerts/history and "Ignore this VM" (agent/rmm_hypervisor_vm.php), "Ignore this
 * host", the rmm.hypervisor.view/manage ability split, and the switched-off notice. Same scratch rules and environment as tests/endpoint_agent_lib.php.
 *
 * What this file does NOT test: the agent's collection or the signed check-in ingest of a `hypervisor` report (that is RivetCore's own Go/PHP suite,
 * HYPERVISORS.md section 10). Host, VM, storage and history rows are inserted directly into Core's tables to stand in for a real Proxmox VE or Hyper-V
 * report; this only proves the edition reads and renders what Core already validated and stored, and that the manage actions reach
 * HypervisorActions correctly. NEVER RUN THESE PAGES AGAINST A REAL HYPERVISOR HOST without reading HYPERVISORS.md section 9 first.
 */
$sd = sys_get_temp_dir() . '/rmm_hv_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
use RivetCore\Rmm\Authz\RmmPrincipal;

register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
ea_reset();
$tokens = ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$q("UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
$ok($rmm->admin()->enable($admin)->ok && $rmm->enabled(), 'the module is on');
$f = $rmm->settings()->features();
$f['hypervisor'] = true;
$ok($rmm->admin()->saveSettings($admin, ['features_json' => $f])->ok, 'the hypervisor switch is on');
$rmm->syncState();

$sdir = sys_get_temp_dir() . '/rmm_hv_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$ok($rmm->admin()->saveSettings($admin, ['service_url' => $wb])->ok, 'service url');
$sidAdmin = ea_forge_session($sdir, 1);     // role 1, admin: rmm.hypervisor.view and .manage
$sidTech = ea_forge_session($sdir, 10);     // role 2, module_rmm level 3: view and manage
$sidViewer = ea_forge_session($sdir, 12);   // role 4, module_rmm level 1: view only, no manage
$sidStock = ea_forge_session($sdir, 14);    // role 6, no rmm permission row at all: no access

echo "\n--- publish a Windows agent binary (deploymentCommands() needs one published) and enroll a device ---\n";
function hv_pe(int $machine, int $size, string $fill): string
{
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);

    return $b . substr(str_repeat($chunk, intdiv($size - strlen($b), 32) + 1), 0, $size - strlen($b));
}
$peTmp = sys_get_temp_dir() . '/hv_pe_' . bin2hex(random_bytes(3));
file_put_contents($peTmp, hv_pe(0x8664, 1048576, 'amd64'));
$peR = $rmm->admin()->uploadBinary($admin, $peTmp, '1.0.0', 'amd64', ['activate' => true]);
@unlink($peTmp);
$ok($peR->ok, 'a Windows amd64 binary is published: ' . $peR->message);

$XHR = ['X-Requested-With: fetch', 'Accept: application/json'];
$post = fn (array $f) => web($wb, 'POST', '/agent/post/rmm_installer.php', $sidAdmin, $f + ['csrf_token' => 'csrftok1'], $XHR);
[$c, $b] = $post(['os' => 'linux', 'mode' => 'commands', 'client_id' => 1, 'arch' => 'amd64']);
$j = json_decode($b, true);
preg_match('/rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) ($j['linux'] ?? ''), $mt);
$enrollToken = $mt[0] ?? null;
$ok($c === 200 && $enrollToken !== null, 'issued an enrollment token');
[$c2, , $jr] = ea_enroll($enrollToken, ea_dev(['hostname' => 'PVE1', 'os' => 'linux', 'os_version' => 'Debian 12']));
$ok($c2 === 201, 'the hypervisor host device enrolls (' . $c2 . '): ' . json_encode($jr));
$devId = (int) ($jr['device_id'] ?? 0);

echo "\n--- fixture: a Proxmox host with a cluster, one running VM, one stopped VM with a failed backup, and one storage at warning level ---\n";
// Standing in for a real Proxmox VE/Hyper-V report (RivetCore's own ingest tests cover the check-in path): HYPERVISORS.md section 9 still applies.
$q("INSERT INTO rmm_hypervisor_hosts SET device_id = $devId, platform = 'proxmox', version = '8.1.4', cluster_name = 'pve-cluster', quorate = 1,
    cluster_nodes_json = '[\"pve1\",\"pve2\"]', failed_groups = 0, vm_count = 2, truncated = 0, cfg_version = '1',
    last_collected_at = NOW(), last_full_at = NOW(), last_report_at = NOW(), error = '', ignored = 0, updated_at = NOW()");
$q("INSERT INTO rmm_hypervisor_vms SET device_id = $devId, vm_key = 'qemu/100', name = 'web01', type = 'qemu', state = 'running', state_changed_at = NOW(), node = 'pve1',
    cpus = 4, cpu_pct = 12.5, mem_bytes = 2147483648, mem_max_bytes = 4294967296, disk_bytes = 34359738368, disk_used_bytes = 12884901888, uptime_s = 86400,
    template = 0, tags = 'prod,web', snapshot_count = 2, oldest_snapshot_at = DATE_SUB(NOW(), INTERVAL 3 DAY), last_backup_at = NOW(), last_backup_result = 'ok',
    backup_covered = 1, guest_agent = 1, replication = '', first_seen_at = NOW(), last_seen_at = NOW()");
$q("INSERT INTO rmm_hypervisor_vms SET device_id = $devId, vm_key = 'lxc/101', name = 'db01', type = 'lxc', state = 'stopped', state_changed_at = NOW(), node = 'pve1',
    cpus = 2, mem_bytes = NULL, mem_max_bytes = 2147483648, disk_bytes = 10737418240, uptime_s = NULL, template = 0, tags = '', snapshot_count = 0,
    last_backup_at = DATE_SUB(NOW(), INTERVAL 5 DAY), last_backup_result = 'failed', backup_covered = 1, guest_agent = 0, replication = '',
    first_seen_at = NOW(), last_seen_at = NOW()");
$vmWeb = (int) $one("SELECT vm_id FROM rmm_hypervisor_vms WHERE device_id = $devId AND vm_key = 'qemu/100'");
$vmDb = (int) $one("SELECT vm_id FROM rmm_hypervisor_vms WHERE device_id = $devId AND vm_key = 'lxc/101'");
$q("INSERT INTO rmm_hypervisor_vm_history SET vm_id = $vmDb, device_id = $devId, at = DATE_SUB(NOW(), INTERVAL 1 HOUR), from_state = 'running', to_state = 'stopped'");
$q("INSERT INTO rmm_hypervisor_storage SET device_id = $devId, name = 'local-zfs', type = 'zfspool', total_bytes = 1099511627776, used_bytes = 989560651776, health = 'warn', shared = 0, last_seen_at = NOW()");
$ok($vmWeb > 0 && $vmDb > 0, 'fixture VMs inserted (web01 #' . $vmWeb . ', db01 #' . $vmDb . ')');

echo "\n--- the hosts page ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisors.php', $sidAdmin);
$ok($c === 200 && str_contains($b, 'PVE1') && str_contains($b, 'Proxmox VE') && str_contains($b, '8.1.4') && str_contains($b, 'pve-cluster'), 'the hosts page lists the host with its platform and cluster');
$ok(str_contains($b, '/agent/rmm_hypervisor_host.php?device_id=' . $devId), 'it links to the host detail page');

echo "\n--- the host detail page ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisor_host.php?device_id=' . $devId, $sidAdmin);
$ok($c === 200 && str_contains($b, 'local-zfs') && str_contains($b, 'web01') && str_contains($b, 'db01'), 'the host page shows its storage and both VMs');
$ok(str_contains($b, '/agent/rmm_hypervisor_vm.php?vm_id=' . $vmWeb), 'it links to a VM detail page');
$ok(str_contains($b, 'Failed') || str_contains($b, 'failed'), 'the stopped VM\'s failed backup is shown');

echo "\n--- the VM detail page ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisor_vm.php?vm_id=' . $vmDb, $sidAdmin);
$ok($c === 200 && str_contains($b, 'db01') && str_contains($b, 'pve1'), 'the VM page shows the VM\'s name and node');
$ok(str_contains($b, 'State history') && str_contains($b, 'Running') && str_contains($b, 'Stopped'), 'the state history row is shown');
$ok(str_contains($b, 'Ignore this VM'), 'an admin (manage) sees the ignore control');

echo "\n--- a viewer (view only, no manage) sees no ignore control and is refused the action ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisor_vm.php?vm_id=' . $vmDb, $sidViewer);
$ok($c === 200 && str_contains($b, 'db01') && !str_contains($b, 'Ignore this VM'), 'a viewer can read the VM but has no ignore button');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_hypervisor.php', $sidViewer, ['csrf_token' => 'csrftok1', 'action' => 'ignore_vm', 'vm_id' => $vmDb], $XHR);
$j = json_decode($b, true);
$ok($c === 403 && ($j['success'] ?? true) === false, 'a viewer is refused the ignore action (' . $c . ')');

echo "\n--- a stock technician (no rmm.hypervisor.view) is refused the page entirely ---\n";
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisors.php', $sidStock);
$ok($c === 403, 'a user with no RMM module access gets 403 (' . $c . ')');

echo "\n--- ignore this VM, then resume ---\n";
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_hypervisor.php', $sidTech, ['csrf_token' => 'csrftok1', 'action' => 'ignore_vm', 'vm_id' => $vmDb, 'reason' => 'noisy test VM'], $XHR);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false), 'a tech (manage) can ignore the VM: ' . $b);
$row = $rows("SELECT ignored, ignored_reason FROM rmm_hypervisor_vms WHERE vm_id = $vmDb")[0] ?? null;
$ok($row !== null && (int) $row['ignored'] === 1 && $row['ignored_reason'] === 'noisy test VM', 'the VM is ignored with its reason stored');
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisor_vm.php?vm_id=' . $vmDb, $sidAdmin);
$ok($c === 200 && str_contains($b, 'ignored') && str_contains($b, 'noisy test VM') && str_contains($b, 'Resume alerting'), 'the VM page now shows it ignored, its reason, and a resume control');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_hypervisor.php', $sidTech, ['csrf_token' => 'csrftok1', 'action' => 'unignore_vm', 'vm_id' => $vmDb], $XHR);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false), 'resuming alerting succeeds: ' . $b);
$ok((int) $one("SELECT ignored FROM rmm_hypervisor_vms WHERE vm_id = $vmDb") === 0, 'the VM is no longer ignored');

echo "\n--- ignore this host (its VMs are silenced too), then resume monitoring ---\n";
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_hypervisor.php', $sidTech, ['csrf_token' => 'csrftok1', 'action' => 'ignore_host', 'device_id' => $devId], $XHR);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false), 'a tech can ignore the host: ' . $b);
$ok((int) $one("SELECT ignored FROM rmm_hypervisor_hosts WHERE device_id = $devId") === 1, 'the host is ignored');
$ok((int) $one("SELECT COUNT(*) FROM rmm_hypervisor_vms WHERE device_id = $devId AND ignored = 1") === 2, 'ignoring the host silenced both its VMs too');
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisor_host.php?device_id=' . $devId, $sidAdmin);
$ok($c === 200 && str_contains($b, 'Resume monitoring'), 'the host page shows a resume control once ignored');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_hypervisor.php', $sidTech, ['csrf_token' => 'csrftok1', 'action' => 'unignore_host', 'device_id' => $devId], $XHR);
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false), 'resuming host monitoring succeeds: ' . $b);
$ok((int) $one("SELECT ignored FROM rmm_hypervisor_hosts WHERE device_id = $devId") === 0, 'the host is no longer ignored');

echo "\n--- switched off: the pages refuse, nothing is read ---\n";
$f['hypervisor'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $f]);
$rmm->syncState();
[$c, $b] = web($wb, 'GET', '/agent/rmm_hypervisors.php', $sidAdmin);
$ok($c === 403 && str_contains($b, 'switched off'), 'the hosts page refuses with the standard switched-off notice (' . $c . ')');
