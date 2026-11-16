<?php
// MSP-DS-88: Content-Security-Policy for pages that would otherwise send none.

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
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.foleyit.com; connect-src 'self' https://cloudflareinsights.com; frame-ancestors 'none'");
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}
