<?php
/*
 * Demo seed 22-knowledge-base - Knowledge Base categories, articles, version history, one attachment and one
 * interactive embed for the Lakeshore Managed IT demo.
 *
 *   RIVETIT_APP_DIR=/path/to/app php docs/user-guide/tools/seed/22-knowledge-base.php
 *
 * Idempotent: categories are found by name and articles by (client, title); versions, attachments and embeds
 * are only added when the article was created by this run. Depends on 00-core (clients, users) and 21-credentials
 * (one article quotes a credential reference token; if the credential is missing the token is left out).
 * Everything is fictional.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this seed from the command line.\n");
    exit(1);
}
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
$app = rtrim($app, '/');
if (!is_file("$app/functions.php") || !is_file("$app/config.php")) {
    fwrite(STDERR, "22-knowledge-base: '$app' is not an application directory.\n");
    exit(1);
}
chdir("$app/scripts");
$_SERVER['DOCUMENT_ROOT'] = $app;
require_once "$app/config.php";
require_once "$app/functions.php";
mysqli_set_charset($mysqli, 'utf8mb4');

function k_esc($v): string { global $mysqli; return mysqli_real_escape_string($mysqli, (string) $v); }
function k_one(string $sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
function k_exec(string $sql): bool { global $mysqli; $ok = mysqli_query($mysqli, $sql); if (!$ok) { fwrite(STDERR, "22-knowledge-base: " . mysqli_error($mysqli) . "\n"); } return (bool) $ok; }
function k_ago(int $days): string { return "(NOW() - INTERVAL $days DAY)"; }

$users = [];
foreach (['morgan' => 'morgan.reyes', 'devon' => 'devon.park', 'sam' => 'sam.ortiz'] as $k => $local) {
    $id = (int) k_one("SELECT user_id FROM users WHERE user_email = '" . k_esc("$local@lakeshoremsp.example") . "' LIMIT 1");
    if (!$id) { fwrite(STDERR, "22-knowledge-base: 00-core has not been applied.\n"); exit(1); }
    $users[$k] = $id;
}

function k_category(string $name, int $parent = 0): int
{
    global $mysqli;
    $id = (int) k_one("SELECT kb_category_id FROM kb_categories WHERE kb_category_name = '" . k_esc($name) . "' AND kb_category_parent_id = $parent AND kb_category_client_id = 0 AND kb_category_archived_at IS NULL LIMIT 1");
    if ($id) { return $id; }
    $order = (int) k_one("SELECT COALESCE(MAX(kb_category_order), -1) + 1 FROM kb_categories WHERE kb_category_parent_id = $parent");
    k_exec("INSERT INTO kb_categories SET kb_category_name = '" . k_esc($name) . "', kb_category_parent_id = $parent, kb_category_client_id = 0, kb_category_order = $order");
    return (int) mysqli_insert_id($mysqli);
}

function k_simple_pdf(string $title, array $lines): string
{
    $t = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $content = "BT /F1 18 Tf 56 780 Td (" . $t($title) . ") Tj ET\n";
    $y = 748;
    foreach ($lines as $line) { $content .= "BT /F1 12 Tf 56 $y Td (" . $t($line) . ") Tj ET\n"; $y -= 20; }
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        4 => "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n"; $offsets = [];
    foreach ($objects as $n => $body) { $offsets[$n] = strlen($pdf); $pdf .= "$n 0 obj\n$body\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) { $pdf .= sprintf("%010d 00000 n \n", $o); }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

function k_attachment(int $article_id, string $display_name, string $bytes, int $days_ago): void
{
    global $app;
    if (k_one("SELECT kb_article_attachment_id FROM kb_article_attachments WHERE kb_article_attachment_kb_article_id = $article_id AND kb_article_attachment_name = '" . k_esc($display_name) . "' LIMIT 1")) { return; }
    $tmp = tempnam(sys_get_temp_dir(), 'kbatt');
    file_put_contents($tmp, $bytes);
    $ref = checkFileUpload(['name' => $display_name, 'tmp_name' => $tmp, 'size' => strlen($bytes)], ['pdf', 'txt', 'png', 'docx', 'xlsx', 'zip']);
    if (!is_string($ref) || !preg_match('/^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/', $ref)) { unlink($tmp); fwrite(STDERR, "22-knowledge-base: could not name attachment $display_name\n"); return; }
    $dir = "$app/uploads/kb/$article_id";
    if (!is_dir("$app/uploads/kb")) { mkdir("$app/uploads/kb", 0775, true); }
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    copy($tmp, "$dir/$ref");
    unlink($tmp);
    k_exec("INSERT INTO kb_article_attachments SET kb_article_attachment_name = '" . k_esc($display_name) . "', kb_article_attachment_reference_name = '" . k_esc($ref) . "', kb_article_attachment_kb_article_id = $article_id, kb_article_attachment_created_at = " . k_ago($days_ago));
}

/* ---- categories (a top level with two sub-categories) ---------------------------------------- */
$cat = [];
foreach (['Service Desk Procedures', 'Client Onboarding', 'Security & Compliance', 'How-to Guides for Clients'] as $n) { $cat[$n] = k_category($n); }
$cat['Password & Account Procedures'] = k_category('Password & Account Procedures', $cat['Service Desk Procedures']);
$cat['Server & Network Procedures'] = k_category('Server & Network Procedures', $cat['Service Desk Procedures']);

