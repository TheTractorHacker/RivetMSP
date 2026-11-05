<?php
/*
 * Demo seed 23-structured-docs - two structured documentation types (Firewall, Internet circuit) and a few
 * documents of each, for the Lakeshore Managed IT demo. Password-type field values are encrypted with the
 * app's own encryptSetting(), exactly like agent/post/structured_doc.php does.
 *
 *   RIVETIT_APP_DIR=/path/to/app php docs/user-guide/tools/seed/23-structured-docs.php
 *
 * Idempotent: types by name, fields by (type, key), documents by (client, name). Depends on 00-core.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this seed from the command line.\n");
    exit(1);
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
$app = rtrim($app, '/');
if (!is_file("$app/functions.php") || !is_file("$app/config.php")) {
    fwrite(STDERR, "23-structured-docs: '$app' is not an application directory.\n");
    exit(1);
}
chdir("$app/scripts");
$_SERVER['DOCUMENT_ROOT'] = $app;
require_once "$app/config.php";
require_once "$app/functions.php";
mysqli_set_charset($mysqli, 'utf8mb4');

function d_esc($v): string { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }
function d_one(string $sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function d_exec(string $sql): bool { global $mysqli; $ok = mysqli_query($mysqli, $sql); if (!$ok) { fwrite(STDERR, "23-structured-docs: " . mysqli_error($mysqli) . "\n"); } return (bool) $ok; }

$morgan = (int) d_one("SELECT user_id FROM users WHERE user_email = 'morgan.reyes@lakeshoremsp.example' LIMIT 1");
$devon = (int) d_one("SELECT user_id FROM users WHERE user_email = 'devon.park@lakeshoremsp.example' LIMIT 1");
if (!$morgan) { fwrite(STDERR, "23-structured-docs: 00-core has not been applied.\n"); exit(1); }

/* field = [key, label, kind, options (dropdown), required] */
$types = [
    'Firewall' => ['icon' => 'fa-shield-alt', 'order' => 1, 'fields' => [
        ['make_model', 'Make and model', 'text', null, 1],
        ['asset_name', 'Asset record', 'text', null, 0],
        ['wan_ip', 'WAN IP address', 'text', null, 0],
        ['mgmt_url', 'Management URL', 'url', null, 0],
        ['admin_password', 'Console password', 'password', null, 0],
        ['support', 'Support contract', 'dropdown', "None\nSecurity subscription\nFull support", 0],
        ['last_export', 'Last configuration export', 'date', null, 0],
        ['backups_on', 'Weekly export enabled', 'checkbox', null, 0],
        ['port_forwards', 'Port forwards and exceptions', 'textarea', null, 0],
    ]],
    'Internet circuit' => ['icon' => 'fa-globe', 'order' => 2, 'fields' => [
        ['provider', 'Provider', 'text', null, 1],
        ['circuit_id', 'Circuit ID', 'text', null, 0],
        ['speed', 'Speed (Mbps)', 'number', null, 0],
        ['static_ips', 'Static IP block', 'text', null, 0],
        ['support_url', 'Provider support URL', 'url', null, 0],
        ['pppoe_password', 'PPPoE password', 'password', null, 0],
        ['notes', 'Notes', 'textarea', null, 0],
    ]],
];

$type_id = $field_id = [];
foreach ($types as $name => $t) {
    $id = (int) d_one("SELECT type_id FROM structured_doc_types WHERE type_name = '" . d_esc($name) . "' AND type_archived_at IS NULL LIMIT 1");
    if (!$id) {
        d_exec("INSERT INTO structured_doc_types SET type_name = '" . d_esc($name) . "', type_icon = '" . d_esc($t['icon']) . "', type_order = " . (int) $t['order'] . ", type_created_by = $morgan");
        $id = (int) mysqli_insert_id($mysqli);
    }
    $type_id[$name] = $id;
    $order = 0;
    foreach ($t['fields'] as [$key, $label, $kind, $opts, $req]) {
        $order += 10;
        $fid = (int) d_one("SELECT field_id FROM structured_doc_fields WHERE field_type_id = $id AND field_key = '" . d_esc($key) . "' LIMIT 1");
        if (!$fid) {
            $options_sql = $opts === null ? 'NULL' : "'" . d_esc(json_encode(preg_split('/\R/', $opts))) . "'";
            d_exec("INSERT INTO structured_doc_fields SET field_type_id = $id, field_key = '" . d_esc($key) . "', field_label = '" . d_esc($label) . "', field_kind = '" . d_esc($kind) . "', field_options_json = $options_sql, field_required = " . (int) $req . ", field_order = $order");
            $fid = (int) mysqli_insert_id($mysqli);
        }
        $field_id[$name][$key] = $fid;
    }
}

