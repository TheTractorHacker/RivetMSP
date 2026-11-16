<?php
// MSP-DS-158 (re-review MRR-10): getIP() in HTTP_CF_CONNECTING_IP mode believes the header only from a trusted peer
// (CONST_TRUSTED_PROXIES, CONST_CLOUDFLARE_RANGES, or - when neither is configured - a private/loopback peer). Runs the REAL getIP() from
// functions.php in a child process per case (the constants can only be defined once per process).
// Run: php tests/msp_mrr_10.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(dirname(__DIR__) . '/functions.php');
$a = strpos($src, 'function ipInTrustedList'); $b = strpos($src, 'function getWebBrowser');
$code = substr($src, $a, $b - $a);
$run = function (string $method, ?string $trusted, ?string $cfRanges, string $remote, ?string $header) use ($code): string {
    $pre = 'define("CONST_GET_IP_METHOD", ' . var_export($method, true) . ');'
        . ($trusted !== null ? 'define("CONST_TRUSTED_PROXIES", ' . var_export($trusted, true) . ');' : '')
        . ($cfRanges !== null ? 'define("CONST_CLOUDFLARE_RANGES", ' . var_export($cfRanges, true) . ');' : '')
        . '$_SERVER["REMOTE_ADDR"] = ' . var_export($remote, true) . ';'
        . ($header !== null ? '$_SERVER["HTTP_CF_CONNECTING_IP"] = ' . var_export($header, true) . ';' : '');
    $p = proc_open([PHP_BINARY, '-r', $pre . $code . 'echo getIP();'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    return trim($out);
};
$CF = 'HTTP_CF_CONNECTING_IP';
$cfr = '173.245.48.0/20,103.21.244.0/22,2400:cb00::/32';

// no list configured: only a private/loopback peer is believed
$ok('no list, public peer: header ignored (the spoof)', $run($CF, null, null, '203.0.113.9', '1.2.3.4') === '203.0.113.9');
$ok('no list, loopback peer: header used (local reverse proxy)', $run($CF, null, null, '127.0.0.1', '198.51.100.7') === '198.51.100.7');
$ok('no list, RFC1918 peer: header used', $run($CF, null, null, '10.1.1.4', '198.51.100.7') === '198.51.100.7');
$ok('no list, IPv6 loopback peer: header used', $run($CF, null, null, '::1', '198.51.100.7') === '198.51.100.7');
$ok('no list, IPv4-mapped loopback peer: header used', $run($CF, null, null, '::ffff:127.0.0.1', '198.51.100.7') === '198.51.100.7');
$ok('no list, public IPv6 peer: header ignored', $run($CF, null, null, '2001:db8::5', '1.2.3.4') === '2001:db8::5');
$ok('no header: peer address', $run($CF, null, null, '127.0.0.1', null) === '127.0.0.1');
$ok('malformed header: peer address', $run($CF, null, null, '127.0.0.1', 'not-an-ip') === '127.0.0.1');

// CONST_TRUSTED_PROXIES configured: only those peers (a private peer that is not listed is NOT trusted any more)
$ok('trusted list, listed peer: header used', $run($CF, '192.0.2.0/24', null, '192.0.2.10', '198.51.100.7') === '198.51.100.7');
$ok('trusted list, unlisted public peer: ignored', $run($CF, '192.0.2.0/24', null, '203.0.113.9', '1.2.3.4') === '203.0.113.9');
$ok('trusted list, unlisted loopback peer: ignored (explicit list wins)', $run($CF, '192.0.2.0/24', null, '127.0.0.1', '1.2.3.4') === '127.0.0.1');
$ok('trusted list as an array constant string with several entries', $run($CF, '192.0.2.0/24, 10.0.0.0/8', null, '10.9.9.9', '198.51.100.7') === '198.51.100.7');

// Cloudflare ranges configured
$ok('CF ranges, peer inside: header used', $run($CF, null, $cfr, '173.245.50.1', '198.51.100.7') === '198.51.100.7');
$ok('CF ranges, IPv6 peer inside: header used', $run($CF, null, $cfr, '2400:cb00::7', '198.51.100.7') === '198.51.100.7');
$ok('CF ranges, direct public peer outside: ignored', $run($CF, null, $cfr, '203.0.113.9', '1.2.3.4') === '203.0.113.9');
$ok('CF ranges, loopback peer not listed: ignored', $run($CF, null, $cfr, '127.0.0.1', '1.2.3.4') === '127.0.0.1');
$ok('CF ranges + trusted proxies together', $run($CF, '127.0.0.1', $cfr, '127.0.0.1', '198.51.100.7') === '198.51.100.7' && $run($CF, '127.0.0.1', $cfr, '103.21.244.9', '198.51.100.7') === '198.51.100.7');

// other modes untouched
$ok('default mode ignores the header', $run('REMOTE_ADDR', null, null, '127.0.0.1', '1.2.3.4') === '127.0.0.1');
$ok('XFF mode with no trusted list still ignores XFF', $run('HTTP_X_FORWARDED_FOR', null, null, '127.0.0.1', '1.2.3.4') === '127.0.0.1');
exit($fail ? 1 : 0);
