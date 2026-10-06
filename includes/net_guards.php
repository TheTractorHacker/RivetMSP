<?php

/*
 * Outbound-request guards that need no database or request context.
 */

// True only when every A/AAAA address of the host (or the literal IP) is public. Unresolvable hosts return false.
function hostResolvesOnlyToPublicIps($host)
{
    $host = trim((string) $host, '[]');
    if ($host === '') {
        return false;
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
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}
