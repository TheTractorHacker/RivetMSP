<?php
/*
 * Demo seed 21-credentials - the credential vault for the Lakeshore Managed IT demo.
 *
 *   RIVETIT_APP_DIR=/path/to/app php docs/user-guide/tools/seed/21-credentials.php
 *
 * Why PHP and not SQL: usernames, passwords and one-time-password (OTP) secrets are encrypted with the vault
 * master key, so they are written through the app's own helpers (encryptCredentialEntryWithKey() and the
 * canonical vault key from getCanonicalVaultKey()). Nothing here does its own cryptography.
 *
 * Idempotent: every row is looked up by a natural key (client + name) first and only created when missing.
 * It never prints secrets. Everything is fictional: .example addresses, 10.x / 192.0.2.x IPs, obviously fake
 * passwords and the well-known documentation OTP secrets.
 *
 * Depends on 00-core (clients, contacts, users), 01 (vault key already exists from setup) and 20-assets-and-docs
 * (assets are linked by NAME; a missing asset just leaves the link empty).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this seed from the command line.\n");
    exit(1);
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
$app = rtrim($app, '/');
if (!is_file("$app/functions.php") || !is_file("$app/config.php")) {
    fwrite(STDERR, "21-credentials: '$app' is not an application directory.\n");
    exit(1);
}
chdir("$app/scripts");
$_SERVER['DOCUMENT_ROOT'] = $app;
require_once "$app/config.php";
require_once "$app/functions.php";
mysqli_set_charset($mysqli, 'utf8mb4');

function s_esc($v): string { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }
function s_one(string $sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function s_exec(string $sql): bool { global $mysqli; $ok = mysqli_query($mysqli, $sql); if (!$ok) { fwrite(STDERR, "21-credentials: " . mysqli_error($mysqli) . "\n"); } return (bool) $ok; }
function s_ago(int $days): string { return "(NOW() - INTERVAL $days DAY)"; }

$master_key = getCanonicalVaultKey($mysqli);
if (!$master_key) {
    fwrite(STDERR, "21-credentials: no canonical vault key is set (Settings > Security), so credentials cannot be encrypted.\n");
    exit(1);
}
$users = [];
foreach (['morgan' => 'morgan.reyes', 'devon' => 'devon.park', 'sam' => 'sam.ortiz'] as $k => $local) {
    $id = (int) s_one("SELECT user_id FROM users WHERE user_email = '" . s_esc("$local@lakeshoremsp.example") . "' LIMIT 1");
    if (!$id) { fwrite(STDERR, "21-credentials: 00-core has not been applied (user $local missing).\n"); exit(1); }
    $users[$k] = $id;
}

function s_tag(string $name, string $color, string $icon): int
{
    $id = (int) s_one("SELECT tag_id FROM tags WHERE tag_name = '" . s_esc($name) . "' AND tag_type = 4 LIMIT 1");
    if ($id) { return $id; }
    global $mysqli;
    s_exec("INSERT INTO tags SET tag_name = '" . s_esc($name) . "', tag_type = 4, tag_color = '" . s_esc($color) . "', tag_icon = '" . s_esc($icon) . "'");
    return (int) mysqli_insert_id($mysqli);
}
function s_folder(int $client_id, string $name): int
{
    $id = (int) s_one("SELECT folder_id FROM folders WHERE folder_client_id = $client_id AND folder_location = 2 AND parent_folder = 0 AND folder_name = '" . s_esc($name) . "' LIMIT 1");
    if ($id) { return $id; }
    global $mysqli;
    s_exec("INSERT INTO folders SET folder_name = '" . s_esc($name) . "', parent_folder = 0, folder_location = 2, folder_client_id = $client_id");
    return (int) mysqli_insert_id($mysqli);
}

$tag = [
    'Network gear'  => s_tag('Network gear', '#17a2b8', 'network-wired'),
    'Server'        => s_tag('Server', '#6f42c1', 'server'),
    'Cloud service' => s_tag('Cloud service', '#007bff', 'cloud'),
    'Vendor portal' => s_tag('Vendor portal', '#fd7e14', 'building'),
    'Shop floor'    => s_tag('Shop floor', '#28a745', 'industry'),
];

/*
 * client = where it lives. asset / contact are looked up by name inside that client (missing = no link).
 * rotation_due = days from today (negative = overdue), null = none. changed = days since the password changed.
 * history rows are [field, old, new, user, days ago]; a Password row carries no values, as the app records it.
 * All passwords and OTP secrets are made up. The OTP secrets are base32 documentation examples.
 */
