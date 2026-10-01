<?php
/** RivetMSP product identity. Company names and uploaded logos retain precedence. */
if (!defined('APP_NAME')) {
    define('APP_NAME', 'RivetMSP');
}

// Product assets; uploaded company branding takes precedence in company-facing areas.
foreach ([
    'APP_LOGO_URL' => '/img/branding/rivetmsp-logo.png',
    'APP_LOGO_MARK_URL' => '/img/branding/logo-mark.svg?v=rivetmsp-2',
    'APP_FAVICON_URL' => '/img/branding/favicon.svg?v=rivetmsp-2',
] as $brand_constant => $brand_value) {
    if (!defined($brand_constant)) {
        define($brand_constant, $brand_value);
    }
}
unset($brand_constant, $brand_value);

function appDisplayName(?string $name): string
{
    $name = trim($name ?? '');
    return in_array($name, ['', 'ITFlow', 'ITFlow MSP', 'ITFlow MSP Edition', 'ITFlow — MSP Edition'], true)
        ? APP_NAME : $name;
}

/** An MSP upload replaces the product artwork; callers escape the returned URL for HTML. */
function appCompanyLogoUrl(?string $logo, string $fallback = APP_LOGO_URL): string
{
    return !empty($logo) ? '/uploads/settings/' . rawurlencode(basename($logo)) : $fallback;
}
