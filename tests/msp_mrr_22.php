<?php
// MSP-DS-182 re-review MRR-22: the any-depth .yaml deny must not block /api/v1/openapi.yaml (admin/api_docs.php and api/v1/docs.php link to it),
// while every other denied-extension file stays refused. Runs the REAL deploy template and docker/nginx.conf in a throwaway nginx.
// Run: php tests/msp_mrr_22.php   (needs the nginx binary; the live part is skipped with a note when it is missing)
require __DIR__ . '/lib/throwaway_nginx.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$files = ['api/v1/openapi.yaml' => 'openapi: 3.0.0', 'api/v1/other.yaml' => 'x', 'api/v1/index.php' => '<?php', 'sub/x.yaml' => 'x', 'sub/readme.md' => 'x', 'sub/d.sql' => 'x'];
$ok('the app still links /api/v1/openapi.yaml', str_contains(file_get_contents(dirname(__DIR__) . '/admin/api_docs.php'), '/api/v1/openapi.yaml'));
$ok('the spec file exists in the repo', is_file(dirname(__DIR__) . '/api/v1/openapi.yaml'));
foreach (['deploy', 'docker'] as $v) {
    $r = throwaway_nginx($v, $files, function ($code) use (&$fail, $v) {
        $t = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
        $t("$v: /api/v1/openapi.yaml is served", $code('/api/v1/openapi.yaml') === 200);
        foreach (['/api/v1/other.yaml', '/sub/x.yaml', '/sub/readme.md', '/sub/d.sql'] as $p) { $c = $code($p); $t("$v: $p stays refused (got $c)", $c === 403 || $c === 404); }
    });
    if ($r === null) { break; }
}
$fail += $GLOBALS['nginx_fail'] ?? 0;
exit($fail ? 1 : 0);
