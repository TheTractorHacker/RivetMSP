<?php
// MSP-DS-88: Content-Security-Policy for pages that would otherwise send none.

// MSP-DS-183: installation-specific CSP sources come from config.php, never from the product. Defaults are strict (self/data/blob only).
//   $config_csp_img_src = 'https://*.example.com https://cdn.example.net';   // extra image origins (https only, validated)
//   $config_csp_cloudflare_insights = true;                                  // allow the Cloudflare Web Analytics beacon (script + connect)
/** Extra https origins for img-src, from $config_csp_img_src; anything that is not a plain https origin (optionally *.host, optional port) is dropped. */
function cspConfiguredImgSources(): string
{
    $raw = (string) ($GLOBALS['config_csp_img_src'] ?? '');
    $out = [];
    foreach (preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $o) {
        if (preg_match('#^https://(\*\.)?[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$#i', $o)) {
            $out[] = $o;
        }
    }
    return $out ? ' ' . implode(' ', array_unique($out)) : '';
}

/** The img-src directive for agent, login and guest pages. */
function cspImgSrc(): string
{
    return "img-src 'self' data: blob:" . cspConfiguredImgSources();
}

/** Cloudflare Web Analytics beacon origins, only when the admin switched it on in config.php. */
function cspCloudflareScript(): string
{
    return !empty($GLOBALS['config_csp_cloudflare_insights']) ? ' https://static.cloudflareinsights.com' : '';
}

function cspCloudflareConnect(): string
{
    return !empty($GLOBALS['config_csp_cloudflare_insights']) ? ' https://cloudflareinsights.com' : '';
}

/** Policy for client-portal pages that queue no CSP of their own (script nonce for the portal's inline scripts; Stripe for the saved-card page). */
function portalFallbackCsp(string $nonce): string
{
    return "default-src 'self'; script-src 'self' 'nonce-$nonce' https://js.stripe.com; style-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https://api.stripe.com; "
         . "frame-src https://js.stripe.com https://hooks.stripe.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
}

/** Headers for the public guest signing pages (same policy and companions as guest/includes/inc_all_guest.php). */
function sendGuestSigningHeaders(string $nonce): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'" . cspCloudflareScript() . "; style-src 'self' 'unsafe-inline'; " . cspImgSrc() . "; connect-src 'self'" . cspCloudflareConnect() . "; frame-ancestors 'none'");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}
