<?php
// MSP-DS-182 re-review MRR-24: the any-depth md/lock/sql/yml/sh/bak/dist deny must also cover the ^~ prefix locations that would otherwise shadow it
// (/plugins/, /fonts/, /css/, /js/). Structural check: every ^~ prefix location in both files is a plain deny, nests its own extension deny, or is
// /uploads/ (user content, served on purpose; its own script deny is nested). Then the real deploy template and docker/nginx.conf in a throwaway nginx.
// Run: php tests/msp_mrr_24.php   (the nginx part is skipped with a note when the binary is missing)
require __DIR__ . '/lib/throwaway_nginx.php';
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
foreach (['deploy/templates/itflow-locations.conf', 'docker/nginx.conf'] as $f) {
    $c = file_get_contents(dirname(__DIR__) . '/' . $f);
    $seen = [];
    foreach (preg_split('/(?=location \^~ )/', $c) as $chunk) {
        if (!preg_match('/^location \^~ (\S+) \{/', $chunk, $p)) { continue; }
        $seen[] = $p[1];
        $covered = preg_match('/^location \^~ \S+ \{\s*deny all;/', $chunk) || str_contains(substr($chunk, 0, 900), '(md|lock|sql|yml|yaml|sh|bak|dist)$') || $p[1] === '/uploads/';
        $ok("$f: prefix {$p[1]} is covered by the extension deny", (bool) $covered);
    }
    $ok("$f: the served prefixes are all checked", count(array_intersect($seen, ['/plugins/', '/fonts/', '/css/', '/js/', '/uploads/'])) === 5);
}
$files = ['plugins/x/composer.lock' => 'x', 'plugins/x/README.md' => 'x', 'plugins/x/dump.sql' => 'x', 'plugins/x/run.sh' => 'x', 'plugins/x/a.bak' => 'x', 'plugins/x/a.css' => 'a{}',
          'plugins/x/UP.MD' => 'x', 'js/notes.md' => 'x', 'css/a.yml' => 'x', 'css/a.css' => 'a{}', 'fonts/a.dist' => 'x', 'fonts/a.txt' => 'x', 'js/a.js' => 'x', 'sub/readme.md' => 'x'];
foreach (['deploy', 'docker'] as $v) {
    $r = throwaway_nginx($v, $files, function ($code) use (&$fail, $v) {
        $t = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
        foreach (['/plugins/x/composer.lock', '/plugins/x/README.md', '/plugins/x/dump.sql', '/plugins/x/run.sh', '/plugins/x/a.bak', '/plugins/x/UP.MD', '/js/notes.md', '/css/a.yml', '/fonts/a.dist', '/sub/readme.md'] as $p) { $c = $code($p); $t("$v: $p refused (got $c)", $c === 403 || $c === 404); }
        $t("$v: normal static assets still served", $code('/plugins/x/a.css') === 200 && $code('/css/a.css') === 200 && $code('/fonts/a.txt') === 200 && $code('/js/a.js') === 200);
    });
    if ($r === null) { break; }
}
$fail += $GLOBALS['nginx_fail'] ?? 0;
exit($fail ? 1 : 0);
