<?php
/*
 * CRM segments and campaigns for the RivetMSP user-guide demo (group 8).
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/62-crm-segments-campaigns.php
 *
 * Depends on 00-core.sql, 60-crm-leads.sql, 61-crm-opportunities.sql. Idempotent: everything is looked up by name.
 *   1. A contact tag "Security newsletter" on a handful of client contacts.
 *   2. Three saved segments: All leads, Customers with an open deal, Security newsletter.
 *   3. Outbound "from" address (only if none is set) so the Send Campaign button works. Nothing leaves the demo: the
 *      mail queue is never delivered here (no SMTP), the rows just sit in Administration > Mail queue.
 *   4. One SENT campaign (recipient rows and queued messages created the same way Send Campaign does it) and one DRAFT.
 */
if (php_sapi_name() !== 'cli') { exit("Run this from the command line.\n"); }
$app = getenv('RIVETIT_APP_DIR') ?: getenv('RIVETMSP_APP_DIR') ?: '/tmp/rivetmsp-user-guide-demo';
if (!is_file($app . '/config.php')) { fwrite(STDERR, "62-crm: no config.php in '$app'. Set RIVETIT_APP_DIR.\n"); exit(1); }
chdir($app . '/scripts');
require_once '../config.php';
require_once '../functions.php';
require_once '../agent/modals/crm/crm_helpers.php';

function q($sql) { global $mysqli; if (!mysqli_query($mysqli, $sql)) { fwrite(STDERR, "62-crm: " . mysqli_error($mysqli) . "\n  $sql\n"); exit(1); } }
function one($sql) { global $mysqli; $r = mysqli_query($mysqli, $sql); $row = $r ? mysqli_fetch_row($r) : null; return $row ? $row[0] : null; }
// The app shows times in the company time zone; make NOW() and friends in this seed agree with it.
mysqli_query($mysqli, "SET time_zone = '" . (new DateTime('now', new DateTimeZone('America/Chicago')))->format('P') . "'");

function esc($s) { global $mysqli; return mysqli_real_escape_string($mysqli, $s); }

// ---- 1. contact tag ----------------------------------------------------------------------------
$tag = one("SELECT tag_id FROM tags WHERE tag_name = 'Security newsletter' AND tag_type = 3");
if (!$tag) {
    q("INSERT INTO tags SET tag_name = 'Security newsletter', tag_type = 3, tag_color = 'teal', tag_icon = 'envelope'");
    $tag = mysqli_insert_id($mysqli);
}
$tagged = ['Dr. Priya Raman', 'Hannah Brooks', 'Margaret Birchwood', 'Theo Lindqvist', 'Robert Kowalski', 'Marcus Webb',
           'Elena Voss', 'Gordon Pike', 'Oliver Cedar', 'Frank Delaney'];
foreach ($tagged as $name) {
    $cid = one("SELECT contact_id FROM contacts WHERE contact_name = '" . esc($name) . "' LIMIT 1");
    if ($cid) { q("INSERT IGNORE INTO contact_tags SET contact_id = $cid, tag_id = $tag"); }
}

