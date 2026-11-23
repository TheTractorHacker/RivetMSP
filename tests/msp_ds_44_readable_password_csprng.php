<?php
// MSP-DS-44: generateReadablePassword uses random_int only (no array_rand/shuffle/rand) and has a >=6 digit suffix.
require __DIR__ . '/lib/ds_src.php';
$f = ds_src('functions.php');
$i = strpos($f, 'function generateReadablePassword'); $body = substr($f, $i, strpos($f, 'return $password;', $i) - $i);
ds_t('no array_rand', strpos($body, 'array_rand') === false);
ds_t('no shuffle()', !preg_match('/\bshuffle\(/', $body));
ds_t('no rand()', !preg_match('/(?<![_a-z])rand\(/', $body));
// run the function itself
eval(substr($f, $i, strpos($f, 'return $password;', $i) + strlen('return $password;') - $i) . "\n}");
$seen = [];
for ($n = 0; $n < 200; $n++) {
    $p = generateReadablePassword(1);
    $seen[$p] = true;
    if (!preg_match('/^[A-Za-z@!0-9$+]+[0-9]{6,}$/', $p)) { ds_t("shape of $p", false); }
}
ds_t('200 distinct passwords', count($seen) === 200);
ds_t('level 3 still works', strlen(generateReadablePassword(3)) > 10);
ds_done();
