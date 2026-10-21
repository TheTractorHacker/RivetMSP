<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Email Templates (includes/mail_templates.php, admin/settings_mail_templates.php, DB 2.6.107), against the REAL code on a SCRATCH database:
 *   RIVETMSP_TEST_DB=1 RIVETMSP_TEST_DB_NAME=..scratch.. RIVETMSP_TEST_DB_USER=... RIVETMSP_TEST_DB_PASS=... RIVETMSP_REDIS_HOST=127.0.0.1 \
 *   RIVETMSP_REDIS_PORT=<throwaway> RIVETMSP_REDIS_ENV_FILE=/dev/null php tests/mail_templates.php     (rules of tests/endpoint_agent_lib.php)
 * The JS<->PHP compiler identity is tests/mail_template_compile.php (DB-free); the page itself in a real browser is
 * tests/browser/mail_templates_smoke.mjs.
 *
 * Covers: THE byte-identity of every default against the hardcoded text it replaced (the old source strings below are verbatim copies
 * from commit 7db8e7e2b, checked against git when it is available) - at the renderer AND at every real call site (the real HTTP handlers,
 * cron, the email parser, the client portal reset page), watchers included; HTML-escaping vs the RAW_KEYS trust boundary; the builder
 * comment never leaking into an email; enabled/disabled through a real send; custom edit + Reset to default through the real handlers;
 * branding (theme preset / custom hex / unknown theme / logo / custom header) resolved at send time; the reply marker (save warnings,
 * the real parser round trip through QuotedTextStripper and addReply()); the updater step, db.sql parity and the untouched-row re-sync;
 * code deployed before the DB step; permissions (non-admin page and handlers, CSRF); the mail queue left unchanged.
 */
$sd = sys_get_temp_dir() . '/mt_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd"); putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
$sdir = sys_get_temp_dir() . '/mt_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sd, $sdir) { foreach ([$sd, $sdir] as $d) { foreach (glob("$d/*") ?: [] as $f) { @unlink($f); } @rmdir($d); } });
$BASE_COMMIT = '7db8e7e2b';   // the beta tip this feature branched from: the hardcoded emails as they were

