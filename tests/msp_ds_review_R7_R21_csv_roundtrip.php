<?php
// Review R7 (credential export/import round trip) + R21 (empty fputcsv escape everywhere). Run: php tests/msp_ds_review_R7_R21_csv_roundtrip.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$root = dirname(__DIR__);
$fn = file_get_contents("$root/functions.php");
$a = strpos($fn, 'function csvSafeRow'); $b = strpos($fn, 'function generateReadablePassword');
eval(substr($fn, $a, $b - $a));

// --- R7: export exactly as agent/post/credential.php does it, import exactly as it reads it
$cred = file_get_contents("$root/agent/post/credential.php");
$ok(str_contains($cred, 'csvSafeRow($lineData, [2, 3, 4])'), 'credential export keeps username/password/TOTP columns raw');
$secrets = ['-Kx9#aP!', '+plus', '=eq', '@at', '|pipe', "'quoted", 'plain', 'a\\"b,=2+5', "\tTab", '123', '-5', '1e3', 'x"y', "multi\nline"];
$rows = [['Name', 'Description', 'Username', 'Password', 'TOTP', 'URI']];
foreach ($secrets as $i => $s) { $rows[] = ["cred$i", 'd', $s, $s, 'JBSWY3DP', 'https://x']; }
$h = fopen('php://memory', 'w+');
foreach ($rows as $k => $r) { fputcsv($h, $k === 0 ? $r : csvSafeRow($r, [2, 3, 4]), ',', '"', ''); }
rewind($h); fgetcsv($h, 1000, ',', '"', '');
$i = 0; $allOk = true;
while (($c = fgetcsv($h, 1000, ',', '"', '')) !== false) {
    $good = $c[2] === $secrets[$i] && $c[3] === $secrets[$i];
    $ok($good, 'round trip keeps ' . json_encode($secrets[$i]) . ($good ? '' : ' got ' . json_encode($c[3])));
    $i++;
}
// formula guard still applies to the non-secret columns of that export
$line = csvSafeRow(['=HYPERLINK("x")', '@x', 'u', 'p', 'JBSWY3DP', '-cmd|x'], [2, 3, 4]);
$ok($line[0] === "'=HYPERLINK(\"x\")" && $line[1] === "'@x" && $line[5] === "'-cmd|x", 'name/description/URI columns still formula-guarded');
$ok(csvSafeRow(['|cmd'])[0] === "'|cmd", "'|' is a trigger like report_send_csv()");
$ok(csvSafeRow([-5, '-5', null, 'ok'])[1] === '-5', 'numbers untouched');

// --- R21: break-out value with the escape every module used to pass
$val = 'x\\",=2+5,y';
$h = fopen('php://memory', 'w+'); fputcsv($h, csvSafeRow([$val, 'b']), ',', '"', ''); rewind($h);
$cells = fgetcsv($h, 1000, ',', '"', '');
$ok($cells === [$val, 'b'], 'empty escape keeps the break-out value in one cell');
$h = fopen('php://memory', 'w+'); fputcsv($h, csvSafeRow([$val, 'b']), ',', '"', '\\'); rewind($h);
$ok(count(fgetcsv($h, 1000, ',', '"', '')) !== 2, 'control: the backslash escape does split it (this is what the test guards against)');

// every fputcsv/fgetcsv call in app code passes an explicit empty escape (directly or via $escape set to '')
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || preg_match('#/(plugins|vendor|tests|node_modules|\.git|uploads|rivetcore|docker)/#', $p)) continue;
    $src = file_get_contents($p);
    if (!preg_match('/\b(fputcsv|fgetcsv)\(/', $src)) continue;
    foreach (explode("\n", $src) as $n => $l) {
        if (preg_match('#^\s*(\*|//|/\*)#', $l)) continue;
        if (preg_match('/\$escape\s*=\s*\'\\\\\\\\\'/', $l)) $bad[] = "$p:" . ($n + 1) . ' backslash escape';
        if (preg_match('/\b(fputcsv|fgetcsv)\(/', $l) && !preg_match('/(\$escape|\'\')\s*\)/', $l) && !str_contains($l, "'\"', ''")) $bad[] = "$p:" . ($n + 1) . ' ' . trim($l);
    }
}
$ok(!$bad, 'all fputcsv/fgetcsv calls use an empty escape' . ($bad ? ': ' . implode(' | ', array_slice($bad, 0, 8)) : ''));
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
