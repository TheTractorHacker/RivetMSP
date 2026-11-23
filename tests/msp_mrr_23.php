<?php
// MSP-DS-182 re-review MRR-23: plugin PHP must not be reachable through PATH_INFO forms (/plugins/x/a.php/foo).
// Part 1 (static, no Apache binary on this box): the real .htaccess RedirectMatch patterns are evaluated as PCRE against a URL list.
// Part 2: the real deploy template and docker/nginx.conf in a throwaway nginx (nginx 404s these already; the nested denies must not regress).
// Run: php tests/msp_mrr_23.php   (the nginx part is skipped with a note when the binary is missing)
require __DIR__ . '/lib/throwaway_nginx.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ht = file_get_contents(dirname(__DIR__) . '/.htaccess');
preg_match_all('/^RedirectMatch\s+(\d+)\s+(\S+)\s*$/m', $ht, $m, PREG_SET_ORDER);
$status = function (string $url) use ($m): int { foreach ($m as $r) { if (preg_match('~' . str_replace('~', '\~', $r[2]) . '~', $url)) { return (int) $r[1]; } } return 200; };
$ok('.htaccess RedirectMatch rules were parsed', count($m) >= 2);
foreach (['/plugins/x/a.php', '/plugins/PHPMailer/get_oauth_token.php/x', '/plugins/x/a.php/foo/bar', '/plugins/x/a.phtml/y', '/plugins/x/a.phar/', '/plugins/a/b.phps/z'] as $u) { $c = $status($u); $ok(".htaccess refuses $u (got $c)", $c === 403); }
foreach (['/plugins/x/a.css', '/plugins/x/a.js', '/plugins/x/phpfile.txt', '/agent/index.php'] as $u) { $ok(".htaccess leaves $u alone", $status($u) === 200); }
$ok('.htaccess still hides config/ and .git', $status('/config/x.json') === 404 && $status('/.git/HEAD') === 404);
$up = file_get_contents(dirname(__DIR__) . '/uploads/.htaccess');
$ok('uploads/.htaccess keeps the PHP engine off and the handler removed (PATH_INFO cannot execute there)', str_contains($up, 'php_flag engine off') && str_contains($up, 'RemoveHandler'));
$files = ['plugins/x/a.php' => '<?php echo 1;', 'plugins/x/a.css' => 'a{}', 'uploads/evil.php' => 'x', 'uploads/a.png' => 'x', 'agent/index.php' => '<?php', 'sub/b.php' => '<?php'];
foreach (['deploy', 'docker'] as $v) {
    $r = throwaway_nginx($v, $files, function ($code) use (&$fail, $v) {
        $t = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
        foreach (['/plugins/x/a.php', '/plugins/x/a.php/foo', '/plugins/x/a.php/', '/uploads/evil.php', '/uploads/evil.php/foo'] as $p) { $c = $code($p); $t("$v: $p refused (got $c)", $c === 403 || $c === 404); }
        $t("$v: static plugin asset and upload still served", $code('/plugins/x/a.css') === 200 && $code('/uploads/a.png') === 200);
        $t("$v: the app still reaches PHP-FPM (502, no FPM behind)", $code('/agent/index.php') === 502);
    });
    if ($r === null) { break; }
}
$fail += $GLOBALS['nginx_fail'] ?? 0;
exit($fail ? 1 : 0);
