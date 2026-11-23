<?php

/*
 * Outbound-request guards that need no database or request context.
 */

/**
 * MSP-DS-107: the vetted addresses of a host, or [] when the host does not resolve or ANY of its addresses is not public. "Public" is RivetCore's
 * UrlPolicy::isPublicIp(): beyond the PHP FILTER_FLAG_NO_PRIV/NO_RES flags it also refuses CGNAT 100.64/10, 198.18/15, NAT64 and 6to4 (and the IPv4 they embed).
 * A caller that goes on to connect must connect to one of THESE addresses, not re-resolve the name (DNS rebinding).
 *
 * @return list<string>
 */
function publicIpsForHost($host): array
{
    $host = trim((string) $host, '[]');
    if ($host === '') {
        return [];
    }
    if (!class_exists('RivetCore\\Webhooks\\UrlPolicy')) {
        if (function_exists('rivetEnsureAutoload')) {
            rivetEnsureAutoload();
        }
        if (!class_exists('RivetCore\\Webhooks\\UrlPolicy') && is_file(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
        }
    }
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach ((array) @dns_get_record($host, DNS_A + DNS_AAAA) as $rec) {
            if (!empty($rec['ip'])) {
                $ips[] = $rec['ip'];
            } elseif (!empty($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
    }
    if (!$ips) {
        return [];
    }
    foreach ($ips as $ip) {
        if (!\RivetCore\Webhooks\UrlPolicy::isPublicIp($ip)) {
            return [];
        }
    }
    return array_values($ips);
}

// True only when every A/AAAA address of the host (or the literal IP) is public. Unresolvable hosts return false.
function hostResolvesOnlyToPublicIps($host)
{
    return publicIpsForHost($host) !== [];
}
