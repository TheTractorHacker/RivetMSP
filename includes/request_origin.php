<?php
// Same-origin check for session-cookie endpoints that cannot carry a form CSRF token (TinyMCE's image upload, MSP-DS-29).

/**
 * False when a browser says the request came from another site. Sec-Fetch-Site (all current browsers) must be same-origin; failing that,
 * the Origin header's host must equal the Host header. A request that carries neither header is not a cross-site browser request.
 */
function requestIsSameOrigin(array $server): bool
{
    $site = strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '') {
        return $site === 'same-origin';
    }
    $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return true;
    }
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ':' . $p : ''));
    return $originHost !== '' && $originHost === strtolower((string) ($server['HTTP_HOST'] ?? ''));
}