// the credential the NSD article points at (reference token "[[credential:ID]]")
$nsd = (int) k_one("SELECT client_id FROM clients WHERE client_name = 'Northshore Dental Group' LIMIT 1");
$cred_id = (int) k_one("SELECT credential_id FROM credentials WHERE credential_client_id = $nsd AND credential_name = 'Imaging Server (NSD-SRV-01) - Local Administrator' LIMIT 1");
$cred_token = $cred_id ? "<p>The local administrator login for this server: [[credential:$cred_id]]</p>" : '';

/*
 * client = client name or null for a company-wide ("Central") article. visible = shown in the client portal.
 * review = days from today for the review date (negative = overdue); versions = number of earlier saved versions.
 */
$articles = [
  ['title' => 'Password Reset Procedure', 'client' => null, 'cat' => 'Password & Account Procedures', 'visible' => 0, 'by' => 'devon', 'created' => 200, 'updated' => 12, 'review' => 170, 'reviewer' => 'morgan', 'versions' => 2,
   'html' => '<h2>Before you start</h2><p>Confirm who is asking. Call the person back on the number stored on their contact record, or ask a second contact at the client to confirm. Never reset a password on the strength of an email alone.</p><h2>Reset a Microsoft 365 password</h2><ol><li>Open the tenant admin center from the client documentation.</li><li>Find the user and choose <strong>Reset password</strong>.</li><li>Untick <em>Automatically create a password</em> only if the client has a password policy that needs it.</li><li>Tell the user the temporary password by phone, never by email.</li></ol><h2>After the reset</h2><p>Add a note to the ticket with who you verified and how. If the account is an administrator, tell the client\'s primary contact.</p>'],
  ['title' => 'New Client Onboarding Checklist', 'client' => null, 'cat' => 'Client Onboarding', 'visible' => 0, 'by' => 'morgan', 'created' => 260, 'updated' => 40, 'review' => 20, 'reviewer' => 'morgan', 'versions' => 1,
   'html' => '<h2>Week 1</h2><ol><li>Create the client, primary contact and every location.</li><li>Record hourly rate, net terms and tax ID on the Billing tab.</li><li>Install the agent on every PC and server and link the assets.</li><li>Document the network, internet circuit and firewall.</li></ol><h2>Week 2</h2><ol><li>Store every shared login in the credential vault.</li><li>Write the backup and restore runbook.</li><li>Walk the primary contact through the client portal.</li></ol>'],
  ['title' => 'Reboot Checklist for Servers', 'client' => null, 'cat' => 'Server & Network Procedures', 'visible' => 0, 'by' => 'sam', 'created' => 180, 'updated' => 95, 'review' => -14, 'reviewer' => 'devon', 'versions' => 1,
   'html' => '<h2>Before you reboot</h2><ol><li>Check the client documentation for a maintenance window and for services that must not stop.</li><li>Confirm no backup job is running.</li><li>Tell the primary contact and wait for a yes.</li></ol><h2>After the reboot</h2><p>Check that services came back, then close the loop on the ticket.</p>'],
  ['title' => 'How to Spot a Phishing Email', 'client' => null, 'cat' => 'Security & Compliance', 'visible' => 1, 'by' => 'morgan', 'created' => 150, 'updated' => 30, 'review' => 150, 'reviewer' => 'morgan', 'versions' => 0,
   'html' => '<p>Before you click, stop and check:</p><ul><li>Is the sender address exactly what you expect?</li><li>Does the message create urgency or ask for gift cards or payments?</li><li>Hover over a link to see where it really goes.</li></ul><p>If you are unsure, do not click. Forward the message to the Lakeshore Managed IT service desk as an attachment and we will check it for you.</p>'],
  ['title' => 'How to Connect to the Office Wi-Fi', 'client' => 'Northshore Dental Group', 'cat' => 'How-to Guides for Clients', 'visible' => 1, 'by' => 'sam', 'created' => 120, 'updated' => 60, 'review' => 100, 'reviewer' => 'sam', 'versions' => 0,
   'html' => '<h2>Staff devices</h2><p>Join <strong>NSD-Staff</strong> and sign in with your Microsoft 365 account.</p><h2>Guests</h2><p>Join <strong>NSD-Guest</strong>. The password is posted at the front desk and changes every quarter.</p><p>If a device will not connect, restart its Wi-Fi once and then call the Lakeshore Managed IT service desk.</p>'],
  ['title' => 'Imaging Server: Do and Do Not', 'client' => 'Northshore Dental Group', 'cat' => 'Server & Network Procedures', 'visible' => 0, 'by' => 'devon', 'created' => 90, 'updated' => 20, 'review' => 60, 'reviewer' => 'devon', 'versions' => 1,
   'html' => '<h2>Never</h2><ul><li>Reboot or patch during business hours. The operatory workstations need this server for X-rays.</li><li>Update the imaging software without calling Apex Dental Imaging Support first.</li></ul><h2>Always</h2><ul><li>Check the 22:00 backup finished before maintenance.</li><li>Tell Dr. Raman or Hannah Brooks before any planned downtime.</li></ul>' . 'CRED_TOKEN'],
  ['title' => 'Birchwood: Matter Server Backup Schedule', 'client' => 'Birchwood Law Partners', 'cat' => 'Server & Network Procedures', 'visible' => 0, 'by' => 'sam', 'created' => 70, 'updated' => 70, 'review' => -3, 'reviewer' => 'sam', 'versions' => 0,
   'html' => '<p>Matter files on BLP-SRV-01 are backed up every night at 23:00 and kept for 30 days. A weekly full copy goes offsite on Sunday. Restores are requested through a ticket and need approval from Theo Lindqvist.</p>'],
  ['title' => 'Granite Peak: Line Down Escalation', 'client' => 'Granite Peak Manufacturing', 'cat' => 'Service Desk Procedures', 'visible' => 0, 'by' => 'devon', 'created' => 160, 'updated' => 25, 'review' => 45, 'reviewer' => 'devon', 'versions' => 0,
   'html' => '<h2>If a production line is down</h2><ol><li>Raise the ticket as <strong>Critical</strong> and call the plant manager, Robert Kowalski.</li><li>Check the HMI, switch and access point for the line in the asset records.</li><li>If the ERP is unreachable, call Keystone ERP Software support with the account number from the vendor record.</li></ol>'],
  ['title' => 'Lakeview: Change Approval Rules', 'client' => 'Lakeview Family Credit Union', 'cat' => 'Security & Compliance', 'visible' => 0, 'by' => 'morgan', 'created' => 100, 'updated' => 10, 'review' => 80, 'reviewer' => 'morgan', 'versions' => 0,
   'html' => '<p>Every change inside the PCI network needs an approved change ticket. Two people must approve: the compliance manager and a Lakeshore Managed IT lead. Emergency changes are documented within one business day.</p>'],
  ['title' => 'How to Print from Your Phone', 'client' => null, 'cat' => 'How-to Guides for Clients', 'visible' => 1, 'by' => 'sam', 'created' => 40, 'updated' => 40, 'review' => 300, 'reviewer' => 'sam', 'versions' => 0,
   'html' => '<p>Make sure your phone is on the office Wi-Fi, open the document, choose <strong>Share</strong> then <strong>Print</strong>, and pick the printer closest to you. If the printer does not appear, call the service desk.</p>'],
  ['title' => 'Quarterly Backup Test Procedure', 'client' => null, 'cat' => 'Security & Compliance', 'visible' => 0, 'by' => 'morgan', 'created' => 220, 'updated' => 130, 'review' => -45, 'reviewer' => 'morgan', 'versions' => 2,
   'html' => '<ol><li>Pick one client and one backed-up server.</li><li>Restore a handful of files and one whole VM to the test host.</li><li>Open the files and boot the VM.</li><li>Record the date, the result and the restore time on the client\'s compliance record.</li></ol>'],
];