// ---- 2. segments ---------------------------------------------------------------------------------
$segments = [
    'All leads'                  => ['lead_status' => 'lead'],
    'Customers with an open deal' => ['lead_status' => 'customer', 'has_open_opportunity' => 1],
    'Security newsletter'        => ['tag_id' => (int) $tag],
];
$seg = [];
foreach ($segments as $name => $criteria) {
    $id = one("SELECT segment_id FROM crm_segments WHERE segment_name = '" . esc($name) . "'");
    if (!$id) {
        q("INSERT INTO crm_segments SET segment_name = '" . esc($name) . "', segment_criteria_json = '" . esc(json_encode($criteria)) . "', segment_created_at = DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $id = mysqli_insert_id($mysqli);
    }
    $seg[$name] = (int) $id;
}

// ---- 3. outbound From address (demo domain; mail is never delivered) --------------------------------
q("UPDATE settings SET config_mail_from_email = 'support@lakeshoremsp.example', config_mail_from_name = 'Lakeshore Managed IT'
   WHERE company_id = 1 AND (config_mail_from_email IS NULL OR config_mail_from_email = '')");
$from = one('SELECT config_mail_from_email FROM settings WHERE company_id = 1');
$from_name = one('SELECT config_mail_from_name FROM settings WHERE company_id = 1');
$company = one('SELECT company_name FROM companies WHERE company_id = 1');

// ---- 4. campaigns ---------------------------------------------------------------------------------
$sent_name = 'Fall security checkup';
if (!one("SELECT campaign_id FROM crm_campaigns WHERE campaign_name = '" . esc($sent_name) . "'")) {
    $subject = 'Is your business ready for the holiday phishing season?';
    $body = "<p>Hello,</p><p>Phishing e-mails rise every year between now and the end of December. Here are three quick checks you can do this week:</p>"
          . "<ul><li>Turn on multi-factor sign-in for e-mail and your accounting software.</li><li>Review who still has access to shared mailboxes.</li><li>Remind your team never to approve a payment request received by e-mail alone.</li></ul>"
          . "<p>Reply to this message if you would like us to run a free 30-minute checkup.</p><p>Lakeshore Managed IT</p>";
    q("INSERT INTO crm_campaigns SET campaign_name = '" . esc($sent_name) . "', campaign_segment_id = " . $seg['Security newsletter'] . ",
        campaign_subject = '" . esc($subject) . "', campaign_body = '" . esc($body) . "', campaign_status = 'draft',
        campaign_created_at = DATE_SUB(NOW(), INTERVAL 9 DAY)");
    $cid = mysqli_insert_id($mysqli);

    $criteria = crmCriteriaFromJson(one("SELECT segment_criteria_json FROM crm_segments WHERE segment_id = " . $seg['Security newsletter']));
    $recipients = crmResolveContacts($mysqli, $criteria, '', true, true);
    $footer = "<br><br><hr><small style=\"color:#888\">You are receiving this message because you are a contact of $company. "
            . "If you would prefer not to receive these emails, please reply to this message and let us know.</small>";
    $batch = [];
    foreach ($recipients as $r) {
        $email = trim((string) $r['contact_email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
        q("INSERT INTO crm_campaign_recipients SET campaign_id = $cid, contact_id = " . (int) $r['contact_id'] . ", email = '" . esc($email) . "', sent = 1");
        $batch[] = ['from' => $from, 'from_name' => $from_name, 'recipient' => $email, 'recipient_name' => (string) $r['contact_name'],
                    'subject' => $subject, 'body' => $body . $footer];
    }
    if ($batch) { addToMailQueue($batch); }
    q("UPDATE crm_campaigns SET campaign_status = 'sent', campaign_sent_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE campaign_id = $cid");
    echo "62-crm: sent campaign '$sent_name' to " . count($batch) . " recipient(s)\n";
}

$draft_name = 'Welcome to Lakeshore Managed IT';
if (!one("SELECT campaign_id FROM crm_campaigns WHERE campaign_name = '" . esc($draft_name) . "'")) {
    $body = "<p>Hello,</p><p>Thank you for talking with us about your IT support. A short summary of what a managed service plan from Lakeshore Managed IT includes:</p>"
          . "<ul><li>Help desk by phone, e-mail and remote session</li><li>Monitoring and patching of every computer</li><li>Backups that are tested every quarter</li></ul>"
          . "<p>If you would like a written quote, reply to this message with the number of computers and users.</p><p>Lakeshore Managed IT</p>";
    q("INSERT INTO crm_campaigns SET campaign_name = '" . esc($draft_name) . "', campaign_segment_id = " . $seg['All leads'] . ",
        campaign_subject = 'Thank you for talking with us', campaign_body = '" . esc($body) . "', campaign_status = 'draft',
        campaign_created_at = DATE_SUB(NOW(), INTERVAL 1 DAY)");
}
echo "62-crm: done\n";