$creds = [
    ['client' => 'Northshore Dental Group', 'name' => 'Edge Firewall (NSD-FW-01) - Admin', 'desc' => 'Administrator console for the Main Office firewall',
     'user' => 'fwadmin', 'pass' => 'FAKE-Fw-Admin-#01', 'otp' => 'JBSWY3DPEHPK3PXP', 'uri' => 'https://10.40.1.1:8443', 'uri2' => '',
     'note' => "Sign-in also needs the one-time code shown in this record.\nAll changes follow the Firewall Change Procedure document.",
     'fav' => 1, 'imp' => 1, 'asset' => 'NSD-FW-01', 'contact' => null, 'tags' => ['Network gear'], 'folder' => 'Network devices',
     'created' => 150, 'due' => 38, 'changed' => 52,
     'history' => [['Password', null, null, 'devon', 52], ['URL', 'https://10.40.1.1', 'https://10.40.1.1:8443', 'devon', 52]]],
    ['client' => 'Northshore Dental Group', 'name' => 'Core Switch (NSD-SW-01) - Admin', 'desc' => 'Management login for the Main Office switch',
     'user' => 'netadmin', 'pass' => 'FAKE-Sw-Core-#02', 'otp' => '', 'uri' => 'https://10.40.1.2', 'uri2' => '',
     'note' => 'SSH is enabled on the same login. Password rotation is overdue; see the rotation report.',
     'fav' => 0, 'imp' => 0, 'asset' => 'NSD-SW-01', 'contact' => null, 'tags' => ['Network gear'], 'folder' => 'Network devices',
     'created' => 140, 'due' => -12, 'changed' => 102,
     'history' => [['Password', null, null, 'sam', 102]]],
    ['client' => 'Northshore Dental Group', 'name' => 'Imaging Server (NSD-SRV-01) - Local Administrator', 'desc' => 'Break-glass local administrator for the imaging server',
     'user' => 'NSD-SRV-01\\Administrator', 'pass' => 'FAKE-Srv-Local-#03', 'otp' => '', 'uri' => '', 'uri2' => '',
     'note' => 'Local account only, for when the domain is unreachable. Domain administrator accounts are never stored here.',
     'fav' => 0, 'imp' => 1, 'asset' => 'NSD-SRV-01', 'contact' => null, 'tags' => ['Server'], 'folder' => null,
     'created' => 130, 'due' => 75, 'changed' => 33,
     'history' => [['Password', null, null, 'morgan', 33]]],
    ['client' => 'Northshore Dental Group', 'name' => 'Microsoft 365 - Break-glass Admin', 'desc' => 'Emergency global administrator; use only if normal admin sign-in is unavailable',
     'user' => 'breakglass@northshoredental.example', 'pass' => 'FAKE-M365-Glass-#04', 'otp' => 'GEZDGNBVGY3TQOJQ', 'uri' => 'https://admin.m365.example', 'uri2' => '',
     'note' => 'Excluded from conditional-access policies on purpose. Every sign-in is reviewed the next working day.',
     'fav' => 1, 'imp' => 1, 'asset' => null, 'contact' => null, 'tags' => ['Cloud service'], 'folder' => null,
     'created' => 160, 'due' => 160, 'changed' => 20,
     'history' => [['Password', null, null, 'morgan', 20], ['Description', 'Emergency admin account', 'Emergency global administrator; use only if normal admin sign-in is unavailable', 'morgan', 20]]],
    ['client' => 'Northshore Dental Group', 'name' => 'Insurance Claims Portal', 'desc' => 'Claims submission portal used by the front desk',
     'user' => 'nsd.frontdesk', 'pass' => 'FAKE-Claims-#05', 'otp' => 'MFRGGZDFMZTWQ2LK', 'uri' => 'https://claims.dentalplan.example', 'uri2' => 'https://support.dentalplan.example',
     'note' => 'Shared by the front desk. Hannah Brooks resets it when staff change.',
     'fav' => 0, 'imp' => 0, 'asset' => null, 'contact' => 'Hannah Brooks', 'tags' => ['Vendor portal'], 'folder' => null,
     'created' => 110, 'due' => 20, 'changed' => 71,
     'history' => [['Password', null, null, 'sam', 71]]],
    ['client' => 'Birchwood Law Partners', 'name' => 'Document Server (BLP-SRV-01) - Local Administrator', 'desc' => 'Break-glass local administrator for the document server',
     'user' => 'BLP-SRV-01\\Administrator', 'pass' => 'FAKE-Blp-Srv-#06', 'otp' => '', 'uri' => '', 'uri2' => '',
     'note' => 'Local account only.', 'fav' => 0, 'imp' => 1, 'asset' => 'BLP-SRV-01', 'contact' => null, 'tags' => ['Server'], 'folder' => null,
     'created' => 100, 'due' => 60, 'changed' => 40, 'history' => [['Password', null, null, 'devon', 40]]],
    ['client' => 'Birchwood Law Partners', 'name' => 'Firewall (BLP-FW-01) - Admin', 'desc' => 'Downtown Office firewall',
     'user' => 'fwadmin', 'pass' => 'FAKE-Blp-Fw-#07', 'otp' => '', 'uri' => 'https://10.41.1.1:8443', 'uri2' => '',
     'note' => '', 'fav' => 0, 'imp' => 0, 'asset' => 'BLP-FW-01', 'contact' => null, 'tags' => ['Network gear'], 'folder' => null,
     'created' => 100, 'due' => -4, 'changed' => 94, 'history' => []],
    ['client' => 'Birchwood Law Partners', 'name' => 'Practice Manager Cloud - Admin', 'desc' => 'Administrator login for the matter and billing system',
     'user' => 'admin@birchwoodlaw.example', 'pass' => 'FAKE-Pmc-Admin-#08', 'otp' => 'KRSXG5CTMVRXEZLU', 'uri' => 'https://app.practicemanager.example', 'uri2' => '',
     'note' => 'Billing exports run from this account every month end.', 'fav' => 0, 'imp' => 0, 'asset' => null, 'contact' => 'Theo Lindqvist', 'tags' => ['Cloud service'], 'folder' => null,
     'created' => 88, 'due' => null, 'changed' => null, 'history' => []],
    ['client' => 'Granite Peak Manufacturing', 'name' => 'Hyper-V Host (GPM-SRV-01) - Local Administrator', 'desc' => 'Break-glass local administrator on the Hyper-V host',
     'user' => 'GPM-SRV-01\\Administrator', 'pass' => 'FAKE-Gpm-Host-#09', 'otp' => '', 'uri' => '', 'uri2' => '',
     'note' => 'Domain administrator accounts are never stored here.', 'fav' => 1, 'imp' => 1, 'asset' => 'GPM-SRV-01', 'contact' => null, 'tags' => ['Server'], 'folder' => null,
     'created' => 120, 'due' => 120, 'changed' => 15, 'history' => [['Password', null, null, 'sam', 15]]],
    ['client' => 'Granite Peak Manufacturing', 'name' => 'Line 3 Operator Panel (GPM-HMI-03) - Service', 'desc' => 'Service login on the line 3 touch panel',
     'user' => 'service', 'pass' => 'FAKE-Hmi03-#10', 'otp' => '', 'uri' => '', 'uri2' => '',
     'note' => 'Do not change settings during a production shift.', 'fav' => 0, 'imp' => 0, 'asset' => 'GPM-HMI-03', 'contact' => 'Marcus Webb', 'tags' => ['Shop floor'], 'folder' => null,
     'created' => 60, 'due' => 120, 'changed' => null, 'history' => []],
    ['client' => 'Granite Peak Manufacturing', 'name' => 'ERP Vendor Support Portal', 'desc' => 'Keystone ERP support portal for case tracking',
     'user' => 'gpm.support', 'pass' => 'FAKE-Erp-Portal-#11', 'otp' => '', 'uri' => 'https://support.keystone-erp.example', 'uri2' => '',
     'note' => 'Cases opened here also email the plant manager.', 'fav' => 0, 'imp' => 0, 'asset' => null, 'contact' => 'Robert Kowalski', 'tags' => ['Vendor portal'], 'folder' => null,
     'created' => 75, 'due' => 9, 'changed' => 170, 'history' => [['Password', null, null, 'devon', 45]]],
    ['client' => 'Lakeview Family Credit Union', 'name' => 'Branch Server (LFCU-SRV-01) - Local Administrator', 'desc' => 'Break-glass local administrator, PCI scope',
     'user' => 'LFCU-SRV-01\\Administrator', 'pass' => 'FAKE-Lfcu-Srv-#12', 'otp' => '', 'uri' => '', 'uri2' => '',
     'note' => 'Reveals are reviewed monthly by the compliance manager.', 'fav' => 1, 'imp' => 1, 'asset' => 'LFCU-SRV-01', 'contact' => 'Gordon Pike', 'tags' => ['Server'], 'folder' => null,
     'created' => 90, 'due' => 30, 'changed' => 60, 'history' => [['Password', null, null, 'morgan', 60]]],
    ['client' => 'Lakeview Family Credit Union', 'name' => 'Core Banking Admin Console', 'desc' => 'Administrator access for the core banking platform',
     'user' => 'lfcu.admin', 'pass' => 'FAKE-Core-Admin-#13', 'otp' => 'ONSWG4TFOQYTEMZU', 'uri' => 'https://core.summitcore.example', 'uri2' => '',
     'note' => 'Owned by the compliance manager. Two-person approval for any change.', 'fav' => 0, 'imp' => 1, 'asset' => null, 'contact' => 'Gordon Pike', 'tags' => ['Vendor portal'], 'folder' => null,
     'created' => 70, 'due' => 14, 'changed' => 76, 'history' => [['Password', null, null, 'morgan', 76]]],
    ['client' => 'Cedar & Pine Architects', 'name' => 'Plotter (CPA-PLOT-01) - Web Admin', 'desc' => 'Embedded web console of the large-format plotter',
     'user' => 'admin', 'pass' => 'FAKE-Plot-#14', 'otp' => '', 'uri' => 'http://10.44.1.50', 'uri2' => '',
     'note' => '', 'fav' => 0, 'imp' => 0, 'asset' => 'CPA-PLOT-01', 'contact' => null, 'tags' => ['Network gear'], 'folder' => null,
     'created' => 40, 'due' => null, 'changed' => null, 'history' => []],
];

