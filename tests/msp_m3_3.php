<?php
// MSP-DS-158 (re-review M3-3 + M3-4): CF-Connecting-IP trust. (3) no private/loopback fallback, malformed list entries never match (a '/abc'
// prefix used to act as /0 and trust everyone); (4) Cloudflare's ranges are the built-in default so a Cloudflare-direct install with no config
// sees real visitors, and an explicit CONST_CLOUDFLARE_RANGES replaces them. Runs the REAL functions from functions.php, one child process per
// getIP() case (constants are defined once per process).
// Run: php tests/msp_m3_3.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$src = file_get_contents(dirname(__DIR__) . '/functions.php');
$a = strpos($src, 'function ipInTrustedList'); $b = strpos($src, 'function getWebBrowser');
$code = str_replace('__DIR__', var_export(dirname(__DIR__), true), substr($src, $a, $b - $a));
$run = function (?string $trusted, ?string $cfRanges, string $remote, ?string $header, string $method = 'HTTP_CF_CONNECTING_IP') use ($code): string {
    $pre = 'define("CONST_GET_IP_METHOD", ' . var_export($method, true) . ');'
        . ($trusted !== null ? 'define("CONST_TRUSTED_PROXIES", ' . var_export($trusted, true) . ');' : '')
        . ($cfRanges !== null ? 'define("CONST_CLOUDFLARE_RANGES", ' . var_export($cfRanges, true) . ');' : '')
        . '$_SERVER["REMOTE_ADDR"] = ' . var_export($remote, true) . ';'
        . ($header !== null ? '$_SERVER["HTTP_CF_CONNECTING_IP"] = ' . var_export($header, true) . ';' : '');
    $p = proc_open([PHP_BINARY, '-r', $pre . $code . 'echo getIP();'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    return trim($out);
};
// M3-3: no private/loopback fallback
$ok('local proxy peer, nothing listed: spoofed header ignored', $run(null, null, '127.0.0.1', '1.2.3.4') === '127.0.0.1');
$ok('RFC1918 peer, nothing listed: spoofed header ignored', $run(null, null, '192.168.1.5', '1.2.3.4') === '192.168.1.5');
$ok('local proxy listed in CONST_TRUSTED_PROXIES: header used', $run('127.0.0.1', null, '127.0.0.1', '198.51.100.7') === '198.51.100.7');
$ok('IPv4-mapped loopback listed as 127.0.0.1: header used', $run('127.0.0.1', null, '::ffff:127.0.0.1', '198.51.100.7') === '198.51.100.7');
// M3-4: Cloudflare ranges built in
$ok('Cloudflare edge peer, no config: real visitor from the header', $run(null, null, '172.69.10.4', '198.51.100.7') === '198.51.100.7');
$ok('direct public peer, no config: header ignored', $run(null, null, '198.51.100.99', '1.2.3.4') === '198.51.100.99');
$ok('explicit CONST_CLOUDFLARE_RANGES replaces the built-in list', $run(null, '192.0.2.0/24', '172.69.10.4', '198.51.100.7') === '172.69.10.4');
$ok('the built-in file lists v4 and v6 ranges', ($r = require dirname(__DIR__) . '/includes/cloudflare_ranges.php') && in_array('104.16.0.0/13', $r, true) && in_array('2606:4700::/32', $r, true));
// malformed entries
// the real code from functions.php, minus getIP() (it exits on a bad address and needs constants); test-only eval
eval(substr($code, 0, strpos($code, 'function getIP')));
$ok("'1.2.3.4/abc' does not trust everyone", ipInTrustedList('203.0.113.9', ['1.2.3.4/abc']) === false);
$ok("'1.2.3.4/' (empty prefix) is rejected", ipInTrustedList('203.0.113.9', ['1.2.3.4/']) === false);
$ok("'0.0.0.0/33' (out of range) is rejected", ipInTrustedList('203.0.113.9', ['0.0.0.0/33']) === false);
$ok("'::/129' (out of range) is rejected", ipInTrustedList('2001:db8::1', ['::/129']) === false);
$ok("'10.0.0.0/-1' is rejected", ipInTrustedList('10.0.0.1', ['10.0.0.0/-1']) === false);
$ok("'10.0.0.0/8x' is rejected", ipInTrustedList('10.0.0.1', ['10.0.0.0/8x']) === false);
$ok("'10.0.0.0/ 8' is rejected", ipInTrustedList('10.0.0.1', ['10.0.0.0/ 8']) === false);
$ok("a v6 prefix on a v4 address is skipped", ipInTrustedList('10.0.0.1', ['::/0']) === false);
$ok("valid entries still match: /8, /32, plain, /0, v6 /32", ipInTrustedList('10.9.9.9', ['10.0.0.0/8']) && ipInTrustedList('1.2.3.4', ['1.2.3.4/32']) && ipInTrustedList('1.2.3.4', ['1.2.3.4']) && ipInTrustedList('8.8.8.8', ['0.0.0.0/0']) && ipInTrustedList('2606:4700::9', ['2606:4700::/32']));
$ok('a malformed entry beside a valid one only drops the bad one', ipInTrustedList('10.9.9.9', ['1.2.3.4/abc', '10.0.0.0/8']) && !ipInTrustedList('203.0.113.9', ['1.2.3.4/abc', '10.0.0.0/8']));
exit($fail ? 1 : 0);
