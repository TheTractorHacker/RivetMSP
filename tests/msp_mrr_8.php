<?php
// MSP-DS-77 (re-review MRR-8): $config_passkey_mobile_require_uv is documented and its state is shown on the identity page. Default stays OFF.
// Run: php tests/msp_mrr_8.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
require $root . '/includes/security_policy.php';
unset($GLOBALS['config_passkey_mobile_require_uv']);
$ok('unset switch reads as off (default preserved)', secMobilePasskeyUvRequired() === false);
$GLOBALS['config_passkey_mobile_require_uv'] = false;
$ok('false reads as off', secMobilePasskeyUvRequired() === false);
$GLOBALS['config_passkey_mobile_require_uv'] = true;
$ok('true reads as on', secMobilePasskeyUvRequired() === true);
$ok('api/v1/auth.php still keys off the same switch', str_contains(file_get_contents($root . '/api/v1/auth.php'), '$config_passkey_mobile_require_uv'));
$page = file_get_contents($root . '/admin/identity_provider.php');
$ok('identity page shows the state via the helper', str_contains($page, 'secMobilePasskeyUvRequired()'));
$ok('API docs name the switch', str_contains(file_get_contents($root . '/docs/API.md'), '$config_passkey_mobile_require_uv = true;'));
exit($fail ? 1 : 0);
