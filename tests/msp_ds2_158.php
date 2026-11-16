<?php
// MSP-DS-158: proxy-mode installs. (1) the app's X-Forwarded-For handling is only believed from a trusted proxy and is read from the right;
// (2) the rendered proxy-mode vhost gets nginx real_ip lines for --trusted-proxy (and none in direct-TLS mode).
// Runs the real functions from functions.php and deploy/lib/common.sh. Run: php tests/msp_ds2_158.php
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$root = dirname(__DIR__);
$src = file_get_contents($root . '/functions.php');
$a = strpos($src, 'function ipInTrustedList'); $b = strpos($src, 'function getIP()');
eval(substr($src, $a, $b - $a));
$trusted = ['10.0.0.0/8', '192.168.1.5', '2001:db8::/32'];

$ok('CIDR match v4', ipInTrustedList('10.20.30.40', $trusted) && !ipInTrustedList('11.0.0.1', $trusted));
$ok('exact address match', ipInTrustedList('192.168.1.5', $trusted) && !ipInTrustedList('192.168.1.6', $trusted));
$ok('CIDR match v6', ipInTrustedList('2001:db8::1', $trusted) && !ipInTrustedList('2001:db9::1', $trusted));
$ok('odd prefix length (/21)', ipInTrustedList('172.16.7.9', ['172.16.0.0/21']) && !ipInTrustedList('172.16.8.1', ['172.16.0.0/21']));

$ok('untrusted peer: the header is ignored (spoof attempt)', clientIpFromForwardedFor('203.0.113.9', '1.2.3.4', $trusted) === '203.0.113.9');
$ok('no trusted list configured: the header is ignored', clientIpFromForwardedFor('10.0.0.1', '1.2.3.4', []) === '10.0.0.1');
$ok('trusted proxy: single client hop is used', clientIpFromForwardedFor('10.0.0.1', '198.51.100.7', $trusted) === '198.51.100.7');
$ok('client-supplied left-most entry is NOT used', clientIpFromForwardedFor('10.0.0.1', '1.2.3.4, 198.51.100.7', $trusted) === '198.51.100.7');
$ok('trusted hops on the right are skipped', clientIpFromForwardedFor('10.0.0.1', '1.2.3.4, 198.51.100.7, 10.0.0.9', $trusted) === '198.51.100.7');
$ok('malformed chain falls back to the peer', clientIpFromForwardedFor('10.0.0.1', '198.51.100.7, not-an-ip', $trusted) === '10.0.0.1');
$ok('all hops trusted falls back to the peer', clientIpFromForwardedFor('10.0.0.1', '10.0.0.2, 10.0.0.3', $trusted) === '10.0.0.1');

// vhost rendering exactly as install.sh does it
$tpl = $root . '/deploy/templates/nginx-vhost.conf.template';
$tmp = tempnam(sys_get_temp_dir(), 'vh');
$render = function (bool $proxy, string $list) use ($root, $tpl, $tmp) {
    $strip = $proxy
        ? "sed -i '/# BEGIN DIRECT-TLS ONLY/,/# END DIRECT-TLS ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN PROXY-MODE ONLY/d; /# END PROXY-MODE ONLY/d' \"\$T\""
        : "sed -i '/# BEGIN PROXY-MODE ONLY/,/# END PROXY-MODE ONLY/d; /# __PROXY_MODE_BLOCK__/d; /# BEGIN DIRECT-TLS ONLY/d; /# END DIRECT-TLS ONLY/d' \"\$T\"";
    $apply = $proxy ? 'apply_real_ip_block "$T" ' . escapeshellarg($list) : ':';
    $script = 'set -u; source ' . escapeshellarg($root . '/deploy/lib/common.sh') . '; T=' . escapeshellarg($tmp) . '; cp ' . escapeshellarg($tpl) . ' "$T"; '
        . 'n=$(grep -n -v -E "^#|^$" "$T" | head -1 | cut -d: -f1); sed -i "1,$((n - 1))d" "$T"; ' . $strip . '; ' . $apply . '; cat "$T"';
    $p = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); $rc = proc_close($p);
    return [$out, $err, $rc];
};
[$out, $err, $rc] = $render(true, '10.1.1.0/24, 2001:db8::1');
$ok('proxy mode + --trusted-proxy: set_real_ip_from for each proxy', $rc === 0 && str_contains($out, 'set_real_ip_from 10.1.1.0/24;') && str_contains($out, 'set_real_ip_from 2001:db8::1;'));
$ok('proxy mode: real_ip_header X-Forwarded-For and recursive', str_contains($out, 'real_ip_header X-Forwarded-For;') && str_contains($out, 'real_ip_recursive on;'));
$ok('no placeholder or marker left behind', !str_contains($out, '__REAL_IP_BLOCK__') && !str_contains($out, 'PROXY-MODE ONLY'));
[$out, $err, $rc] = $render(true, '');
$ok('proxy mode without a proxy list: no real_ip lines, a warning is printed', $rc === 0 && !str_contains($out, 'real_ip') && str_contains($err, 'trusted-proxy'));
[$out, $err, $rc] = $render(true, '10.0.0.1; rm -rf /');
$ok('an invalid proxy value is refused', $rc !== 0 && !str_contains($out, 'set_real_ip_from'));
[$out, $err, $rc] = $render(false, '');
$ok('direct-TLS mode: no real_ip lines at all', $rc === 0 && !str_contains($out, 'real_ip') && !str_contains($out, '__REAL_IP_BLOCK__'));
@unlink($tmp);
$ok('install.sh and harden.sh both accept --trusted-proxy and call the helper', str_contains(file_get_contents($root . '/deploy/install.sh'), 'apply_real_ip_block "${vhost_tmp}"') && str_contains(file_get_contents($root . '/deploy/harden.sh'), '--trusted-proxy)'));
exit($fail ? 1 : 0);
