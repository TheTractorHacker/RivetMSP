<?php
/*
 * Modules, permission modules, roles and admin switches for the RivetMSP user-guide demo.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/01-modules-and-permissions.php
 *
 * Idempotent: safe to run again on the live demo. What it does
 *   1. Turns on every company-wide module the guide documents (settings.config_module_enable_* / core switches).
 *   2. Inserts the permission modules a stock install lacks (module_kb, module_rmm, module_rmm_scripts,
 *      module_rmm_remote_connect, module_rmm_alerts).
 *   3. Fills the built-in Technician role for those modules and creates two custom roles:
 *      "Billing clerk" and "Read-only viewer"; Jules Whitaker is moved onto Billing clerk.
 *   4. Enables cron, saves a backup passphrase (encryptSetting), and gives the technicians a few preferences.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "01-modules: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "01-modules: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }

// ---- 1. company-wide switches (only columns that exist in this schema version) --------------------
$want = [
    'config_module_enable_itdoc' => 1, 'config_module_enable_ticketing' => 1, 'config_module_enable_accounting' => 1,
    'config_module_enable_ticket_charges' => 1, 'config_client_portal_enable' => 1, 'config_ticket_csat_enable' => 1,
    'config_module_enable_kb' => 1, 'config_module_enable_live_chat' => 1, 'config_module_enable_payroll' => 1,
    'config_module_enable_rmm' => 1, 'config_core_rmm_enabled' => 1, 'config_comet_enabled' => 1,
    'config_core_panel_enabled' => 1, 'config_module_enable_unifi' => 1, 'config_module_enable_mcp' => 1,
    'config_enable_cron' => 1,
];
$have = [];
$r = mysqli_query($mysqli, 'SHOW COLUMNS FROM settings');
while ($c = mysqli_fetch_assoc($r)) { $have[$c['Field']] = true; }
$set = [];
foreach ($want as $k => $v) { if (isset($have[$k])) { $set[] = "$k = $v"; } else { echo "01-modules: (no such setting $k)\n"; } }
q('UPDATE settings SET ' . implode(', ', $set) . ' WHERE company_id = 1');
// the built-in RMM module also has its own master switch
$t = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_settings'");
if ($t && mysqli_num_rows($t)) { q('UPDATE endpoint_agent_settings SET enabled = 1 WHERE id = 1'); }

// ---- 2. permission modules ---------------------------------------------------------------------
$modules = [
    'module_kb' => 'Access to the knowledge base',
    'module_rmm' => 'Access to endpoints, RMM devices and backups',
    'module_rmm_scripts' => 'Run scripts on endpoints',
    'module_rmm_remote_connect' => 'Open remote sessions to endpoints',
    'module_rmm_alerts' => 'See and act on RMM and backup alerts',
];
foreach ($modules as $name => $desc) {
    if (!one("SELECT module_id FROM modules WHERE module_name = '$name'")) {
        q("INSERT INTO modules SET module_name = '$name', module_description = '" . mysqli_real_escape_string($mysqli, $desc) . "'");
    }
}
$mid = [];
$r = mysqli_query($mysqli, 'SELECT module_id, module_name FROM modules');
while ($m = mysqli_fetch_assoc($r)) { $mid[$m['module_name']] = (int) $m['module_id']; }

// ---- 3. roles ------------------------------------------------------------------------------------
function role_id($name, $desc) {
    global $mysqli;
    $id = one("SELECT role_id FROM user_roles WHERE role_name = '" . mysqli_real_escape_string($mysqli, $name) . "'");
    if (!$id) {
        q("INSERT INTO user_roles SET role_name = '" . mysqli_real_escape_string($mysqli, $name) . "', role_description = '" . mysqli_real_escape_string($mysqli, $desc) . "', role_is_admin = 0");
        $id = mysqli_insert_id($mysqli);
    }
    return (int) $id;
}
function grant($role, array $levels) {
    global $mid;
    foreach ($levels as $mod => $lvl) {
        if (!isset($mid[$mod])) { continue; }
        $m = $mid[$mod];
        q("DELETE FROM user_role_permissions WHERE user_role_id = $role AND module_id = $m");
        if ($lvl > 0) { q("INSERT INTO user_role_permissions SET user_role_id = $role, module_id = $m, user_role_permission_level = $lvl"); }
    }
}
$tech = (int) one("SELECT role_id FROM user_roles WHERE role_name = 'Technician' AND role_type = 1");
// Technician: day-to-day service desk and endpoints, no money, no remote shell.
grant($tech, ['module_kb' => 2, 'module_rmm' => 2, 'module_rmm_scripts' => 2, 'module_rmm_alerts' => 2, 'module_rmm_remote_connect' => 2, 'module_reporting' => 1]);
$billing = role_id('Billing clerk', 'Custom - quotes, invoices, payments and finance; read-only on clients and tickets');
grant($billing, ['module_client' => 1, 'module_support' => 1, 'module_credential' => 0, 'module_sales' => 3, 'module_financial' => 3,
                 'module_reporting' => 2, 'module_kb' => 1, 'module_rmm' => 0, 'module_rmm_scripts' => 0, 'module_rmm_remote_connect' => 0, 'module_rmm_alerts' => 0]);
$viewer = role_id('Read-only viewer', 'Custom - can look at everything except credentials, nothing can be changed');
grant($viewer, ['module_client' => 1, 'module_support' => 1, 'module_sales' => 1, 'module_financial' => 1, 'module_reporting' => 1, 'module_kb' => 1, 'module_rmm' => 1, 'module_rmm_alerts' => 1]);

q("UPDATE users SET user_role_id = $tech WHERE user_email IN ('devon.park@lakeshoremsp.example','sam.ortiz@lakeshoremsp.example')");
q("UPDATE users SET user_role_id = $billing WHERE user_email = 'jules.whitaker@lakeshoremsp.example'");

// ---- 4. backup passphrase (16+ chars; encrypted exactly as Administration > Backup does) ----------
if (!one('SELECT config_backup_passphrase FROM settings WHERE company_id = 1')) {
    $pp = mysqli_real_escape_string($mysqli, encryptSetting('lakeshore-demo-passphrase-2026'));
    q("UPDATE settings SET config_backup_passphrase = '$pp' WHERE company_id = 1");
}
echo "01-modules: done (roles: Technician=$tech, Billing clerk=$billing, Read-only viewer=$viewer)\n";
