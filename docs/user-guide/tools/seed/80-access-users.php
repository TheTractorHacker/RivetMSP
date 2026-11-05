<?php
/*
 * Group 13 (Access and compliance), seed 80: users, client scoping, MFA / passkey rows, activity log rows and audit-trail events
 * for Administration > Users, Roles, Audit log and Audit trail. Invented people only.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/80-access-users.php
 *
 * Depends on 00-core.sql (Morgan, Devon, Sam, Jules) and 01-modules-and-permissions.php (Read-only viewer role).
 * Idempotent: rows are found by e-mail / marker text first. Does not touch any existing login.
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "80-access-users: no config.php in '$app'.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../includes/security_policy.php';

function q80($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "80-access-users: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one80($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function esc80($s) { global $mysqli; return mysqli_real_escape_string($mysqli, $s); }

$hash = secPasswordHash('DemoPass#2026');
$ro = (int) one80("SELECT role_id FROM user_roles WHERE role_name = 'Read-only viewer' LIMIT 1");
$tech = (int) one80("SELECT role_id FROM user_roles WHERE role_name = 'Technician' LIMIT 1");
if (!$ro || !$tech) { fwrite(STDERR, "80-access-users: roles missing (run seed 01).\n"); exit(1); }

// name, email, role, status, archived, mfa token, color
$people = [
    ['Riley Chen', 'riley.chen@lakeshoremsp.example', $ro, 1, false, null],
    ['Avery Stone', 'avery.stone@lakeshoremsp.example', $tech, 1, false, 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'],
    ['Casey Lindgren', 'casey.lindgren@lakeshoremsp.example', $tech, 0, true, null],
];
$ids = [];
foreach ($people as [$name, $email, $role, $status, $archived, $token]) {
    $id = one80("SELECT user_id FROM users WHERE user_email = '" . esc80($email) . "'");
    if ($id === null) {
        $dbname = $archived ? "$name (archived)" : $name;
        $tok = $token ? "'" . esc80($token) . "'" : 'NULL';
        q80("INSERT INTO users (user_name, user_email, user_password, user_type, user_status, user_role_id, user_token, user_archived_at, user_created_at)
             VALUES ('" . esc80($dbname) . "', '" . esc80($email) . "', '" . esc80($hash) . "', 1, $status, $role, $tok, " . ($archived ? "NOW() - INTERVAL 40 DAY" : 'NULL') . ", NOW() - INTERVAL 120 DAY)");
        $id = mysqli_insert_id($mysqli);
        q80("INSERT INTO user_settings (user_id) VALUES ($id)");
        echo "80-access-users: created $name\n";
    }
    $ids[$email] = (int) $id;
}
$riley = $ids['riley.chen@lakeshoremsp.example'];
$casey = $ids['casey.lindgren@lakeshoremsp.example'];
$avery = $ids['avery.stone@lakeshoremsp.example'];

// Riley (an outside reviewer) is limited to two clients.
foreach (['Northshore Dental Group', 'Birchwood Law Partners'] as $c) {
    $cid = one80("SELECT client_id FROM clients WHERE client_name = '" . esc80($c) . "'");
    if ($cid !== null && one80("SELECT 1 FROM user_client_permissions WHERE user_id = $riley AND client_id = $cid") === null) {
        q80("INSERT INTO user_client_permissions (user_id, client_id) VALUES ($riley, $cid)");
    }
}
// A passkey and two remember-me tokens for Avery (fake values; nothing can sign in with them).
if (one80("SELECT 1 FROM user_passkeys WHERE passkey_user_id = $avery LIMIT 1") === null) {
    q80("INSERT INTO user_passkeys (passkey_user_id, passkey_name, passkey_credential_id, passkey_public_key, passkey_created_at, passkey_last_used_at)
         VALUES ($avery, 'Avery laptop (Touch ID)', 'ZGVtby1jcmVkZW50aWFsLW5vdC1yZWFs', 'demo-not-a-real-key', NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 2 DAY)");
}
if (one80("SELECT 1 FROM remember_tokens WHERE remember_token_user_id = $avery LIMIT 1") === null) {
    foreach ([12, 5] as $d) {
        q80("INSERT INTO remember_tokens (remember_token_token, remember_token_user_id, remember_token_created_at) VALUES ('" . bin2hex(random_bytes(16)) . "', $avery, NOW() - INTERVAL $d DAY)");
    }
}

// Activity log (Audit log page): backdated rows, only when the marker row is missing.
if (one80("SELECT 1 FROM logs WHERE log_description = 'Morgan Reyes created user Riley Chen' LIMIT 1") === null) {
    $morgan = (int) one80("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'");
    $ip = '203.0.113.24'; $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    $rows = [
        [118, 'User', 'Create', 'Morgan Reyes created user Riley Chen', $riley],
        [118, 'User', 'Edit', 'Morgan Reyes edited user Riley Chen', $riley],
        [92, 'User Role', 'Create', 'Morgan Reyes created user role Read-only viewer', 5],
        [92, 'User Role', 'Create', 'Morgan Reyes created user role Billing clerk', 4],
        [60, 'User', 'Edit', 'Morgan Reyes disabled 2FA for Casey Lindgren', $casey],
        [41, 'User', 'Archive', 'Morgan Reyes archived user Casey Lindgren', $casey],
        [33, 'Settings', 'Edit', 'Morgan Reyes edited security settings', 0],
        [20, 'User', 'Edit', 'Morgan Reyes revoked all remember me tokens for user Devon Park', 2],
        [9, 'User Role', 'Edit', 'Morgan Reyes edited user role Technician', 2],
        [3, 'User', 'Export', 'Morgan Reyes exported 6 user(s) to a CSV file', 0],
    ];
    foreach ($rows as [$days, $type, $action, $desc, $eid]) {
        q80("INSERT INTO logs (log_type, log_action, log_description, log_ip, log_user_agent, log_created_at, log_user_id, log_entity_id)
             VALUES ('" . esc80($type) . "', '" . esc80($action) . "', '" . esc80($desc) . "', '$ip', '" . esc80($ua) . "', NOW() - INTERVAL $days DAY - INTERVAL 3 HOUR, $morgan, $eid)");
    }
}

// Audit trail events (hash-chained, so written through the app's own audit service; stamped when the seed runs).
if (one80("SELECT 1 FROM audit_events WHERE event_type = 'user.role_changed' AND summary = 'Role of Riley Chen changed' LIMIT 1") === null) {
    require_once '../includes/event_bus.php';
    $morgan = (int) one80("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example'");
    rivetAudit('role.created', $morgan, 'role', 5, 'create', 'Role Read-only viewer: 8 permission change(s)', ['role_id' => 5, 'role_name' => 'Read-only viewer', 'admin_before' => null, 'admin_after' => false, 'changes' => [['module' => 'module_client', 'from' => 0, 'to' => 1], ['module' => 'module_support', 'from' => 0, 'to' => 1]]]);
    rivetAudit('user.role_changed', $morgan, 'user', $riley, 'update', 'Role of Riley Chen changed', ['user_id' => $riley, 'role_before' => $tech, 'role_after' => $ro, 'role_before_name' => 'Technician', 'role_after_name' => 'Read-only viewer']);
    rivetAudit('user.client_access_changed', $morgan, 'user', $riley, 'update', 'Client access of Riley Chen changed', ['user_id' => $riley, 'before' => [], 'after' => [1, 2], 'note' => 'empty = every client']);
    rivetAudit('settings.security_policy_changed', $morgan, 'settings', 'security_policy', 'edit', 'Morgan Reyes changed the sign-in policy', ['changes' => ['session_idle_minutes: 600 -> 480']]);
    echo "80-access-users: audit events written\n";
}
echo "80-access-users: done\n";
