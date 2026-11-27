<?php
// MSP-DS-16/33 (rest): inbound auto-filing by sender DOMAIN needs the admin-set per-domain 'route inbound mail' flag. Run:
//   SCRATCH_TABLES="domains contacts" bash tests/lib/with_scratch_db.sh php tests/msp_ds2_16_33.php
require __DIR__ . '/lib/ds_src.php';
function blk($src, $marker, $len = 2500) { $i = strpos($src, $marker); return $i === false ? '' : substr($src, $i, $len); }
// ---- handler / parser / UI wiring
$p = ds_src('cron/ticket_email_parser.php');
ds_t('parser: both domain lookups ask for routable domains only', substr_count($p, 'inboundDomainOwnerClient($mysqli, $from_domain, true)') === 2 && substr_count($p, 'inboundDomainOwnerClient(') === 2);
$d = ds_src('agent/post/domain.php');
$add = blk($d, "isset(\$_POST['add_domain'])", 3500);
ds_t('add_domain: flag only for admins', strpos($add, '$route_mail = ($session_is_admin && !empty($_POST[\'route_mail\'])) ? 1 : 0;') !== false && strpos($add, 'INSERT INTO domains SET domain_route_mail = $route_mail') !== false);
$edit = blk($d, "isset(\$_POST['edit_domain'])", 4500);
ds_t('edit_domain: admin sets it, non-admin keeps it and a rename clears it', strpos($edit, 'if ($session_is_admin) {') !== false && strpos($edit, 'strcasecmp((string) ($original_domain_info[\'domain_name\']') !== false && strpos($edit, 'UPDATE domains SET domain_route_mail = $route_mail') !== false);
ds_t('edit_domain: original row is read before the UPDATE', strpos($edit, '$original_domain_info = ') < strpos($edit, 'UPDATE domains SET domain_route_mail'));
ds_t('client creation: website domain routes only when an admin created the client', strpos(ds_src('agent/post/client.php'), '$route_mail = $session_is_admin ? 1 : 0;') !== false);
ds_t('edit modal has the toggle, disabled for non-admins', preg_match('/name="route_mail".*?\$session_is_admin\) \{ echo \'disabled\'/s', ds_src('agent/modals/domain/domain_edit.php')) === 1);
ds_t('add modal has the toggle, disabled for non-admins', preg_match('/name="route_mail".*?\$session_is_admin\) \{ echo \'disabled\'/s', ds_src('agent/modals/domain/domain_add.php')) === 1);
// ---- the real routing functions on a real DB
if (getenv('RIVETMSP_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETMSP_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = new mysqli('localhost', getenv('RIVETMSP_TEST_DB_USER'), getenv('RIVETMSP_TEST_DB_PASS'), getenv('RIVETMSP_TEST_DB_NAME'));
    $m->query("SET SESSION sql_mode=''");
    require __DIR__ . '/../includes/inbound_routing.php';
    foreach (['domains', 'contacts'] as $t) { $m->query("DELETE FROM $t"); }
    $m->query("INSERT INTO domains (domain_name, domain_client_id, domain_route_mail) VALUES
        ('flagged.example', 1, 1), ('unflagged.example', 2, 0), ('shared.example', 3, 1), ('shared.example', 4, 0), ('gmail.com', 5, 1)");
    $m->query("INSERT INTO domains (domain_name, domain_client_id, domain_route_mail, domain_archived_at) VALUES ('old.example', 6, 1, NOW())");
    ds_t('routable: flagged, single-owner domain routes to its client', inboundDomainOwnerClient($m, 'flagged.example', true) === 1);
    ds_t('routable: unflagged domain does NOT route', inboundDomainOwnerClient($m, 'unflagged.example', true) === 0);
    ds_t('ownership (conflict checks) still sees the unflagged domain', inboundDomainOwnerClient($m, 'unflagged.example') === 2);
    ds_t('routable: domain listed by two clients does not route even if one flagged it', inboundDomainOwnerClient($m, 'shared.example', true) === 0);
    ds_t('routable: public mailbox provider never routes, flag or not', inboundDomainOwnerClient($m, 'gmail.com', true) === 0);
    ds_t('routable: archived listing does not route', inboundDomainOwnerClient($m, 'old.example', true) === 0);
    ds_t('routable: case-insensitive input', inboundDomainOwnerClient($m, 'FLAGGED.example', true) === 1);
    // a claim by contact of client 1 on a domain solely owned (unflagged) by client 2 is still refused (ownership, not routing)
    $m->query("INSERT INTO contacts (contact_name, contact_email, contact_client_id) VALUES ('x', 'a@unflagged.example', 1)");
    ds_t('contact whose domain is solely another client\'s stays untrusted even if the domain is unflagged', inboundTrustedContact($m, 'a@unflagged.example') === null);
}
ds_done();
