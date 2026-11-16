<?php
// Review R5: IntuneAssetMapper must be able to load its PlainText helper through the REAL composer autoloader.
// Run: php tests/msp_ds_review_R5_plaintext_autoload.php   (no DB needed)
$fail = 0; $ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
require __DIR__ . '/../vendor/autoload.php';
$ok('RivetMSP\\Security\\PlainText autoloads', class_exists('RivetMSP\\Security\\PlainText'));
$ok('no stale ITFlow\\Security namespace is referenced from src/', trim((string) shell_exec('grep -rl "ITFlow.Security" ' . escapeshellarg(__DIR__ . '/../src'))) === '');
$ok('PlainText strips tags', \RivetMSP\Security\PlainText::clean('<img src=x onerror=1>Bob') === 'Bob');
// Run the mapper's cleaning path: a name that is only markup cleans to '' and is skipped BEFORE any DB use,
// so an unconnected mysqli is enough; a missing class would throw Error here (and abort the whole sync in production).
$m = new mysqli();
$mapper = new \RivetMSP\Integrations\Microsoft\IntuneAssetMapper($m, 1, 5);
try {
    $r = $mapper->syncDevices([['id' => 'dev-1', 'deviceName' => '<script></script><b></b>']]);
    $ok('mapper runs PlainText::clean and skips a markup-only device name', $r['skipped'] === 1 && $r['created'] === 0 && $r['errors'] === []);
} catch (\Throwable $e) {
    $ok('mapper ran without Error: ' . $e->getMessage(), false);
}
exit($fail ? 1 : 0);
