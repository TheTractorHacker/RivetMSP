<?php
/*
 * Seed 72 (group 12, portals): secure share links ("shared items") for Northshore Dental Group, the rows that
 * Share Link on a document, file or credential creates.
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/72-shared-items.php
 *
 *   - Document   "Office Wi-Fi and Printer Guide"        for Hannah Brooks, expires in 29 days, unlimited views
 *   - File       "NSD-Network-Diagram-v3.pdf"            for Dr. Priya Raman, expires in 6 days, unlimited views
 *   - Credential "Insurance Claims Portal"               for Hannah Brooks, expires in 6 days, unlimited views
 *   - Credential "Insurance Claims Portal"               for Dr. Priya Raman, expires in 1 day, "Delete after view" (1 view)
 *
 * The credential shares are encrypted exactly like agent/ajax.php does (AES-128-CBC with a 16 character key that only ever
 * lives in the link's #ek=... fragment). The values inside are made-up demo strings, not the vault values. The demo link keys
 * and the encryption key below are fixed on purpose so the capture script can open the same pages every time:
 *   guest/guest_view_item.php?id=<item id>&key=<item key>#ek=DemoEk-Lakeshore
 * The demo File share points at a database row only; the demo has no stored bytes for it, so its Download link is not used.
 * Example data, not a live connection.
 *
 * Depends on 00-core, 20-assets-and-docs (documents, files) and 21-credentials. Idempotent: a share is skipped when its
 * item key already exists. The cron job deletes expired or used-up shares, so a very old database loses these rows; re-run this seed.
 */
if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "72-shared-items: no config.php in '$app'. Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "72-shared-items: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } return true; }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function e($s) { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $s); }

$tz = (string) one('SELECT config_timezone FROM settings WHERE company_id = 1') ?: 'America/Chicago';
q("SET time_zone = '" . (new DateTime('now', new DateTimeZone($tz)))->format('P') . "'");

$cid = (int) one("SELECT client_id FROM clients WHERE client_name = 'Northshore Dental Group' LIMIT 1");
if (!$cid) {
    fwrite(STDERR, "72-shared-items: run 00-core first.\n");
    exit(1);
}
$ek = 'DemoEk-Lakeshore';   // 16 characters: the AES-128 key, only ever in the URL fragment

function seal(string $plain, string $key): string
{
    $iv = 'DemoIv' . substr(md5($plain), 0, 10);   // 16 characters; the app uses a random one
    return $iv . openssl_encrypt($plain, 'aes-128-cbc', $key, 0, $iv);
}

$doc = (int) one("SELECT document_id FROM documents WHERE document_client_id = $cid AND document_name = 'Office Wi-Fi and Printer Guide' LIMIT 1");
$file = (int) one("SELECT file_id FROM files WHERE file_client_id = $cid AND file_name = 'NSD-Network-Diagram-v3.pdf' LIMIT 1");
$cred = (int) one("SELECT credential_id FROM credentials WHERE credential_client_id = $cid AND credential_name = 'Insurance Claims Portal' LIMIT 1");

// key label, type, related id, recipient, note, view limit, expires in (hours)
$shares = [
  ['g12-doc-wifi',  'Document',   $doc,  'hannah.brooks@northshoredental.example', 'Wi-Fi and printer instructions for the front office.', 0, 29 * 24],
  ['g12-file-net',  'File',       $file, 'priya.raman@northshoredental.example',   'Network diagram for your records.', 0, 6 * 24],
  ['g12-cred-view', 'Credential', $cred, 'hannah.brooks@northshoredental.example', 'Sign-in for the insurance claims portal.', 0, 6 * 24],
  ['g12-cred-once', 'Credential', $cred, 'priya.raman@northshoredental.example',   'One-time view: the link is deleted after you open it.', 1, 24],
];
foreach ($shares as [$label, $type, $rel, $to, $note, $limit, $hours]) {
    $itemKey = md5($label);
    if (!$rel) { echo "72-shared-items: $label skipped (related $type not found)\n"; continue; }
    if (one("SELECT item_id FROM shared_items WHERE item_key = '$itemKey'")) { continue; }
    $user = $pass = $otp = '';
    if ($type === 'Credential') {
        $user = seal('claims-desk@northshoredental.example', $ek);
        $pass = seal('Demo-Only-Not-A-Real-Password', $ek);
    }
    q("INSERT INTO shared_items SET item_active = 1, item_key = '$itemKey', item_type = '$type', item_related_id = $rel,
        item_encrypted_username = '" . e($user) . "', item_encrypted_credential = '" . e($pass) . "', item_encrypted_otp = '" . e($otp) . "',
        item_note = '" . e($note) . "', item_recipient = '" . e($to) . "', item_views = 0, item_view_limit = $limit,
        item_expire_at = DATE_ADD(NOW(), INTERVAL $hours HOUR), item_client_id = $cid");
    echo "72-shared-items: $type share for $to\n";
}