$made = 0;
foreach ($articles as $a) {
    $client_id = 0;
    if ($a['client']) {
        $client_id = (int) k_one("SELECT client_id FROM clients WHERE client_name = '" . k_esc($a['client']) . "' LIMIT 1");
        if (!$client_id) { fwrite(STDERR, "22-knowledge-base: client {$a['client']} not found, skipped {$a['title']}\n"); continue; }
    }
    if ((int) k_one("SELECT kb_article_id FROM kb_articles WHERE kb_article_title = '" . k_esc($a['title']) . "' AND kb_article_client_id = $client_id LIMIT 1")) { continue; }

    $html = str_replace('CRED_TOKEN', $cred_token, $a['html']);
    $raw = trim($a['title'] . ' ' . str_replace('<', ' <', $html));
    $ok = k_exec("INSERT INTO kb_articles SET
        kb_article_title = '" . k_esc($a['title']) . "', kb_article_content = '" . k_esc($html) . "', kb_article_content_raw = '" . k_esc($raw) . "',
        kb_article_client_id = $client_id, kb_article_client_visible = " . (int) $a['visible'] . ",
        kb_article_category_id = " . $cat[$a['cat']] . ",
        kb_article_created_by = " . $users[$a['by']] . ", kb_article_updated_by = " . $users[$a['by']] . ",
        kb_article_created_at = " . k_ago($a['created']) . ", kb_article_updated_at = " . k_ago($a['updated']) . ",
        kb_article_review_due_at = " . ($a['review'] === null ? 'NULL' : "(CURDATE() + INTERVAL {$a['review']} DAY)") . ",
        kb_article_reviewer_user_id = " . $users[$a['reviewer']]);
    if (!$ok) { continue; }
    $id = (int) mysqli_insert_id($mysqli);
    $made++;

    // earlier saved versions (the app snapshots the OLD text on every edit, so v1 is the oldest)
    for ($v = 1; $v <= $a['versions']; $v++) {
        $old_html = ($v === 1 ? '<p><em>First draft.</em></p>' : '') . preg_replace('/<h2>.*?<\/h2>/', '', $html, 1);
        $old_raw = trim($a['title'] . ' ' . str_replace('<', ' <', $old_html));
        $when = max(1, $a['created'] - 20 * $v);
        k_exec("INSERT INTO kb_article_versions SET kb_article_version_kb_article_id = $id,
            kb_article_version_content = '" . k_esc($old_html) . "', kb_article_version_content_raw = '" . k_esc($old_raw) . "',
            kb_article_version_edited_by = " . $users[$a['by']] . ", kb_article_version_edited_at = " . k_ago(max(1, $a['updated'] + 15 * ($a['versions'] - $v + 1))) . ",
            kb_article_version_number = $v");
    }

    if ($a['title'] === 'Imaging Server: Do and Do Not') {
        k_attachment($id, 'NSD-Backup-Schedule.pdf', k_simple_pdf('Northshore Dental Group - backup schedule', [
            'Imaging server NSD-SRV-01 (example data)', '', 'Nightly 22:00   imaging and file shares to local backup disk',
            'Sunday 02:00   weekly full copy to the offsite vault', 'Retention      30 days local, 12 weeks offsite', '', 'Restore requests: open a ticket.']), 20);
    }
    if ($a['title'] === 'Quarterly Backup Test Procedure') {
        $embed = '<!doctype html><html><body style="font-family:sans-serif;padding:12px"><h3>Restore time estimator</h3><p>Data to restore (GB): <input id="gb" type="number" value="200" style="width:80px"></p><p>Throughput (MB/s): <input id="mbs" type="number" value="80" style="width:80px"></p><p><strong id="out"></strong></p><script>function c(){var m=document.getElementById("gb").value*1024/document.getElementById("mbs").value/60;document.getElementById("out").textContent="About "+Math.round(m)+" minutes";}document.getElementById("gb").oninput=c;document.getElementById("mbs").oninput=c;c();</script></body></html>';
        k_exec("INSERT INTO kb_article_embeds SET kb_article_embed_kb_article_id = $id, kb_article_embed_name = 'Restore time estimator',
            kb_article_embed_untrusted_html = '" . k_esc($embed) . "', kb_article_embed_sha256 = '" . hash('sha256', $embed) . "',
            kb_article_embed_height = 240, kb_article_embed_created_by = " . $users['morgan']);
    }
}
echo "22-knowledge-base: $made article(s) created; total " . (int) k_one("SELECT COUNT(*) FROM kb_articles") . "\n";