$docs = [
    ['type' => 'Firewall', 'client' => 'Northshore Dental Group', 'name' => 'Main Office firewall', 'fav' => 1, 'values' => [
        'make_model' => 'Fortinet FortiGate 60F', 'asset_name' => 'NSD-FW-01', 'wan_ip' => '192.0.2.18', 'mgmt_url' => 'https://10.40.1.1:8443',
        'admin_password' => 'FAKE-Struct-Fw-#1', 'support' => 'Security subscription', 'last_export' => date('Y-m-d', strtotime('-6 days')), 'backups_on' => '1',
        'port_forwards' => "No inbound port forwards.\nSite-to-site VPN to the Sun Prairie Satellite (IPsec).\nImaging VLAN may reach the internet only from the imaging server."]],
    ['type' => 'Firewall', 'client' => 'Birchwood Law Partners', 'name' => 'Downtown Office firewall', 'fav' => 0, 'values' => [
        'make_model' => 'Fortinet FortiGate 40F', 'asset_name' => 'BLP-FW-01', 'wan_ip' => '192.0.2.27', 'mgmt_url' => 'https://10.41.1.1:8443',
        'admin_password' => 'FAKE-Struct-Fw-#2', 'support' => 'None', 'last_export' => date('Y-m-d', strtotime('-40 days')), 'backups_on' => '0',
        'port_forwards' => 'Inbound 443 to the secure client portal host.']],
    ['type' => 'Firewall', 'client' => 'Granite Peak Manufacturing', 'name' => 'Administration Building firewall', 'fav' => 0, 'values' => [
        'make_model' => 'Fortinet FortiGate 100F', 'asset_name' => 'GPM-FW-01', 'wan_ip' => '192.0.2.35', 'mgmt_url' => 'https://10.42.1.1:8443',
        'admin_password' => 'FAKE-Struct-Fw-#3', 'support' => 'Full support', 'last_export' => date('Y-m-d', strtotime('-3 days')), 'backups_on' => '1',
        'port_forwards' => "Inbound 443 to the supplier EDI gateway.\nAllow list for the plant floor VLAN (see the Plant Floor network record)."]],
    ['type' => 'Internet circuit', 'client' => 'Northshore Dental Group', 'name' => 'Main Office fiber (Meridian)', 'fav' => 0, 'values' => [
        'provider' => 'Meridian Business Internet', 'circuit_id' => 'MBI-FIB-0412', 'speed' => '300', 'static_ips' => '192.0.2.16/29',
        'support_url' => 'https://meridian-bi.example/support', 'pppoe_password' => 'FAKE-Struct-Ppp-#1',
        'notes' => 'Report outages to the carrier NOC first, then note the vendor reference on the ticket.']],
];

$made = 0;
foreach ($docs as $d) {
    $client_id = (int) d_one("SELECT client_id FROM clients WHERE client_name = '" . d_esc($d['client']) . "' LIMIT 1");
    if (!$client_id) { fwrite(STDERR, "23-structured-docs: client {$d['client']} not found.\n"); continue; }
    if ((int) d_one("SELECT doc_id FROM structured_documents WHERE doc_client_id = $client_id AND doc_name = '" . d_esc($d['name']) . "' LIMIT 1")) { continue; }
    d_exec("INSERT INTO structured_documents SET doc_type_id = " . $type_id[$d['type']] . ", doc_client_id = $client_id, doc_name = '" . d_esc($d['name']) . "', doc_favorite = " . (int) $d['fav'] . ", doc_created_by = " . ($devon ?: $morgan) . ", doc_updated_by = " . ($devon ?: $morgan));
    $doc_id = (int) mysqli_insert_id($mysqli);
    $made++;
    foreach ($d['values'] as $key => $val) {
        $fid = $field_id[$d['type']][$key];
        $kind = d_one("SELECT field_kind FROM structured_doc_fields WHERE field_id = $fid");
        if ($kind === 'password') {
            d_exec("INSERT INTO structured_document_values SET doc_id = $doc_id, field_id = $fid, value_enc = '" . d_esc(encryptSetting($val)) . "', value_text = NULL, updated_by = $morgan");
        } else {
            d_exec("INSERT INTO structured_document_values SET doc_id = $doc_id, field_id = $fid, value_text = '" . d_esc($val) . "', updated_by = $morgan");
        }
    }
}
echo "23-structured-docs: $made document(s) created.\n";
