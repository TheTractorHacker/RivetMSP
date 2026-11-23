<?php
// MSP-DS-173 (re-review M3-20): docs/AUDIT_CHAIN.md is referenced by the page and the code but was not in the tree. It must exist and cover the agreed topics.
// Run: php tests/msp_m3_20.php
$doc = dirname(__DIR__) . '/docs/AUDIT_CHAIN.md';
$t = is_file($doc) ? (string) file_get_contents($doc) : '';
$fail = 0;
$ok = function (string $n, bool $c) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $n . "\n"; $fail += $c ? 0 : 1; };
$ok('docs/AUDIT_CHAIN.md exists', $t !== '');
foreach (['Modes', 'The anchor', 'Retention', 'Re-anchor', 'One-time legacy step', 'Restore procedure', 'audit_chain_head.json', 'hmac_from', 'genesis'] as $topic) {
    $ok("covers: $topic", stripos($t, $topic) !== false);
}
exit($fail ? 1 : 0);