// ---------------------------------------------------------------------------------------------------------------------------------------------
// The text every call site sent before Email Templates, VERBATIM (PHP double-quoted string sources, so \" is a quote and \' stays \').
$LEGACY = [
    // agent/post/ticket.php, edit_ticket "notify the contact" (edit_ticket_contact has the identical text)
    'ticket_created' => ['file' => 'agent/post/ticket.php', 'subject' => <<<'SRC'
Ticket Created - [$ticket_prefix$ticket_number] - $ticket_subject
SRC, 'body' => <<<'SRC'
<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>A ticket regarding \"$ticket_subject\" has been created for you.<br><br>--------------------------------<br>$ticket_details--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
    // agent/post/ticket.php, add_ticket_reply, not resolving (bulk_ticket_reply: same text)
    'ticket_reply' => ['file' => 'agent/post/ticket.php', 'subject' => <<<'SRC'
Ticket update - [$ticket_prefix$ticket_number] - $ticket_subject
SRC, 'body' => <<<'SRC'
<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been updated.<br><br>--------------------------------<br>$ticket_reply<br>--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status_name<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
    // agent/post/ticket.php, add_ticket_reply that resolves: the reply quoted
    'ticket_resolved' => ['file' => 'agent/post/ticket.php', 'subject' => <<<'SRC'
Ticket resolved - [$ticket_prefix$ticket_number] - $ticket_subject
SRC, 'body' => <<<'SRC'
<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been resolved and closed.<br><br>--------------------------------<br>$ticket_reply<br>--------------------------------<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>$csat_rating_html<br>If you need further assistance, please reply or <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>re-open</a> to let us know! <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status_name<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
    // agent/post/ticket.php, resolve_ticket (agent/ajax.php kanban: identical)
    'ticket_resolved_noreply' => ['file' => 'agent/post/ticket.php', 'subject' => <<<'SRC'
Ticket resolved - [$ticket_prefix$ticket_number] - $ticket_subject
SRC, 'body' => <<<'SRC'
<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been resolved and closed.<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>$csat_rating_html<br>If you need further assistance, please reply or <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>re-open</a> to let us know! <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
    // agent/post/ticket.php, close_ticket
    'ticket_closed' => ['file' => 'agent/post/ticket.php', 'subject' => <<<'SRC'
Ticket closed - [$ticket_prefix$ticket_number] - $ticket_subject | (do not reply)
SRC, 'body' => <<<'SRC'
Hello $contact_name,<br><br>Your ticket regarding \"$ticket_subject\" has been closed. <br><br> We hope the request/issue was resolved to your satisfaction - how would you rate it?<br>$csat_rating_html<a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>Or click here to leave feedback</a>. <br>If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/client/ticket.php?id=$ticket_id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
    // agent/post/ticket.php, assign_ticket
    'ticket_assigned' => ['file' => 'agent/post/ticket.php', 'subject' => <<<'SRC'
$config_app_name - Ticket $ticket_prefix$ticket_number assigned to you - $ticket_subject
SRC, 'body' => <<<'SRC'
Hi $agent_name, <br><br>A ticket has been assigned to you!<br><br>Client: $client_name<br>Ticket Number: $ticket_prefix$ticket_number<br> Subject: $ticket_subject<br><br>https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id$client_uri <br><br>Thanks, <br>$session_name<br>$company_name
SRC],
    // client/login_reset.php, reset link
    'password_reset' => ['file' => 'client/login_reset.php', 'subject' => <<<'SRC'
Password reset for $company_name Client Portal
SRC, 'body' => <<<'SRC'
Hello $name,<br><br>Someone (probably you) has requested a new password for your account on $company_name\'s Client Portal.<br><br><b>Please <a href=\'$url\'>click here</a> to reset your password.</b> <br><br>Alternatively, copy and paste this URL into your browser:<br> $url<br><br><i>If you didn\'t request this change, you can safely ignore this email.</i><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
    // client/login_reset.php, reset confirmation
    'password_changed' => ['file' => 'client/login_reset.php', 'subject' => <<<'SRC'
Password reset confirmation for $company_name Client Portal
SRC, 'body' => <<<'SRC'
Hello $name,<br><br>Your password for your account on $company_name\'s Client Portal was successfully reset. You should be all set! <br><br><b>If you didn\'t reset your password, please get in touch ASAP.</b><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone
SRC],
];

/** Evaluates one of those sources exactly as PHP did: \" \\ \$ unescaped, any other backslash kept, every $name interpolated. */
function mt_legacy(string $src, array $vars): string {
    return (string) preg_replace_callback('/\\\\(["\\\\$])|\$([A-Za-z_][A-Za-z0-9_]*)/', static function (array $m) use ($vars): string {
        if (($m[1] ?? '') !== '') { return $m[1]; }
        if (!array_key_exists($m[2], $vars)) { throw new RuntimeException('legacy source needs $' . $m[2]); }
        return (string) $vars[$m[2]];
    }, $src);
}
$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/**
 * One event's expected email, built from the OLD source: $f are the facts (ticket number, names, links' parts ...). Values are HTML-escaped
 * as the renderer does (a no-op for the plain values the call-site checks use); ticket details/reply and the CSAT links stay raw. Then the
 * three deliberate, documented differences: the old sources' stray backslashes (style=\'...\', didn\'t) are gone; a link's & is &amp;
 * (the whole URL is one escaped placeholder now); the "-----" fence around details/reply is the Quote box. Returns [subject, inner body].
 */
$expected = function (string $key, array $f, string $accent) use ($h): array {
    global $LEGACY;
    $raw = ['ticket_details', 'ticket_reply', 'csat_rating_html'];
    $v = [];
    foreach ($f as $k => $val) { $v[$k] = in_array($k, $raw, true) ? (string) $val : $h((string) $val); }
    $body = mt_legacy($LEGACY[$key]['body'], $v);
    $body = str_replace(["\\'", '&url_key='], ["'", '&amp;url_key='], $body);
    $q = mailTemplateQuoteOpen($accent);
    if (isset($f['ticket_details'])) { $body = str_replace('--------------------------------<br>' . $f['ticket_details'] . '--------------------------------', $q . $f['ticket_details'] . '</div>', $body); }
    if (isset($f['ticket_reply'])) { $body = str_replace('--------------------------------<br>' . $f['ticket_reply'] . '<br>--------------------------------', $q . $f['ticket_reply'] . '</div>', $body); }
    return [mt_legacy($LEGACY[$key]['subject'], $f), $body];
};
/** The placeholder values a call site passes for the same facts. */
$varsFor = function (string $event, array $f): array {
    $vars = [
        'contact_name' => $f['contact_name'] ?? '', 'ticket_number' => ($f['ticket_prefix'] ?? '') . ($f['ticket_number'] ?? ''), 'subject' => $f['ticket_subject'] ?? '',
        'status' => $f['ticket_status'] ?? ($f['ticket_status_name'] ?? ''),
        'portal_link' => 'https://' . ($f['config_base_url'] ?? '') . '/guest/guest_view_ticket.php?ticket_id=' . ($f['ticket_id'] ?? '') . '&url_key=' . ($f['url_key'] ?? ''),
        'client_portal_link' => 'https://' . ($f['config_base_url'] ?? '') . '/client/ticket.php?id=' . ($f['ticket_id'] ?? ''),
        'company_name' => $f['company_name'] ?? '', 'company_phone' => $f['company_phone'] ?? '', 'from_email' => $f['config_ticket_from_email'] ?? '',
    ];
    foreach (['ticket_details', 'ticket_reply', 'csat_rating_html'] as $k) { if (isset($f[$k])) { $vars[$k] = $f[$k]; } }
    if ($event === 'ticket_assigned') {
        $vars = ['technician_name' => $f['agent_name'], 'client_name' => $f['client_name'], 'ticket_number' => $f['ticket_prefix'] . $f['ticket_number'], 'subject' => $f['ticket_subject'],
            'agent_link' => 'https://' . $f['config_base_url'] . '/agent/ticket.php?ticket_id=' . $f['ticket_id'] . $f['client_uri'], 'assigned_by_name' => $f['session_name'],
            'company_name' => $f['company_name'], 'app_name' => $f['config_app_name']];
    }
    if ($event === 'password_reset' || $event === 'password_changed') {
        $vars = ['contact_name' => $f['name'], 'portal_link' => $f['url'] ?? '', 'company_name' => $f['company_name'], 'company_phone' => $f['company_phone'], 'from_email' => $f['config_ticket_from_email']];
    }
    return $vars;
};
$mtDefault = fn(string $event) => mailTemplateDefaults()[$event];
$innerOf = fn(string $event, array $vars, string $accent) => mailTemplateRenderParts($mtDefault($event)['subject'], $mtDefault($event)['body'], $vars, $accent);

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- the old sources are verbatim (checked against git $BASE_COMMIT) ---\n";
$gitShow = function (string $file) use ($root, $BASE_COMMIT): ?string {
    $out = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg("$BASE_COMMIT:$file") . ' 2>/dev/null');
    return is_string($out) && $out !== '' ? $out : null;
};
foreach ($LEGACY as $key => $src) {
    $old = $gitShow($src['file']);
    if ($old === null) { echo "SKIP  $key: git or commit $BASE_COMMIT not available\n"; continue; }
    $ok(str_contains($old, '"' . $src['subject'] . '";') && str_contains($old, '"' . $src['body'] . '";'), "$key: subject and body sources appear verbatim in $BASE_COMMIT:{$src['file']}");
}

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- THE byte-identity: every default renders exactly the text its hardcoded call site sent ---\n";
$accent = '#2563EB';
$facts = [
    'ticket_prefix' => 'T', 'ticket_number' => '1042', 'ticket_id' => '77', 'url_key' => 'k3y9', 'config_base_url' => 'support.example.test',
    'contact_name' => 'Jane Roe', 'ticket_subject' => 'Printer down on floor 3', 'ticket_status' => 'Open', 'ticket_status_name' => 'Open',
    'company_name' => 'Scratch MSP', 'company_phone' => '555-0100', 'config_ticket_from_email' => 'support@msp.example',
    'ticket_details' => '<p>Paper jam in tray 2</p>', 'ticket_reply' => '<p>Cleared the jam</p>',
    'csat_rating_html' => '<table role="presentation"><tr><td><a href="https://support.example.test/guest/guest_rate.php?ticket_id=77&amp;url_key=k3y9&amp;rating=1">1</a></td></tr></table>',
    'agent_name' => 'Sam Tech', 'client_name' => 'Client A', 'client_uri' => '&client_id=1', 'session_name' => 'Alex Admin', 'config_app_name' => 'RivetMSP',
    'name' => 'Pat Portal', 'url' => 'https://support.example.test/client/login_reset.php?email=pat@client.example&token=abc123&client=1',
];
// [legacy source, event, fact overrides]
$identity = [
    ['ticket_created', 'ticket_created', []],
    ['ticket_reply', 'ticket_reply', []],
    ['ticket_resolved', 'ticket_resolved', ['ticket_status_name' => 'Closed', 'ticket_status' => 'Closed']],
    ['ticket_resolved_noreply', 'ticket_resolved', ['ticket_reply' => '', 'ticket_status' => 'Closed']],
    ['ticket_closed', 'ticket_closed', []],
    ['ticket_assigned', 'ticket_assigned', []],
    ['password_reset', 'password_reset', []],
    ['password_changed', 'password_changed', []],
];
foreach ([['plain values', []], ['values with HTML special characters', ['contact_name' => "Jane O'Roe <b>&</b>", 'ticket_subject' => 'Printer "down" & <out>', 'company_name' => 'Smith & Sons <IT>', 'agent_name' => "Sam 'Tech'"]]] as [$label, $extra]) {
    foreach ($identity as [$key, $event, $over]) {
        $f = array_merge($facts, $extra, $over);
        if ($key === 'ticket_resolved_noreply') { unset($f['ticket_reply']); }
        $vars = $varsFor($event, $f);
        if ($key === 'ticket_resolved_noreply') { $vars['ticket_reply'] = ''; }
        [$expSubject, $expBody] = $expected($key, $f, $accent);
        $got = $innerOf($event, $vars, $accent);
        $ok($got['body'] === $expBody, "$key ($label): rendered default body === the old hardcoded body" . ($got['body'] === $expBody ? '' : "\n   expected: $expBody\n   got:      {$got['body']}"));
        $ok($got['subject'] === $expSubject, "$key ($label): rendered default subject === the old hardcoded subject" . ($got['subject'] === $expSubject ? '' : " (expected '$expSubject', got '{$got['subject']}')"));
    }
}
$plain = $innerOf('ticket_created', $varsFor('ticket_created', $facts), $accent)['body'];
$oldLiteral = mt_legacy($LEGACY['ticket_created']['body'], $facts);
$ok(str_replace(["\\'", '&url_key=', '--------------------------------<br><p>Paper jam in tray 2</p>--------------------------------'], ["'", '&amp;url_key=', mailTemplateQuoteOpen($accent) . '<p>Paper jam in tray 2</p></div>'], $oldLiteral) === $plain,
    'with plain values the ONLY differences from the literal old output are: no stray backslashes, & -> &amp; in the link, the Quote box');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- fixtures ---\n";
ea_reset(); ea_seed_users();
foreach (['email_queue', 'ticket_watchers', 'contacts', 'ticket_statuses', 'recurring_tickets', 'mail_templates', 'ticket_history'] as $t) { $q("DELETE FROM `$t`"); }
$q("INSERT INTO companies SET company_id = 1, company_name = 'Scratch MSP', company_phone = '5550100', company_logo = NULL ON DUPLICATE KEY UPDATE company_name = 'Scratch MSP', company_phone = '5550100', company_phone_country_code = NULL, company_logo = NULL");
foreach ([[1, 'New'], [2, 'Open'], [3, 'On Hold'], [4, 'Resolved'], [5, 'Closed']] as [$id, $n]) { $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_active=1, ticket_status_order=$id"); }
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'Jane Roe', contact_email = 'jane@client.example', contact_client_id = 1");
$q("UPDATE settings SET config_timezone = 'UTC', config_smtp_host = '127.0.0.1', config_ticket_from_email = 'support@msp.example', config_ticket_from_name = 'MSP Support',
    config_mail_from_email = 'noreply@msp.example', config_mail_from_name = 'MSP', config_ticket_client_general_notifications = 1, config_ticket_csat_enable = 1,
    config_ticket_new_ticket_notification_email = '', config_client_portal_enable = 1, config_enable_cron = 0, config_theme = 'blue', config_theme_accent_custom = NULL,
    config_mail_template_header_html = NULL, config_mail_template_footer_html = NULL, config_ticket_prefix = 'T' WHERE company_id = 1");
$q("UPDATE users SET user_name = 'Sam Tech', user_email = 'stock@example.test' WHERE user_id = 14");
$q("UPDATE users SET user_name = 'Alex Admin' WHERE user_id = 1");
$GLOBALS['config_ticket_prefix'] = 'T';
$web = ea_start_php($root . '/tests/rmm_golden/router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = []; foreach ([1, 10] as $u) { $sid[$u] = ea_forge_session($sdir, $u); }
$agentPost = fn(array $f, string $ref = 'ticket.php') => web($wb, 'POST', '/agent/post.php', $sid[1], $f + ['csrf_token' => 'csrftok1'], ['Referer: ' . $wb . '/agent/' . $ref]);
$agentGet = fn(string $qs, string $ref = 'ticket.php') => web($wb, 'GET', '/agent/post.php?' . $qs . '&csrf_token=csrftok1', $sid[1], [], ['Referer: ' . $wb . '/agent/' . $ref]);
$MTREF = ['Referer: ' . $wb . '/admin/settings_mail_templates.php'];
$adminPost = fn(array $f, int $uid = 1, bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $sid[$uid], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), $MTREF);
$num = 3000;
$mkTicket = function (array $set = []) use ($q, $db, &$num): int {
    $num++;
    $cols = array_merge(['ticket_prefix' => 'T', 'ticket_number' => $num, 'ticket_subject' => 'Printer down on floor 3', 'ticket_details' => '<p>Paper jam in tray 2</p>', 'ticket_priority' => 'High',
        'ticket_status' => 2, 'ticket_client_id' => 1, 'ticket_contact_id' => 1, 'ticket_created_by' => 1, 'ticket_url_key' => "key$num", 'ticket_source' => 'Agent'], $set);
    $sql = [];
    foreach ($cols as $k => $v) { $sql[] = "`$k` = '" . $db->real_escape_string((string) $v) . "'"; }
    $q('INSERT INTO tickets SET ticket_created_at = NOW(), ' . implode(', ', $sql));
    return (int) $db->insert_id;
};
$mails = fn(int $tid): array => $rows("SELECT email_recipient, email_subject, email_content FROM email_queue WHERE email_ticket_id = $tid ORDER BY email_id");
$tk = fn(int $id): array => mysqli_fetch_assoc($db->query("SELECT * FROM tickets WHERE ticket_id = $id")) ?: [];
$branding = mailTemplateBranding($db);
$ok($branding['accent'] === '#2563EB' && $branding['company_name'] === 'Scratch MSP', 'fixture: branding resolves (blue theme, company name)');
$phone = formatPhoneNumber('5550100', '');
/** Facts for a real ticket the way its call site reads them. */
$ticketFacts = function (int $tid, array $over = []) use ($tk, $phone, $config_base_url): array {
    $t = $tk($tid);
    return array_merge(['ticket_prefix' => $t['ticket_prefix'], 'ticket_number' => $t['ticket_number'], 'ticket_id' => (string) $tid, 'url_key' => $t['ticket_url_key'],
        'config_base_url' => $config_base_url, 'contact_name' => 'Jane Roe', 'ticket_subject' => $t['ticket_subject'], 'company_name' => 'Scratch MSP', 'company_phone' => $phone,
        'config_ticket_from_email' => 'support@msp.example'], $over);
};
$csatFor = fn(int $tid) => csatEmailRatingLinksHtml($config_base_url, $tid, $tk($tid)['ticket_url_key']);
$WATCH_NOTE = '<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET';
/** The full email a call site must have queued: the old text (as above) + the shell. */
$full = function (string $key, array $f, string $note = '') use ($expected, $db): array {
    $b = mailTemplateBranding($db);
    [$s, $inner] = $expected($key, $f, $b['accent']);
    $hf = mailTemplateHeaderFooter($db);
    return [$s, mailTemplateWrapShell($inner . $note, $b['company_name'], $hf['header'], $hf['footer'], $b['accent'], $b['logo_url'])];
};
$checkMail = function (string $label, array $mail, array $exp, ?string $subject = null) use ($ok) {
    $ok(($mail['email_subject'] ?? null) === ($subject ?? $exp[0]), "$label: subject" . (($mail['email_subject'] ?? null) === ($subject ?? $exp[0]) ? '' : ' (got ' . json_encode($mail['email_subject'] ?? null) . ', expected ' . json_encode($subject ?? $exp[0]) . ')'));
    $same = ($mail['email_content'] ?? null) === $exp[1];
    $ok($same, "$label: body byte-identical to the old text in the shell" . ($same ? '' : "\n   expected: {$exp[1]}\n   got:      " . ($mail['email_content'] ?? 'NOTHING QUEUED')));
};

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- every real call site queues the default byte-for-byte (old text, inside the shell) ---\n";
// edit_ticket "notify the contact" (the canonical "created" copy)
$t = $mkTicket();
$agentPost(['edit_ticket' => '1', 'ticket_id' => (string) $t, 'contact_id' => '1', 'contact_notify' => '1', 'subject' => 'Printer down on floor 3', 'details' => '<p>Paper jam in tray 2</p>', 'priority' => 'High', 'assigned_to' => '0', 'billable' => '0', 'category_id' => '0']);
$m = $mails($t);
$checkMail('edit_ticket notify -> ticket_created', $m[0] ?? [], $full('ticket_created', $ticketFacts($t, ['ticket_status' => 'Open', 'ticket_details' => $tk($t)['ticket_details']])));
// edit_ticket_contact notify
$t = $mkTicket();
$agentPost(['edit_ticket_contact' => '1', 'ticket_id' => (string) $t, 'contact' => '1', 'contact_notify' => '1']);
$checkMail('edit_ticket_contact notify -> ticket_created', $mails($t)[0] ?? [], $full('ticket_created', $ticketFacts($t, ['ticket_status' => 'Open', 'ticket_details' => '<p>Paper jam in tray 2</p>'])));
// add_ticket (its old subject lacked the " -"; converges on the canonical subject), with a watcher: the watcher copy carries the notice INSIDE the card
$agentPost(['add_ticket' => '1', 'client_id' => '1', 'contact_id' => '1', 'subject' => 'New laptop setup', 'details' => '<p>For the new starter</p>', 'priority' => 'Low', 'assigned_to' => '0',
    'category_id' => '0', 'vendor_ticket_number' => '', 'vendor_id' => '0', 'asset_id' => '0', 'location_id' => '0', 'project_id' => '0', 'ticket_template_id' => '0', 'watchers' => ['watch@client.example']], 'tickets.php');
$t = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'New laptop setup' ORDER BY ticket_id DESC LIMIT 1");
$m = $mails($t);
$ok($t > 0 && count($m) >= 1, 'add_ticket: created and mailed (' . count($m) . ' email(s))');
$fa = $ticketFacts($t, ['ticket_status' => 'Open', 'ticket_details' => '<p>For the new starter</p>']);
$checkMail('add_ticket -> ticket_created', $m[0] ?? [], $full('ticket_created', $fa));
if (count($m) > 1) {
    $checkMail('add_ticket watcher copy', $m[1], $full('ticket_created', $fa, '<br><br>----------------------------------------<br>YOU HAVE BEEN ADDED AS A COLLABORATOR FOR THIS TICKET'));
    $ok(strpos($m[1]['email_content'], 'YOU HAVE BEEN ADDED') < strrpos($m[1]['email_content'], '</td></tr></table>'), 'the watcher notice sits inside the card, not after the shell');
}
// add_ticket_reply, public + email, not resolving
$t = $mkTicket();
$q("INSERT INTO ticket_watchers SET watcher_email = 'watch@client.example', watcher_ticket_id = $t");
$agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $t, 'ticket_reply' => '<p>Cleared the jam</p>', 'status' => '2', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2']);
$m = $mails($t);
$fr = $ticketFacts($t, ['ticket_status_name' => 'Open', 'ticket_reply' => '<p>Cleared the jam</p>']);
$checkMail('add_ticket_reply -> ticket_reply', $m[0] ?? [], $full('ticket_reply', $fr));
$checkMail('add_ticket_reply watcher copy', $m[1] ?? [], $full('ticket_reply', $fr, $WATCH_NOTE));
// add_ticket_reply that resolves
$t = $mkTicket();
$agentPost(['add_ticket_reply' => '1', 'ticket_id' => (string) $t, 'ticket_reply' => '<p>Fixed it</p>', 'status' => '4', 'hours' => '0', 'minutes' => '0', 'seconds' => '0', 'public_reply_type' => '2']);
$m = $mails($t);
$statusNow = (string) $one("SELECT ticket_status_name FROM tickets JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE ticket_id = $t");
$isResolved = str_starts_with((string) ($m[0]['email_subject'] ?? ''), 'Ticket resolved');
$checkMail('add_ticket_reply resolving -> ' . ($isResolved ? 'ticket_resolved' : 'ticket_reply (status already Closed when the handler re-reads it, as before)'), $m[0] ?? [],
    $isResolved ? $full('ticket_resolved', $ticketFacts($t, ['ticket_status_name' => $statusNow, 'ticket_reply' => '<p>Fixed it</p>', 'csat_rating_html' => $csatFor($t)]))
                : $full('ticket_reply', $ticketFacts($t, ['ticket_status_name' => $statusNow, 'ticket_reply' => '<p>Fixed it</p>'])));
// resolve_ticket (no reply: the empty Quote box is dropped -> exactly the old resolve text)
$t = $mkTicket();
$agentGet("resolve_ticket=$t");
$checkMail('resolve_ticket -> ticket_resolved (no reply)', $mails($t)[0] ?? [], $full('ticket_resolved_noreply', $ticketFacts($t, ['ticket_status' => 'Closed', 'csat_rating_html' => $csatFor($t)])));
// kanban drag to Resolved (agent/ajax.php)
$t = $mkTicket();
web($wb, 'POST', '/agent/ajax.php', $sid[1], ['update_kanban_ticket' => '1', 'csrf_token' => 'csrftok1', 'positions' => [['ticket_id' => $t, 'ticket_order' => 1, 'ticket_oldStatus' => 2, 'ticket_status' => 4]]]);
$checkMail('kanban -> ticket_resolved (no reply)', $mails($t)[0] ?? [], $full('ticket_resolved_noreply', $ticketFacts($t, ['ticket_status' => 'Closed', 'csat_rating_html' => $csatFor($t)])));
// close_ticket, with a watcher
$t = $mkTicket();
$q("INSERT INTO ticket_watchers SET watcher_email = 'watch@client.example', watcher_ticket_id = $t");
$agentGet("close_ticket=$t");
$m = $mails($t);
$fc = $ticketFacts($t, ['csat_rating_html' => $csatFor($t)]);
$checkMail('close_ticket -> ticket_closed', $m[0] ?? [], $full('ticket_closed', $fc));
$checkMail('close_ticket watcher copy', $m[1] ?? [], $full('ticket_closed', $fc, $WATCH_NOTE));
// assign_ticket (to technician 14)
$t = $mkTicket();
$agentPost(['assign_ticket' => '1', 'ticket_id' => (string) $t, 'assigned_to' => '14', 'ticket_status' => '2']);
$m = $mails($t);
$fs = $ticketFacts($t, ['agent_name' => 'Sam Tech', 'client_name' => 'Client A', 'client_uri' => '&client_id=1', 'session_name' => 'Alex Admin', 'config_app_name' => $config_app_name]);
$checkMail('assign_ticket -> ticket_assigned (to the technician)', $m[0] ?? [], $full('ticket_assigned', $fs));
$ok(($m[0]['email_recipient'] ?? '') === 'stock@example.test', 'the assigned email goes to the technician');
// bulk resolve and bulk reply (their old copies drifted: they converge on the canonical text)
$t = $mkTicket();
$agentPost(['bulk_resolve_tickets' => '1', 'ticket_ids' => [(string) $t], 'bulk_details' => '<p>Bulk fixed</p>', 'time' => '00:05:00']);
$checkMail('bulk resolve -> ticket_resolved (details quoted, Status line added)', $mails($t)[0] ?? [], $full('ticket_resolved', $ticketFacts($t, ['ticket_status_name' => 'Closed', 'ticket_reply' => '<p>Bulk fixed</p>', 'csat_rating_html' => $csatFor($t)])));
$t = $mkTicket();
$agentPost(['bulk_ticket_reply' => '1', 'ticket_ids' => [(string) $t], 'bulk_reply_details' => '<p>Bulk hello</p>', 'bulk_status' => '2', 'time' => '00:05:00']);
$checkMail('bulk reply -> ticket_reply (Status no longer blank)', $mails($t)[0] ?? [], $full('ticket_reply', $ticketFacts($t, ['ticket_status_name' => 'Open', 'ticket_reply' => '<p>Bulk hello</p>'])));
// recurring: the agent's "create now" and the cron (their old "(scheduled)" / "automatically created" copy converges on the canonical one)
$q("INSERT INTO recurring_tickets SET recurring_ticket_subject = 'Monthly patch check', recurring_ticket_details = '<p>Check updates</p>', recurring_ticket_priority = 'Low', recurring_ticket_frequency = 'Monthly',
    recurring_ticket_start_date = CURDATE(), recurring_ticket_next_run = CURDATE(), recurring_ticket_created_by = 1, recurring_ticket_client_id = 1, recurring_ticket_contact_id = 1");
$rt = (int) $db->insert_id;
$agentGet("force_recurring_ticket=$rt", 'recurring_tickets.php');
$t = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_recurring_ticket_id = $rt ORDER BY ticket_id DESC LIMIT 1");
$checkMail('recurring "create now" -> ticket_created (company name/phone no longer blank)', $mails($t)[0] ?? [], $full('ticket_created', $ticketFacts($t, ['ticket_status' => 'Open', 'ticket_details' => '<p>Check updates</p>'])));
$q("UPDATE recurring_tickets SET recurring_ticket_next_run = CURDATE() WHERE recurring_ticket_id = $rt");
$q("UPDATE settings SET config_enable_cron = 1 WHERE company_id = 1");
shell_exec('cd ' . escapeshellarg("$root/cron") . ' && RIVETMSP_REDIS_HOST=127.0.0.1 RIVETMSP_REDIS_PORT=' . escapeshellarg((string) getenv('RIVETMSP_REDIS_PORT')) . ' RIVETMSP_REDIS_ENV_FILE=/dev/null php cron.php </dev/null 2>&1');
$q("UPDATE settings SET config_enable_cron = 0 WHERE company_id = 1");
$t2 = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_recurring_ticket_id = $rt AND ticket_id <> $t ORDER BY ticket_id DESC LIMIT 1");
$ok($t2 > 0 && strlen((string) $tk($t2)['ticket_url_key']) === 32, 'cron recurring ticket created, now WITH a guest-link key');
$checkMail('cron recurring -> ticket_created', $mails($t2)[0] ?? [], $full('ticket_created', $ticketFacts($t2, ['ticket_status' => 'Open', 'ticket_details' => '<p>Check updates</p>'])));
// the inbound-email auto-reply (functions.php addTicket): no details quoted (as before), status New
foreach (['config_ticket_client_general_notifications' => 1, 'config_ticket_new_ticket_notification_email' => '', 'config_ticket_from_email' => 'support@msp.example',
          'config_ticket_from_name' => 'MSP Support', 'config_ticket_default_billable' => 0, 'config_ticket_prefix' => 'T'] as $k => $v) { $GLOBALS[$k] = $v; }
addTicket(1, 'Jane Roe', 'jane@client.example', 1, date('Y-m-d H:i:s'), 'Email raised issue', '<p>From the inbox</p>', [], '', []);
$t = (int) $one("SELECT ticket_id FROM tickets WHERE ticket_subject = 'Email raised issue' ORDER BY ticket_id DESC LIMIT 1");
$m = $mails($t);
$fe = $ticketFacts($t, ['ticket_status' => 'New', 'company_phone' => getCompanyNameAndPhone()['phone']]);
[, $feBody] = $full('ticket_created', array_merge($fe, ['ticket_details' => '']));
$feBody = str_replace(mailTemplateQuoteOpen('#2563EB') . '</div><br><br>', '', str_replace('--------------------------------<br>--------------------------------<br><br>', '', $feBody));
$checkMail('email parser addTicket() -> ticket_created (no Quote box, no stray backslashes)', $m[0] ?? [], [$full('ticket_created', $fe + ['ticket_details' => ''])[0], $feBody]);
$ok(!str_contains((string) ($m[0]['email_content'] ?? ''), "\\'") && !str_contains((string) ($m[0]['email_content'] ?? ''), '\\"'), 'the auto-reply no longer has a backslash before every quote');
// client portal password reset and its confirmation (the public client/login_reset.php)
$q("INSERT INTO users SET user_id = 40, user_name = 'Pat Portal', user_email = 'pat@client.example', user_password = 'x', user_type = 2, user_status = 1, user_auth_method = 'local', user_role_id = 0");
$q("INSERT INTO contacts SET contact_id = 3, contact_name = 'Pat Portal', contact_email = 'pat@client.example', contact_client_id = 1, contact_user_id = 40");
web($wb, 'POST', '/client/login_reset.php', '', ['password_reset_email_request' => '1', 'email' => 'pat@client.example']);
$pm = $rows("SELECT email_subject, email_content FROM email_queue WHERE email_recipient = 'pat@client.example' ORDER BY email_id");
preg_match('/token=([A-Za-z0-9_-]+)&amp;client=1/', (string) ($pm[0]['email_content'] ?? ''), $tm);
$fp = ['name' => 'Pat Portal', 'company_name' => 'Scratch MSP', 'company_phone' => formatPhoneNumber('5550100'), 'config_ticket_from_email' => 'support@msp.example',
    'url' => "https://$config_base_url/client/login_reset.php?email=pat@client.example&token=" . ($tm[1] ?? '?') . '&client=1'];
$checkMail('client/login_reset.php -> password_reset', $pm[0] ?? [], $full('password_reset', $fp));
$q("UPDATE users SET user_password_reset_token = '" . hash('sha256', 'tok123') . "' WHERE user_id = 40");
web($wb, 'POST', '/client/login_reset.php', '', ['password_reset_set_password' => '1', 'email' => 'pat@client.example', 'token' => 'tok123', 'client' => '1', 'new_password' => 'A-new-Passw0rd-123']);
$pm = $rows("SELECT email_subject, email_content FROM email_queue WHERE email_recipient = 'pat@client.example' ORDER BY email_id");
$checkMail('client/login_reset.php -> password_changed', $pm[1] ?? [], $full('password_changed', $fp));

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- the shell, and the reply marker leading it ---\n";
$r = renderMailTemplate($db, 'ticket_created', $varsFor('ticket_created', $facts));
$ok(str_starts_with($r['body'], MAIL_TEMPLATE_REPLY_MARKER_HTML . '<table role="presentation" width="100%"'), 'the reply marker is the very first thing, above the shell (the parser drops everything after it)');
$ok(str_contains($r['body'], 'background-color:#f4f5f7') && str_contains($r['body'], 'max-width:580px;background-color:#ffffff') && !preg_match('/display:\s*flex/', $r['body']), 'table-based shell: light outer background, white card, no flexbox');
$ok(str_contains($r['body'], 'background-color:#2563EB;border-radius:10px 10px 0 0;'), 'the card has the accent stripe');
$ok(str_contains($r['body'], '<div style="font-size:19px;font-weight:700;color:#1f2937;margin-bottom:20px;">Scratch MSP</div>'), 'no logo: the company name is the header');
$closed = renderMailTemplate($db, 'ticket_closed', $varsFor('ticket_closed', $facts));
$ok(!mailTemplateHasReplyMarker($closed['body']) && str_starts_with($closed['body'], '<table'), 'ticket_closed (a do-not-reply notice) carries no marker');
$ok(!mailTemplateHasReplyMarker(renderMailTemplate($db, 'ticket_assigned', $varsFor('ticket_assigned', $facts))['body']), 'ticket_assigned (to a technician) carries no marker');
foreach (['ticket_created', 'ticket_reply', 'ticket_resolved'] as $e) { $ok(mailTemplateHasReplyMarker(renderMailTemplate($db, $e, $varsFor($e, $facts))['body']) && mailTemplateRequiresReplyMarker($e), "$e keeps the marker (client-replyable)"); }
foreach (['ticket_closed', 'ticket_assigned', 'password_reset', 'password_changed'] as $e) { $ok(!mailTemplateRequiresReplyMarker($e), "$e does not require the marker"); }

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- reply marker: the real parser round trip ---\n";
$custText = 'Thanks, that worked!';
foreach (['gmail' => fn($b) => "<div dir=\"ltr\">$custText</div><div class=\"gmail_quote\"><div class=\"gmail_attr\">On Tue, Oct 21, 2026 at 9:00 AM MSP Support &lt;support@msp.example&gt; wrote:</div><blockquote class=\"gmail_quote\">$b</blockquote></div>",
          'no quote container at all' => fn($b) => "<div>$custText</div>$b"] as $client => $wrap) {
    $html = $wrap($r['body']);
    $s = \RivetMSP\Mail\QuotedTextStripper::stripHtml($html);
    $ok(trim(strip_tags($s)) === $custText, "QuotedTextStripper::stripHtml ($client): only the customer's text survives (" . json_encode(trim(strip_tags($s))) . ')');
}
$textReply = "$custText\n\n" . trim(html_entity_decode(strip_tags(str_replace(['<br>', '</div>'], "\n", $r['body'])), ENT_QUOTES));
$ok(\RivetMSP\Mail\QuotedTextStripper::stripText($textReply) === $custText, 'QuotedTextStripper::stripText: the plain-text part is cut at the marker line');
$t = $mkTicket();
$tn = (int) $tk($t)['ticket_number'];
$GLOBALS['config_ticket_from_email'] = 'support@msp.example';
addReply('jane@client.example', date('Y-m-d H:i:s'), 'Re: Ticket update - [T' . $tn . '] - Printer down on floor 3', $tn, "<div>$custText</div>" . renderMailTemplate($db, 'ticket_reply', $varsFor('ticket_reply', $facts))['body'], [], null, 'Jane Roe');
$stored = (string) $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id = $t ORDER BY ticket_reply_id DESC LIMIT 1");
$ok(str_contains($stored, $custText) && !str_contains($stored, 'Scratch MSP') && !str_contains($stored, 'Hello') && !str_contains($stored, '<table'), 'functions.php addReply(): the stored reply is the customer text only - no shell, header or template text');
// save-time warnings (the page asks before Save via RivetDialog; the handler says it again)
$noMarker = mailTemplateSerializeBlocks(array_values(array_filter(mailTemplateDefaultBlocks()['ticket_reply'], fn($b) => $b['type'] !== 'reply_marker')));
$markerLate = mailTemplateSerializeBlocks(array_merge([['type' => 'text', 'html' => 'Hi']], mailTemplateDefaultBlocks()['ticket_reply']));
$noLink = mailTemplateSerializeBlocks([['type' => 'reply_marker'], ['type' => 'text', 'html' => 'Hello']]);
$ok(mailTemplateSaveWarnings('ticket_reply', $mtDefault('ticket_reply')['body']) === [], 'no warning for a default');
$ok(count(mailTemplateSaveWarnings('ticket_reply', $noMarker)) === 1 && str_contains(mailTemplateSaveWarnings('ticket_reply', $noMarker)[0], 'reply marker is missing'), 'warning: marker removed');
$ok(str_contains(implode(' ', mailTemplateSaveWarnings('ticket_created', $markerLate)), 'not the first block'), 'warning: marker no longer first');
$ok(str_contains(implode(' ', mailTemplateSaveWarnings('ticket_closed', 'Bye')), '{portal_link}') && mailTemplateSaveWarnings('ticket_assigned', 'Bye') === [], 'warning: portal link removed (only where one is needed)');
$ok(str_contains(implode(' ', mailTemplateSaveWarnings('ticket_created', $noLink)), '{portal_link}'), 'warning: portal link removed from a replyable template');
$ok(!mailTemplateHasReplyMarker("<i style='color: #808080'> ##- Please type your reply above this line -##</i>"), 'a marker the parser would NOT cut at (space inside the tag) counts as missing');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- placeholders: escaping vs the RAW_KEYS trust boundary ---\n";
$hv = ['contact_name' => "O'Brien <script>alert(1)</script>", 'subject' => "Line1\r\nBcc: evil@x.example", 'ticket_details' => '<p>Trusted <b>HTML</b> {contact_name}</p>',
    'portal_link' => 'https://x.example/a?b=1&c="2"', 'not_a_raw_key' => '<img src=x onerror=alert(1)>'];
$p = mailTemplateRenderParts('Re: {subject} {ticket_details}', 'Hi {contact_name} | {ticket_details} | <a href="{portal_link}">x</a> | {not_a_raw_key} | {unknown_one} | {mt_accent}', $hv, '#2563EB');
$ok(str_contains($p['body'], 'Hi O&#039;Brien &lt;script&gt;alert(1)&lt;/script&gt;'), 'a contact name is HTML-escaped');
$ok(str_contains($p['body'], '| <p>Trusted <b>HTML</b> {contact_name}</p> |'), 'ticket_details (a RAW key) is inserted verbatim - and its own "{contact_name}" is NOT expanded again (one pass)');
$ok(str_contains($p['body'], 'href="https://x.example/a?b=1&amp;c=&quot;2&quot;"'), 'a link value is attribute-safe');
$ok(str_contains($p['body'], '| &lt;img src=x onerror=alert(1)&gt; |'), 'a key that is not in the fixed RAW_KEYS list is escaped, whatever its name');
$ok(str_contains($p['body'], '|  |') && !str_contains($p['body'], '{unknown_one}'), 'an unknown placeholder renders blank, never as literal {token} text');
$ok(str_ends_with($p['body'], '#2563EB'), '{mt_accent} resolves to the accent');
$ok($p['subject'] === 'Re: Line1 Bcc: evil@x.example ', 'subject (a mail header): CR/LF flattened, RAW keys unavailable (no HTML in a subject)');
$ok(mailTemplateApplyPlaceholders('{a}{b}', ['a' => '{b}', 'b' => 'X']) === '{b}X', 'substitution is one pass: a value that looks like a placeholder stays literal');
$ok(MAIL_TEMPLATE_RAW_KEYS === ['ticket_details', 'ticket_reply', 'csat_rating_html'], 'the RAW_KEYS set is exactly the three app-built HTML values');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- the builder comment never reaches an email ---\n";
foreach (array_keys(mailTemplateEvents()) as $e) {
    $ok(str_starts_with($mtDefault($e)['body'], '<!--RIVETMSP_BLOCKS:') && count(mailTemplateBlocksFromBody($mtDefault($e)['body'])) > 1, "$e: the default carries its real block list");
    $ok(!str_contains(renderMailTemplate($db, $e, $varsFor($e, $facts))['body'], 'RIVETMSP_BLOCKS'), "$e: the rendered email has no builder comment");
}
$tricky = [
    'html block holding "-->"' => mailTemplateSerializeBlocks([['type' => 'html', 'html' => '<!-- note --> -->{"x":1}'], ['type' => 'text', 'html' => 'after']]),
    'a pasted serialized body inside a block' => mailTemplateSerializeBlocks([['type' => 'html', 'html' => mailTemplateSerializeBlocks([['type' => 'text', 'html' => 'inner']])]]),
    'unterminated comment' => '<!--RIVETMSP_BLOCKS:{"v":1,"blocks":[{"type":"text","html":"x"}',
    'comment mid-body' => 'Hi <!--RIVETMSP_BLOCKS:{"secret":true}--> there',
];
foreach ($tricky as $label => $body) {
    $out = mailTemplateRenderParts('s', $body, [], '#2563EB')['body'];
    $ok(!str_contains($out, 'RIVETMSP_BLOCKS') && !str_contains($out, '"blocks"'), "$label: nothing of the comment or its JSON in the email (" . json_encode(substr($out, 0, 60)) . ')');
}
$leaky = (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_content LIKE '%RIVETMSP_BLOCKS%' OR email_content LIKE '%{mt_accent}%' OR email_content LIKE '%{portal_link}%'");
$ok($leaky === 0 && (int) $one('SELECT COUNT(*) FROM email_queue') > 10, 'not one of the ' . $one('SELECT COUNT(*) FROM email_queue') . ' emails this test queued holds a builder comment or an unresolved token');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- enabled switch, custom edit, Reset to default (the real handlers) ---\n";
$q('DELETE FROM mail_templates'); mailTemplateSyncDefaults($db);
$custom = mailTemplateSerializeBlocks([['type' => 'reply_marker'], ['type' => 'text', 'html' => 'CUSTOM hello {contact_name}'], ['type' => 'button', 'label' => 'Open', 'link' => '{portal_link}']]);
$tampered = substr($custom, 0, strpos($custom, '-->') + 3) . '<p>browser-side HTML that does not match the blocks</p>';
[$code] = $adminPost(['save_mail_template' => '1', 'event' => 'ticket_closed', 'subject' => 'CUSTOM closed [{ticket_number}]', 'body' => $tampered, 'enabled' => '1']);
$row = mysqli_fetch_assoc($db->query("SELECT * FROM mail_templates WHERE mail_template_event = 'ticket_closed'"));
$ok($code === 302 && $row['mail_template_subject'] === 'CUSTOM closed [{ticket_number}]' && (int) $row['mail_template_updated_by'] === 1, 'admin save: stored, marked customized by the admin');
$ok($row['mail_template_body'] === $custom, 'the server re-compiles the posted blocks: the stored HTML is its own compile, not what the browser sent');
$t = $mkTicket();
$agentGet("close_ticket=$t");
$m = $mails($t);
$ok(($m[0]['email_subject'] ?? '') === 'CUSTOM closed [T' . $tk($t)['ticket_number'] . ']' && str_contains($m[0]['email_content'], 'CUSTOM hello Jane Roe') && str_contains($m[0]['email_content'], 'background-color:#2563EB;"><a href="https://'), 'a real close sends the customized email (button in the theme colour)');
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_closed', 'subject' => 'CUSTOM closed', 'body' => $custom]);
$t = $mkTicket();
$agentGet("close_ticket=$t");
$ok($mails($t) === [] && $tk($t)['ticket_status'] === '5', 'switched off: the ticket still closes, and NOTHING is queued for it');
$t = $mkTicket();
$agentGet("resolve_ticket=$t");
$ok(count($mails($t)) === 1, 'other events are unaffected by one switched-off template');
$ok(renderMailTemplate($db, 'ticket_closed', $varsFor('ticket_closed', $facts)) === null, 'renderMailTemplate() returns null for a switched-off event');
[$code, , $hdr] = $adminPost(['reset_mail_template' => '1', 'event' => 'ticket_closed']);
$row = mysqli_fetch_assoc($db->query("SELECT * FROM mail_templates WHERE mail_template_event = 'ticket_closed'"));
$ok($row['mail_template_subject'] === $mtDefault('ticket_closed')['subject'] && $row['mail_template_body'] === $mtDefault('ticket_closed')['body'] && (int) $row['mail_template_enabled'] === 1 && (int) $row['mail_template_updated_by'] === 0,
    'Reset to default: the exact default, switched on again, back to "never customized"');
$t = $mkTicket();
$agentGet("close_ticket=$t");
$checkMail('after Reset, a real close sends the default again', $mails($t)[0] ?? [], $full('ticket_closed', $ticketFacts($t, ['csat_rating_html' => $csatFor($t)])));
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%reset the \"ticket_closed\" email template%'") === 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%edited the \"ticket_closed\" email template and switched it off%'") === 1, 'edits, switch-offs and resets are logged');
// the server-side warning toast and the hostile inputs
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'S', 'body' => $noMarker, 'enabled' => '1']);
$ok((int) $one("SELECT COUNT(*) FROM mail_templates WHERE mail_template_event = 'ticket_reply' AND mail_template_body = '" . $esc($noMarker) . "'") === 1, 'a template without the marker IS saved (a warning, never a block)');
$sess = (string) @file_get_contents("$sdir/sess_{$sid[1]}");
$ok(str_contains($sess, 'warning') && str_contains($sess, 'reply marker is missing'), 'and the save answers with a warning toast naming the problem');
mailTemplateResetToDefault($db, 'ticket_reply');
$before = $rows('SELECT * FROM mail_templates ORDER BY mail_template_event');
$adminPost(['save_mail_template' => '1', 'event' => 'nope', 'subject' => 'x', 'body' => 'y', 'enabled' => '1']);
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => '', 'body' => 'y', 'enabled' => '1']);
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'x', 'body' => '<!--RIVETMSP_BLOCKS:{}-->   ', 'enabled' => '1']);
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => str_repeat('x', 501), 'body' => 'y', 'enabled' => '1']);
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => ['a'], 'body' => ['b'], 'enabled' => '1']);
$adminPost(['save_mail_template' => '1', 'event' => "ticket_reply' OR '1'='1", 'subject' => 'x', 'body' => 'y']);
$ok($rows('SELECT * FROM mail_templates ORDER BY mail_template_event') === $before, 'unknown event, empty subject/body, an empty block list, a 501-char subject, arrays, an injected event: nothing stored');
$ok(mailTemplateResetToDefault($db, 'nope') === false, 'Reset of an unknown event is refused');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- preview / Send test: the same action, the real send path ---\n";
[$code, $body] = $adminPost(['preview_mail_template' => '1', 'event' => 'ticket_resolved', 'subject' => '', 'body' => '']);
$j = json_decode($body, true);
$sv = mailTemplateSampleVars('ticket_resolved', 'Scratch MSP');
$ok(($j['ok'] ?? false) && $j['body'] === mailTemplateRenderWith($db, $mtDefault('ticket_resolved')['subject'], $mtDefault('ticket_resolved')['body'], $sv)['body'], 'preview of the default === mailTemplateRenderWith() with the sample data (what a send would be)');
[, $body] = $adminPost(['preview_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'Draft {ticket_number}', 'body' => $custom]);
$j = json_decode($body, true);
$ok(($j['subject'] ?? '') === 'Draft TCK-1042' && str_contains($j['body'] ?? '', 'CUSTOM hello Jamie Example') && !str_contains($j['body'] ?? '', 'RIVETMSP'), 'preview renders the UNSAVED draft');
$ok((int) $one("SELECT COUNT(*) FROM mail_templates WHERE mail_template_body LIKE '%CUSTOM hello%'") === 0, 'a preview writes nothing');
$q("DELETE FROM email_queue WHERE email_subject LIKE '[TEST]%'");
[, $body] = $adminPost(['preview_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'Draft {ticket_number}', 'body' => $custom, 'send_test' => '1']);
$tq = $rows("SELECT email_recipient, email_subject, email_content FROM email_queue WHERE email_subject LIKE '[TEST]%'");
$ok((json_decode($body, true)['ok'] ?? false) && count($tq) === 1 && $tq[0]['email_recipient'] === 'admin@example.test' && $tq[0]['email_subject'] === '[TEST] Draft TCK-1042' && str_contains($tq[0]['email_content'], 'CUSTOM hello Jamie Example'), 'Send test: one copy of the draft to the admin\'s own address, through the normal queue');
[, $body] = $adminPost(['preview_mail_template' => '1', 'event' => 'bogus']);
$ok((json_decode($body, true)['ok'] ?? true) === false, 'preview of an unknown event: an error, no render');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- branding: resolved from the database at send time ---\n";
$b = mailTemplateBranding($db);
$ok($b['accent'] === '#2563EB' && $b['logo_url'] === '', "theme preset 'blue' -> #2563EB, no logo -> ''");
$q("UPDATE settings SET config_theme = '' WHERE company_id = 1");
$ok(mailTemplateBranding($db)['accent'] === MAIL_TEMPLATE_DEFAULT_ACCENT, 'an empty theme name -> the default accent ' . MAIL_TEMPLATE_DEFAULT_ACCENT);
$q("UPDATE settings SET config_theme = 'no-such-theme' WHERE company_id = 1");
$ok(mailTemplateBranding($db)['accent'] === MAIL_TEMPLATE_DEFAULT_ACCENT, 'an unknown theme name -> the default accent');
$q("UPDATE settings SET config_theme = 'green' WHERE company_id = 1");
$ok(mailTemplateBranding($db)['accent'] === '#16A34A', "preset 'green' -> #16A34A");
$q("UPDATE settings SET config_theme_accent_custom = '#ff00aa' WHERE company_id = 1");
$ok(mailTemplateBranding($db)['accent'] === '#FF00AA', 'a custom hex beats the preset');
$q("UPDATE settings SET config_theme_accent_custom = 'red;x' WHERE company_id = 1");
$ok(mailTemplateBranding($db)['accent'] === '#16A34A', 'an invalid custom hex is ignored (preset again)');
$q("UPDATE settings SET config_theme_accent_custom = '#ff00aa' WHERE company_id = 1");
$t = $mkTicket();
$agentGet("resolve_ticket=$t");
$m = $mails($t);
$ok(str_contains($m[0]['email_content'] ?? '', 'background-color:#FF00AA;border-radius:10px 10px 0 0;') && !str_contains($m[0]['email_content'] ?? '', '{mt_accent}'), 'a theme change reaches the next real email with no template re-save');
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_closed', 'subject' => 'S', 'body' => $custom, 'enabled' => '1']);
$ok(str_contains((string) $one("SELECT mail_template_body FROM mail_templates WHERE mail_template_event = 'ticket_closed'"), 'background-color:{mt_accent};'), 'a saved button stores the {mt_accent} token, not a hex');
$q("UPDATE settings SET config_theme_accent_custom = '#123456' WHERE company_id = 1");
$ok(str_contains(renderMailTemplate($db, 'ticket_closed', $varsFor('ticket_closed', $facts))['body'], 'background-color:#123456;"><a href='), 'an already-saved template follows the new accent at send time');
mailTemplateResetToDefault($db, 'ticket_closed');
$q("UPDATE companies SET company_logo = 'my logo.png' WHERE company_id = 1");
$b = mailTemplateBranding($db);
$ok($b['logo_url'] === 'https://' . trim($config_base_url, '/') . '/uploads/settings/my%20logo.png', 'an uploaded logo becomes an ABSOLUTE https URL: ' . $b['logo_url']);
$r = renderMailTemplate($db, 'ticket_reply', $varsFor('ticket_reply', $facts));
$ok(str_contains($r['body'], '<img src="' . $b['logo_url'] . '" alt="Scratch MSP"') && !str_contains($r['body'], '>Scratch MSP</div>'), 'logo set: the logo is the header (no company-name header)');
$q("UPDATE settings SET config_mail_template_header_html = '<div id=\"hdr\">Custom header</div>', config_mail_template_footer_html = '<p id=\"ftr\">Footer</p>' WHERE company_id = 1");
$r = renderMailTemplate($db, 'ticket_reply', $varsFor('ticket_reply', $facts));
$ok(str_contains($r['body'], '<div id="hdr">Custom header</div>') && !str_contains($r['body'], '<img src='), 'a custom header beats the logo');
$ok(strpos($r['body'], 'id="hdr"') < strpos($r['body'], 'Hello') && strpos($r['body'], 'Hello') < strpos($r['body'], 'id="ftr"') && strpos($r['body'], 'id="ftr"') < strrpos($r['body'], '</td></tr></table>'), 'header above the body, footer below it, both inside the card');
$q("UPDATE settings SET config_mail_template_header_html = NULL, config_mail_template_footer_html = NULL, config_theme = 'blue', config_theme_accent_custom = NULL WHERE company_id = 1");
$q("UPDATE companies SET company_logo = NULL, company_name = 'O''Reilly & Sons' WHERE company_id = 1");
$ok(str_contains(renderMailTemplate($db, 'ticket_reply', $varsFor('ticket_reply', $facts))['body'], '>O&#039;Reilly &amp; Sons</div>'), 'no logo, no header: the company name (escaped, read raw from the DB - no stray backslash)');
$adminPost(['save_mail_template_header_footer' => '1', 'header_html' => '<b>H</b>', 'footer_html' => '']);
$hf = mailTemplateHeaderFooter($db);
$ok($hf['header'] === '<b>H</b>' && $hf['footer'] === '' && $one('SELECT config_mail_template_footer_html FROM settings WHERE company_id = 1') === null, 'the shared header/footer handler stores the header, an empty footer as NULL');
$q("UPDATE settings SET config_mail_template_header_html = NULL WHERE company_id = 1");
$q("UPDATE companies SET company_name = 'Scratch MSP' WHERE company_id = 1");

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- permissions ---\n";
[$code, $body] = web($wb, 'GET', '/admin/settings_mail_templates.php', $sid[10]);
$ok(str_contains($body, 'Your role does not have admin access') && !str_contains($body, 'mt-block-list'), 'a technician (non-admin role) cannot open the page');
[$code, $body] = web($wb, 'GET', '/admin/settings_mail_templates.php', $sid[1]);
$ok($code === 200 && substr_count($body, 'class="card mb-3 mail-template-card"') === 7 && str_contains($body, '/plugins/tinymce/tinymce.min.js') && str_contains($body, 'bootstrap.bundle.min.js'), 'an admin gets the page with all seven cards and the footer scripts (includes/footer.php)');
$before = $rows('SELECT * FROM mail_templates ORDER BY mail_template_event');
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'TECH', 'body' => 'TECH', 'enabled' => '1'], 10);
$adminPost(['reset_mail_template' => '1', 'event' => 'ticket_reply'], 10);
$adminPost(['save_mail_template_header_footer' => '1', 'header_html' => 'TECH', 'footer_html' => 'TECH'], 10);
[, $body] = $adminPost(['preview_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'x', 'body' => 'y'], 10);
$ok($rows('SELECT * FROM mail_templates ORDER BY mail_template_event') === $before && $one('SELECT config_mail_template_header_html FROM settings WHERE company_id = 1') === null && json_decode($body, true) === null, 'a technician cannot save, reset, edit the header/footer or preview (admin/post.php never loads the handler)');
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'NOCSRF', 'body' => 'NOCSRF', 'enabled' => '1'], 1, false);
$adminPost(['save_mail_template' => '1', 'event' => 'ticket_reply', 'subject' => 'BADCSRF', 'body' => 'BADCSRF', 'enabled' => '1', 'csrf_token' => 'wrong'], 1, false);
$ok($rows('SELECT * FROM mail_templates ORDER BY mail_template_event') === $before, 'an admin post without a valid CSRF token changes nothing');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- DB 2.6.107: updater step, db.sql parity, untouched-row re-sync, code before the step ---\n";
$q('DROP TABLE IF EXISTS mail_templates');
$q('ALTER TABLE settings DROP COLUMN IF EXISTS config_mail_template_header_html, DROP COLUMN IF EXISTS config_mail_template_footer_html');
$q("UPDATE settings SET config_current_database_version = '2.6.106' WHERE company_id = 1");
$r = renderMailTemplate($db, 'ticket_created', $varsFor('ticket_created', $facts));
$ok($r !== null && $r['body'] === mailTemplateRenderWith($db, $mtDefault('ticket_created')['subject'], $mtDefault('ticket_created')['body'], $varsFor('ticket_created', $facts))['body'], 'before the step (no table, no columns): renders the default, no exception');
$t = $mkTicket();
$agentGet("close_ticket=$t");
$ok(count($mails($t)) === 1, 'before the step: a real ticket email is still sent');
[, $body] = web($wb, 'GET', '/admin/settings_mail_templates.php', $sid[1]);
$ok(substr_count($body, 'class="card mb-3 mail-template-card"') === 7, 'before the step: the admin page still opens (shows the defaults)');
$upd = shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
require_once "$root/includes/database_version.php";
$ok($one('SELECT config_current_database_version FROM settings WHERE company_id = 1') === LATEST_DATABASE_VERSION && LATEST_DATABASE_VERSION === '2.6.107', 'the updater runs 2.6.106 -> 2.6.107 (' . str_replace("\n", ' ', trim((string) $upd)) . ')');
$seeded = $rows('SELECT mail_template_event, mail_template_subject, mail_template_body, mail_template_enabled, mail_template_updated_by FROM mail_templates ORDER BY mail_template_id');
$allDefault = count($seeded) === 7;
foreach ($seeded as $s) { $allDefault = $allDefault && $s['mail_template_subject'] === $mtDefault($s['mail_template_event'])['subject'] && $s['mail_template_body'] === $mtDefault($s['mail_template_event'])['body'] && $s['mail_template_enabled'] === '1' && $s['mail_template_updated_by'] === '0'; }
$ok($allDefault, 'every event seeded with its exact default, enabled, never customized');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name IN ('config_mail_template_header_html', 'config_mail_template_footer_html') AND column_type = 'mediumtext' AND is_nullable = 'YES'") === 2, 'both settings columns exist (nullable mediumtext)');
$create = (string) ($db->query('SHOW CREATE TABLE mail_templates')->fetch_row()[1] ?? '');
$create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
$dbsql = (string) file_get_contents("$root/db.sql");
preg_match('/CREATE TABLE `mail_templates` \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $dbsql, $dm);
$ok(($dm[0] ?? '') === $create . ';', 'db.sql\'s mail_templates DDL is exactly what the step creates');
$ok(str_contains($dbsql, "  `config_mail_template_header_html` mediumtext DEFAULT NULL,\n  `config_mail_template_footer_html` mediumtext DEFAULT NULL,"), 'db.sql has both settings columns');
// re-run is harmless; an old default text on an untouched row is re-synced, a customized row never
$q("UPDATE settings SET config_current_database_version = '2.6.106' WHERE company_id = 1");
shell_exec('cd ' . escapeshellarg("$root/scripts") . ' && php update_cli.php --update_db </dev/null 2>&1');
$ok((int) $one('SELECT COUNT(*) FROM mail_templates') === 7 && $one('SELECT config_current_database_version FROM settings WHERE company_id = 1') === '2.6.107', 'running the step twice: still seven rows, version 2.6.107');
$q("UPDATE mail_templates SET mail_template_body = 'OLD DEFAULT TEXT', mail_template_subject = 'OLD' WHERE mail_template_event = 'ticket_reply'");
$q("UPDATE mail_templates SET mail_template_body = 'ADMIN TEXT', mail_template_subject = 'ADMIN', mail_template_updated_by = 1 WHERE mail_template_event = 'ticket_closed'");
$q("UPDATE mail_templates SET mail_template_subject = LOWER(mail_template_subject) WHERE mail_template_event = 'ticket_created'");
$q("DELETE FROM mail_templates WHERE mail_template_event = 'password_reset'");
$n = mailTemplateSyncDefaults($db);
$ok($one("SELECT mail_template_body FROM mail_templates WHERE mail_template_event = 'ticket_reply'") === $mtDefault('ticket_reply')['body'], 'an untouched row (updated_by = 0) with stale text is brought up to the current default');
$ok($one("SELECT mail_template_subject FROM mail_templates WHERE mail_template_event = 'ticket_created'") === $mtDefault('ticket_created')['subject'], 'a case-only difference is re-synced too (binary comparison, not the ci collation)');
$ok($one("SELECT mail_template_body FROM mail_templates WHERE mail_template_event = 'ticket_closed'") === 'ADMIN TEXT', 'a customized row (updated_by <> 0) is never overwritten');
$ok((int) $one("SELECT COUNT(*) FROM mail_templates WHERE mail_template_event = 'password_reset'") === 1 && $n === 3, 'a missing event row is seeded again (3 rows written)');
$ok(mailTemplateSyncDefaults($db) === 0, 'a second sync has nothing to do');
mailTemplateResetToDefault($db, 'ticket_closed');

// ---------------------------------------------------------------------------------------------------------------------------------------------
echo "\n--- the mail queue and its viewer are untouched ---\n";
$diffOut = []; $diffRc = 1;
exec('git -C ' . escapeshellarg($root) . ' diff --stat ' . escapeshellarg($BASE_COMMIT) . ' -- cron/mail_queue.php admin/mail_queue.php admin/post/mail_queue.php src/Mail/MailQueuePolicy.php 2>/dev/null', $diffOut, $diffRc);
$diff = $diffRc === 0 ? implode("\n", $diffOut) : null;
$oldFns = $gitShow('functions.php');
if ($diff === null || $oldFns === null) {
    echo "SKIP  git not available\n";
} else {
    $ok(trim((string) $diff) === '', 'no change to cron/mail_queue.php, the queue viewer (admin/mail_queue.php + its handler) or MailQueuePolicy');
    $fnOf = function (string $src): string { $s = strpos($src, 'function addToMailQueue('); $e = strpos($src, "\nfunction ", $s + 10); return substr($src, $s, $e - $s); };
    $ok($fnOf($oldFns) === $fnOf((string) file_get_contents("$root/functions.php")), 'addToMailQueue() is byte-identical to before');
}
$ok(!preg_match('/new PHPMailer|INSERT INTO email_queue/i', (string) file_get_contents("$root/includes/mail_templates.php") . file_get_contents("$root/admin/post/settings_mail_templates.php")), 'Email Templates never writes the queue itself (only addToMailQueue())');

$q("UPDATE users SET user_name = 'admin' WHERE user_id = 1");
$serverLog = (string) @file_get_contents($web['log']);
// (The harness posts only the fields each check needs and sends no User-Agent, so some old handlers log "Undefined array key" warnings; those are
// not this feature's. Anything fatal, any undefined variable / null offset, or any warning from Email Templates' own files fails.)
$bad = preg_grep('/PHP (Fatal|Parse)|Undefined variable|array offset on value of type null|PHP (Warning|Notice).*(mail_templates|theme_accent|login_reset|cron\.php)/', array_filter(explode("\n", $serverLog), fn($l) => !str_contains($l, 'HTTP_USER_AGENT')));
$ok($bad === [], 'no PHP fatal, undefined variable or Email Templates warning in the server log' . ($bad ? ': ' . implode(' | ', array_slice($bad, 0, 3)) : ''));
