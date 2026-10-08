<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * TLS verification for the Comet and UniFi integrations, no database or network:  php tests/tls_verification.php
 */
require __DIR__ . '/../includes/class_unifi.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$comet = file_get_contents(__DIR__ . '/../includes/comet.php');
$ok(!preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/', $comet), 'comet.php never hard-codes VERIFYPEER=false');
$ok(!preg_match('/CURLOPT_SSL_VERIFYHOST\s*=>\s*0/', $comet), 'comet.php never hard-codes VERIFYHOST=0');
$ok(substr_count($comet, '+ comet_tls_options()') === 2, 'both Comet requests use comet_tls_options()');

require_once __DIR__ . '/../includes/comet.php';
$o = comet_tls_options();
$ok($o[CURLOPT_SSL_VERIFYPEER] === true && $o[CURLOPT_SSL_VERIFYHOST] === 2, 'default: verification on');
$config_comet_verify_ssl = 1; $GLOBALS['config_comet_verify_ssl'] = 1;
$o = comet_tls_options();
$ok($o[CURLOPT_SSL_VERIFYPEER] === true, 'setting=1: verification on');
$GLOBALS['config_comet_verify_ssl'] = 0;
$o = comet_tls_options();
$ok($o[CURLOPT_SSL_VERIFYPEER] === false && $o[CURLOPT_SSL_VERIFYHOST] === 0, 'explicit admin opt-out (setting=0) is the only way to disable');

$unifi = file_get_contents(__DIR__ . '/../includes/class_unifi.php');
$proxy = substr($unifi, strpos($unifi, 'private function proxyGet'));
$proxy = substr($proxy, 0, strpos($proxy, 'public function getLocalSites'));
$ok(strpos($proxy, 'CURLOPT_SSL_VERIFYPEER => true') !== false && strpos($proxy, 'CURLOPT_FOLLOWLOCATION => false') !== false, 'proxyGet verifies TLS and does not follow redirects with the API key');

foreach (['abc123.id.ui.direct', 'a-b.c1.id.ui.direct', 'ABC.ID.UI.DIRECT'] as $d) $ok(UniFiLocalOrCloud_domain_ok($d), "accepts $d");
foreach (['evil.com', 'id.ui.direct.evil.com', 'x.id.ui.direct@evil.com', 'x.id.ui.direct:8443', '127.0.0.1', '', 'a b.id.ui.direct', 'x.id.ui.direct/../', '-x.id.ui.direct', "x.id.ui.direct\n"] as $d) $ok(!UniFiLocalOrCloud_domain_ok($d), "rejects '$d'");

function UniFiLocalOrCloud_domain_ok(string $d): bool {
    foreach (get_declared_classes() as $c) {
        if (method_exists($c, 'isValidDirectDomain')) return $c::isValidDirectDomain($d);
    }
    return false;
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
