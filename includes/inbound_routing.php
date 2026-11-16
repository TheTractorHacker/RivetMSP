<?php
// Which client an inbound mail may be filed into without a person looking at it (MSP-DS-33, MSP-DS-16).
//
// Two claims route mail automatically: a contact row with the sender's address, and a domain row for the sender's domain. Anyone who
// can add a domain (a Write-level tech) or a contact (a portal contact) could claim a sender they do not own. So:
//   - a domain routes mail only when it is not a public mailbox provider and exactly one client lists it;
//   - a contact routes mail only when one client holds that address and no other client is the sole owner of its domain.
// Anything else goes to the unknown-sender review queue (a person decides).

/** Public mailbox providers: never a basis for routing a sender to a client, and not accepted as a client's domain. */
function inboundFreemailDomains(): array
{
    return [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'ymail.com', 'rocketmail.com',
        'aol.com', 'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'pm.me', 'gmx.com', 'gmx.net', 'mail.com', 'zoho.com',
        'yandex.com', 'yandex.ru', 'fastmail.com', 'tutanota.com', 'tuta.io', 'comcast.net', 'att.net', 'sbcglobal.net', 'verizon.net',
        'cox.net', 'charter.net', 'earthlink.net', 'bellsouth.net', 'qq.com', '163.com', '126.com',
        'yahoo.co.uk', 'hotmail.co.uk', 'outlook.co.uk', 'btinternet.com', 'web.de', 'orange.fr', 'free.fr', 'laposte.net',
    ];
}

function inboundNormalizeDomain(string $domain): string
{
    return strtolower(trim($domain, " \t\n\r\0\x0B."));
}

function inboundIsFreemailDomain(string $domain): bool
{
    return in_array(inboundNormalizeDomain($domain), inboundFreemailDomains(), true);
}

/**
 * The one client that lists this domain, or 0 when it is a public mailbox provider, unlisted, or listed by more than one client.
 * $routableOnly (MSP-DS-16/33): additionally require that an administrator switched 'route inbound mail' on for the listing
 * (domains.domain_route_mail). Mail routing passes true; the ownership-conflict checks (contact claims, portal contacts) pass false,
 * because a listed domain is somebody's even when it is not routable.
 */
function inboundDomainOwnerClient(mysqli $mysqli, string $domain, bool $routableOnly = false): int
{
    $domain = inboundNormalizeDomain($domain);
    if ($domain === '' || inboundIsFreemailDomain($domain)) {
        return 0;
    }
    $esc = mysqli_real_escape_string($mysqli, $domain);
    $res = mysqli_query($mysqli, "SELECT domain_client_id, MAX(domain_route_mail) AS route FROM domains WHERE domain_name = '$esc' AND domain_archived_at IS NULL AND domain_client_id > 0 GROUP BY domain_client_id LIMIT 2");
    $ids = [];
    $route = 0;
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $ids[] = (int) $r['domain_client_id'];
        $route = (int) $r['route'];
    }
    if (count($ids) !== 1 || ($routableOnly && $route !== 1)) {
        return 0;
    }
    return $ids[0];
}

/**
 * The active contact an inbound sender may be filed under automatically, or null when none exists or the claim is not safe
 * (the address is held by contacts of several clients, or the sender's domain belongs solely to another client).
 */
function inboundTrustedContact(mysqli $mysqli, string $email): ?array
{
    $esc = mysqli_real_escape_string($mysqli, $email);
    $res = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_email = '$esc' AND contact_archived_at IS NULL ORDER BY contact_id ASC LIMIT 20");
    $rows = [];
    $clients = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $rows[] = $r;
        $clients[(int) $r['contact_client_id']] = true;
    }
    if (!$rows || count($clients) > 1) {
        return null;
    }
    $at = strrpos($email, '@');
    $owner = $at === false ? 0 : inboundDomainOwnerClient($mysqli, substr($email, $at + 1));
    if ($owner > 0 && $owner !== (int) $rows[0]['contact_client_id']) {
        return null;
    }
    return $rows[0];
}

/**
 * MSP-DS-91: true when this client's portal has been refused $max contact addresses within the last hour (logged as 'Contact Address
 * Refused'). The add/edit contact forms then refuse everything with their one generic message, so they cannot be used as a
 * who-has-an-account oracle by trying many addresses.
 */
function portalContactProbeLimited(mysqli $mysqli, int $clientId, int $max = 5): bool
{
    $stmt = mysqli_prepare($mysqli, "SELECT COUNT(*) FROM logs WHERE log_type = 'Client Portal' AND log_action = 'Contact Address Refused' AND log_client_id = ? AND log_created_at > NOW() - INTERVAL 1 HOUR");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $clientId);
    mysqli_stmt_execute($stmt);
    $n = 0;
    mysqli_stmt_bind_result($stmt, $n);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return $n >= $max;
}

/**
 * Portal contacts may not claim an address that already belongs to a contact of another client (archived ones count), nor an address
 * whose domain another client lists. Returns the reason, or '' when the address is free for this client.
 * $email must be the RAW address (not already SQL-escaped): this function escapes it itself (MSP-DS-16, review R33).
 */
function portalContactEmailConflict(mysqli $mysqli, string $email, int $clientId, int $exceptContactId = 0): string
{
    $esc = mysqli_real_escape_string($mysqli, $email);
    $r = mysqli_query($mysqli, "SELECT 1 FROM contacts WHERE contact_email = '$esc' AND contact_client_id != $clientId AND contact_id != $exceptContactId LIMIT 1");
    if ($r && mysqli_num_rows($r) > 0) {
        return 'contact';
    }
    $at = strrpos($email, '@');
    if ($at !== false) {
        $domain = inboundNormalizeDomain(substr($email, $at + 1));
        if ($domain !== '' && !inboundIsFreemailDomain($domain)) {
            $d = mysqli_real_escape_string($mysqli, $domain);
            $r = mysqli_query($mysqli, "SELECT 1 FROM domains WHERE domain_name = '$d' AND domain_archived_at IS NULL AND domain_client_id > 0 AND domain_client_id != $clientId LIMIT 1");
            if ($r && mysqli_num_rows($r) > 0) {
                return 'domain';
            }
        }
    }
    return '';
}
