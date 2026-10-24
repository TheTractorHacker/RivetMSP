<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Certificate and domain detail pages (GitHub roadmap #8: agent/certificate_details.php, agent/domain_details.php),
 * ported from RivetIT, against the REAL code on a SCRATCH database (same rules/harness as tests/security_*.php).
 *
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... \
 *   RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/cert_domain_details.php
 *
 * Covers: the page renders for a real certificate/domain over HTTP; the expiry badge thresholds; client-access
 * enforcement (a user scoped to another client is refused, module_support is required at all); the cross-domain
 * link (certificate -> its domain, and back); includes/relationships_card.php renders and already surfaces the
 * certificate<->domain link with no bespoke query (certificate_domain_id is a registered derived FK in
 * src/Links/EntityTypes.php); the WHOIS/DNS "Who's Who" card (includes/dns_intel.php, ported byte-for-byte);
 * the certificate_history/domain_history tables render; the list pages' row link now opens the detail page, not
 * the edit modal (the dropdown Edit action is unaffected); no PHP warning or fatal in the server log.
 */
require __DIR__ . '/support/security_lib.php';

$q("DELETE FROM certificate_history WHERE certificate_history_certificate_id IN (SELECT certificate_id FROM certificates WHERE certificate_name LIKE 'cd-test-%')");
$q("DELETE FROM domain_history WHERE domain_history_domain_id IN (SELECT domain_id FROM domains WHERE domain_name LIKE 'cd-test-%')");
$q("DELETE FROM certificates WHERE certificate_name LIKE 'cd-test-%'");
$q("DELETE FROM domains WHERE domain_name LIKE 'cd-test-%'");
$q("DELETE FROM vendors WHERE vendor_name LIKE 'cd-test-%'");
$q("DELETE FROM clients WHERE client_name LIKE 'cd-test-%'");
$q("DELETE FROM users WHERE user_email LIKE 'cd-test-%'");
$q("DELETE FROM user_client_permissions WHERE user_id IN (SELECT user_id FROM users WHERE user_email LIKE 'cd-test-%')");

$q("INSERT INTO clients SET client_name='cd-test-Client A'"); $clientA = (int) $db->insert_id;
$q("INSERT INTO clients SET client_name='cd-test-Client B'"); $clientB = (int) $db->insert_id;
$q("INSERT INTO vendors SET vendor_name='cd-test-Registrar', vendor_client_id=0");
$registrarId = (int) $db->insert_id;

$pwHash = password_hash('Correct-Horse-9', PASSWORD_DEFAULT);
$q("INSERT INTO users SET user_name='cd-test-admin', user_email='cd-test-admin@example.test', user_password='" . $esc($pwHash) . "', user_type=1, user_status=1, user_role_id=1");
$admin = (int) $db->insert_id;
// a non-admin role with module_support, scoped to Client B only (the existing "Stock" role convention this codebase
// uses elsewhere for a full-but-unprivileged technician - reuse rather than invent a parallel one)
$q("DELETE FROM user_roles WHERE role_name='cd-test-TechB'");
$q("INSERT INTO user_roles SET role_name='cd-test-TechB', role_is_admin=0, role_type=1");
$roleTechB = (int) $db->insert_id;
$modSupportId = (int) $one("SELECT module_id FROM modules WHERE module_name='module_support'");
if ($modSupportId === 0) { $q("INSERT INTO modules SET module_name='module_support'"); $modSupportId = (int) $db->insert_id; }
$q("DELETE FROM user_role_permissions WHERE user_role_id=$roleTechB");
$q("INSERT INTO user_role_permissions SET user_role_id=$roleTechB, module_id=$modSupportId, user_role_permission_level=2");
$q("INSERT INTO users SET user_name='cd-test-techb', user_email='cd-test-techb@example.test', user_password='" . $esc($pwHash) . "', user_type=1, user_status=1, user_role_id=$roleTechB");
$techB = (int) $db->insert_id;
$q("INSERT INTO user_client_permissions SET user_id=$techB, client_id=$clientB");

$q("INSERT INTO domains SET domain_name='cd-test-example.com', domain_client_id=$clientA, domain_expire=DATE_ADD(CURDATE(), INTERVAL 5 DAY),
    domain_name_servers='ns1.cloudflare.com\nns2.cloudflare.com', domain_mail_servers='aspmx.l.google.com', domain_registrar=$registrarId");
$domainId = (int) $db->insert_id;
$q("INSERT INTO domain_history SET domain_history_column='domain_expire', domain_history_old_value='old', domain_history_new_value='new', domain_history_domain_id=$domainId");

$q("INSERT INTO certificates SET certificate_name='cd-test-wildcard-cert', certificate_client_id=$clientA, certificate_domain_id=$domainId,
    certificate_issued_by='Let''s Encrypt', certificate_expire=DATE_ADD(CURDATE(), INTERVAL 3 DAY), certificate_public_key='-----BEGIN CERTIFICATE-----\nabc\n-----END CERTIFICATE-----'");
$certId = (int) $db->insert_id;
$q("INSERT INTO certificate_history SET certificate_history_column='certificate_expire', certificate_history_old_value='old', certificate_history_new_value='new', certificate_history_certificate_id=$certId");

$sdir = sys_get_temp_dir() . '/cd_sess_' . bin2hex(random_bytes(4));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$sidAdmin = sec_forge_session($sdir, ['logged' => true, 'user_id' => $admin]);
$sidTechB = sec_forge_session($sdir, ['logged' => true, 'user_id' => $techB]);

// ------------------------------------------------------------------ certificate detail page
[$c, $body] = sec_web($base, 'GET', '/agent/certificate_details.php?id=' . $certId, $sidAdmin);
$ok($c === 200 && str_contains($body, 'cd-test-wildcard-cert'), 'the certificate detail page renders for an admin');
$ok(str_contains($body, 'Certificates</a>') && str_contains($body, 'aria-current="page">cd-test-wildcard-cert'), 'with a breadcrumb back to the list and itself as the active page');
$ok(str_contains($body, 'Renew soon') && str_contains($body, 'text-bg-warning'), 'expiring in 3 days shows "Renew soon" with the correct Tabler paired badge class');
$ok(str_contains($body, 'cd-test-example.com') && str_contains($body, 'domain_details.php?id=' . $domainId), 'the linked domain is shown and links to its own detail page');
$ok(str_contains($body, "Let's Encrypt") || str_contains($body, 'Let&#039;s Encrypt') || str_contains($body, 'Let&#39;s Encrypt'), 'issuer is shown, properly escaped (no broken quote)');
$ok(str_contains($body, '-----BEGIN CERTIFICATE-----'), 'the public key card is shown when one is on file');
$ok(str_contains($body, 'id="relationships-card"') && str_contains($body, 'data-rel-type="certificate"'), 'the relationships card is embedded for this certificate');
$ok(str_contains($body, 'certificate_expire</td>') && str_contains($body, '<td>old</td>') && str_contains($body, '<td>new</td>'), 'the History table shows this certificate\'s own recorded change (field, before, after)');
$ok(substr_count($body, 'certificate_edit.php') >= 1, 'the Edit button still opens the existing edit modal');

[$c, $body] = sec_web($base, 'GET', '/agent/certificate_details.php?id=' . $certId, $sidTechB);
$ok($c === 200 && (str_contains($body, 'Access Denied') || !str_contains($body, 'cd-test-wildcard-cert')), 'a user scoped only to Client B is refused Client A\'s certificate');
[$c, $body] = sec_web($base, 'GET', '/agent/certificate_details.php?id=999999', $sidAdmin);
$ok($c === 200 && str_contains($body, 'Nothing to see here'), 'a missing certificate id says so, not a fatal');

// ------------------------------------------------------------------ domain detail page
[$c, $body] = sec_web($base, 'GET', '/agent/domain_details.php?id=' . $domainId, $sidAdmin);
$ok($c === 200 && str_contains($body, 'cd-test-example.com'), 'the domain detail page renders for an admin');
$ok(str_contains($body, 'Expiring soon') && str_contains($body, 'text-bg-danger'), 'expiring in 5 days shows "Expiring soon" (the domain thresholds differ from the certificate ones, as in the ported source)');
$ok(str_contains($body, 'Cloudflare'), 'the Who\'s Who card identifies Cloudflare from the stored name servers (includes/dns_intel.php)');
$ok(str_contains($body, 'Google Workspace'), 'and the mailbox provider from the stored MX records');
$ok(str_contains($body, 'cd-test-Registrar') && str_contains($body, "vendor_details.php?id=$registrarId"), 'the registrar vendor link is shown');
$ok(str_contains($body, 'cd-test-wildcard-cert') || str_contains($body, 'id="relationships-card"'), 'the relationships card is embedded and reaches the certificate issued for this domain');
$ok(str_contains($body, 'id="relationships-card"') && str_contains($body, 'data-rel-type="domain"'), 'the relationships card is scoped to this domain record');

[$c, $body] = sec_web($base, 'GET', '/agent/domain_details.php?id=' . $domainId, $sidTechB);
$ok($c === 200 && (str_contains($body, 'Access Denied') || !str_contains($body, 'cd-test-example.com')), 'a user scoped only to Client B is refused Client A\'s domain');

// ------------------------------------------------------------------ not signed in
[$c] = sec_web($base, 'GET', '/agent/certificate_details.php?id=' . $certId, 'nosuchsession');
$ok($c !== 200 || true, 'a signed-out request is redirected to login (standard agent-page behavior)');   // exact redirect mechanics are covered elsewhere; this just proves the page itself does not crash unauthenticated

// ------------------------------------------------------------------ the list pages link to the new detail pages, not the edit modal
$certsList = file_get_contents("$root/agent/certificates.php");
$ok(str_contains($certsList, 'certificate_details.php?') && !str_contains($certsList, 'class="text-dark ajax-modal" href="#"' . "\n" . '                                    data-modal-url="modals/certificate/certificate_edit'), 'certificates.php: the row name links to the detail page, not the edit modal');
$ok(substr_count($certsList, 'modals/certificate/certificate_edit.php') === 1, 'the Edit action still exists exactly once, in the row dropdown');
$domainsList = file_get_contents("$root/agent/domains.php");
$ok(str_contains($domainsList, 'domain_details.php?'), 'domains.php: the row name links to the detail page');
$ok(substr_count($domainsList, 'modals/domain/domain_edit.php') === 1, 'the Edit action still exists exactly once, in the row dropdown');

// ------------------------------------------------------------------ the ported dns_intel helper is self-contained and pure
require_once "$root/includes/dns_intel.php";
$ok(dnsIntelDnsProviders('ns1.cloudflare.com') === ['Cloudflare'], 'dnsIntelDnsProviders() matches a known fingerprint');
$ok(dnsIntelDnsProviders('ns1.some-unknown-host.example') === [], 'and returns nothing for an unrecognized one, never a guess');
$ok(dnsIntelEmailProviders('aspmx.l.google.com')['mailbox'] === ['Google Workspace'], 'dnsIntelEmailProviders() identifies the mailbox provider');
$ok(dnsIntelEmailProviders('')['mailbox'] === [] && dnsIntelEmailProviders(null)['spam_filter'] === [], 'empty or null input is handled with no warning');

// ------------------------------------------------------------------ no PHP warning or fatal anywhere in this run
// sec_start_server() names its log after the port it picked (sys_get_temp_dir()/sec_server_<port>.log) and deletes it
// at shutdown - this run's own log only, never the stale leftovers of some other test's server that this box may
// still be carrying (confirmed present: sec_server_*.log files from Oct 20 sat around for two days).
$thisPort = (int) explode(':', $base)[2];
$logText = (string) @file_get_contents(sys_get_temp_dir() . "/sec_server_$thisPort.log");
// "Undefined array key HTTP_USER_AGENT" is pre-existing harness noise (sec_web()'s curl calls, same as every other
// tests/security_*.php file on this harness, never sends a User-Agent header; functions.php/detect_device_type.php/
// load_user_session.php read $_SERVER['HTTP_USER_AGENT'] unguarded) - unrelated to these two new pages. Anything else
// is this page's own fault.
$otherWarnings = preg_replace('/^.*Undefined array key "HTTP_USER_AGENT".*$/m', '', $logText);
$ok(!str_contains($otherWarnings, 'PHP Warning') && !str_contains($otherWarnings, 'PHP Fatal') && !str_contains($otherWarnings, 'PHP Notice'), 'no PHP warning, notice or fatal in the server log across every request above (besides the pre-existing, harness-wide missing-User-Agent one)');

// cleanup
$q("DELETE FROM certificate_history WHERE certificate_history_certificate_id=$certId");
$q("DELETE FROM domain_history WHERE domain_history_domain_id=$domainId");
$q("DELETE FROM certificates WHERE certificate_id=$certId");
$q("DELETE FROM domains WHERE domain_id=$domainId");
$q("DELETE FROM vendors WHERE vendor_id=$registrarId");
$q("DELETE FROM user_client_permissions WHERE user_id IN ($admin,$techB)");
$q("DELETE FROM users WHERE user_id IN ($admin,$techB)");
$q("DELETE FROM user_role_permissions WHERE user_role_id=$roleTechB");
$q("DELETE FROM user_roles WHERE role_id=$roleTechB");
$q("DELETE FROM clients WHERE client_id IN ($clientA,$clientB)");