$made = 0;
foreach ($creds as $c) {
    $client_id = (int) s_one("SELECT client_id FROM clients WHERE client_name = '" . s_esc($c['client']) . "' LIMIT 1");
    if (!$client_id) { fwrite(STDERR, "21-credentials: client '{$c['client']}' not found, skipped {$c['name']}\n"); continue; }
    if ((int) s_one("SELECT credential_id FROM credentials WHERE credential_client_id = $client_id AND credential_name = '" . s_esc($c['name']) . "' LIMIT 1")) { continue; }

    $asset_id = $c['asset'] ? (int) s_one("SELECT asset_id FROM assets WHERE asset_client_id = $client_id AND asset_name = '" . s_esc($c['asset']) . "' LIMIT 1") : 0;
    $contact_id = $c['contact'] ? (int) s_one("SELECT contact_id FROM contacts WHERE contact_client_id = $client_id AND contact_name = '" . s_esc($c['contact']) . "' LIMIT 1") : 0;
    $folder_id = $c['folder'] ? s_folder($client_id, $c['folder']) : 0;

    $enc_user = encryptCredentialEntryWithKey($c['user'], $master_key);
    $enc_pass = encryptCredentialEntryWithKey($c['pass'], $master_key);
    $enc_otp = $c['otp'] !== '' ? 'enc:' . encryptCredentialEntryWithKey($c['otp'], $master_key) : '';
    $due = $c['due'] === null ? 'NULL' : "(CURDATE() + INTERVAL {$c['due']} DAY)";
    $changed = $c['changed'] === null ? s_ago($c['created']) : s_ago($c['changed']);

    $ok = s_exec("INSERT INTO credentials SET
        credential_name = '" . s_esc($c['name']) . "', credential_description = '" . s_esc($c['desc']) . "',
        credential_uri = '" . s_esc($c['uri']) . "', credential_uri_2 = '" . s_esc($c['uri2']) . "',
        credential_username = '" . s_esc($enc_user) . "', credential_password = '" . s_esc($enc_pass) . "',
        credential_otp_secret = '" . s_esc($enc_otp) . "', credential_note = '" . s_esc($c['note']) . "',
        credential_favorite = " . (int) $c['fav'] . ", credential_important = " . (int) $c['imp'] . ",
        credential_folder_id = $folder_id, credential_contact_id = $contact_id, credential_asset_id = $asset_id,
        credential_client_id = $client_id, credential_rotation_due_at = $due,
        credential_password_changed_at = $changed, credential_created_at = " . s_ago($c['created']));
    if (!$ok) { continue; }
    $cid = (int) mysqli_insert_id($mysqli);
    $made++;

    $stored = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_password FROM credentials WHERE credential_id = $cid"));
    if (decryptCredentialEntryWithKey($stored['credential_password'], $master_key) !== $c['pass']) {
        fwrite(STDERR, "21-credentials: encryption round-trip failed for credential id $cid\n");
        exit(1);
    }
    foreach ($c['tags'] as $t) { s_exec("INSERT IGNORE INTO credential_tags SET credential_id = $cid, tag_id = " . $tag[$t]); }
    foreach ($c['history'] as [$field, $old, $new, $who, $days]) {
        $uid = $users[$who];
        $uname = s_one("SELECT user_name FROM users WHERE user_id = $uid");
        s_exec("INSERT INTO credential_history SET history_credential_id = $cid, history_user_id = $uid, history_user_name = '" . s_esc($uname) . "',
            history_field = '" . s_esc($field) . "', history_old_value = " . ($old === null ? 'NULL' : "'" . s_esc($old) . "'") . ",
            history_new_value = " . ($new === null ? 'NULL' : "'" . s_esc($new) . "'") . ", history_created_at = " . s_ago($days));
    }
}

// Link two credentials to vendors / licenses so the Relationships cards have content (names are unique per client).
$vendor_link = [['Granite Peak Manufacturing', 'ERP Vendor Support Portal', 'Keystone ERP Software']];
foreach ($vendor_link as [$cl, $cn, $vn]) {
    $client_id = (int) s_one("SELECT client_id FROM clients WHERE client_name = '" . s_esc($cl) . "' LIMIT 1");
    $vid = (int) s_one("SELECT vendor_id FROM vendors WHERE vendor_client_id = $client_id AND vendor_name = '" . s_esc($vn) . "' LIMIT 1");
    $cid = (int) s_one("SELECT credential_id FROM credentials WHERE credential_client_id = $client_id AND credential_name = '" . s_esc($cn) . "' LIMIT 1");
    if ($vid && $cid) { s_exec("INSERT IGNORE INTO vendor_credentials SET vendor_id = $vid, credential_id = $cid"); }
}

echo "21-credentials: $made credential(s) created; total " . (int) s_one("SELECT COUNT(*) FROM credentials") . "\n";
