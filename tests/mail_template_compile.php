<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Email Templates: the browser's block compiler (js/mail_template_blocks.js) and the server's (includes/mail_templates.php) must produce
 * byte-identical output for every block type - the builder writes what the JS compiles, the server re-compiles on save and compiles
 * every default. Runs BOTH on the same block data (node for the JS, this php for the PHP) and compares byte for byte: compileOne per
 * block, compileHtml, normalize, serialize (blocks comment + HTML), decompile, the comment stripper and the save-time warnings.
 * DB-free, needs node (>= 18) on PATH:
 *   php tests/mail_template_compile.php
 */
$root = dirname(__DIR__);
require "$root/vendor/autoload.php";
require "$root/includes/mail_templates.php";

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') { fwrite(STDERR, "node not found on PATH: this test needs it to run js/mail_template_blocks.js\n"); exit(2); }

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };

/** Runs js/mail_template_blocks.js in node on $input (JSON) and returns its JSON answer. */
$js = function (string $script, $input) use ($node, $root) {
    $tmp = tempnam(sys_get_temp_dir(), 'mtc');
    file_put_contents($tmp, json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $code = 'const M=require(' . json_encode("$root/js/mail_template_blocks.js") . ');const I=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));'
        . 'process.stdout.write(JSON.stringify((function(){' . $script . '})()));';
    $out = shell_exec(escapeshellarg($node) . ' -e ' . escapeshellarg($code) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    $decoded = json_decode((string) $out, true);
    if ($decoded === null && trim((string) $out) !== 'null') { fwrite(STDERR, "node failed: $out\n"); }
    return $decoded;
};

// Every block type the builder offers, with ordinary and adversarial values, plus shapes only a hand-edited body could hold.
$hostile = "Hi {contact_name} <b>bold</b> --> <!-- c --> </script> é ü 😀 \u{2028} \u{2029} & &amp; \"dq\" 'sq' \\ / \t tab\nnewline";
$blocks = [
    ['type' => 'reply_marker'],
    ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>A ticket regarding "{subject}" has been created for you.'],
    ['type' => 'text', 'html' => $hostile],
    ['type' => 'text', 'html' => ''],
    ['type' => 'quote', 'html' => '{ticket_details}'],
    ['type' => 'quote', 'html' => $hostile],
    ['type' => 'button', 'label' => 'View ticket', 'link' => '{portal_link}'],
    ['type' => 'button', 'label' => '', 'link' => '{agent_link}'],
    ['type' => 'button', 'label' => 'Go <now> & "then"', 'link' => '" onmouseover="x'],
    ['type' => 'button'],
    ['type' => 'divider'],
    ['type' => 'divider', 'html' => 'ignored'],
    ['type' => 'spacer', 'size' => 8], ['type' => 'spacer', 'size' => '16'], ['type' => 'spacer', 'size' => '32px'], ['type' => 'spacer', 'size' => 999],
    ['type' => 'spacer', 'size' => -5], ['type' => 'spacer', 'size' => 4.9], ['type' => 'spacer', 'size' => 199.99], ['type' => 'spacer', 'size' => '  77'],
    ['type' => 'spacer', 'size' => 'abc'], ['type' => 'spacer', 'size' => ''], ['type' => 'spacer', 'size' => null], ['type' => 'spacer', 'size' => true],
    ['type' => 'spacer', 'size' => [12]], ['type' => 'spacer', 'size' => '1e3'], ['type' => 'spacer', 'size' => '-0'], ['type' => 'spacer'],
    ['type' => 'html', 'html' => '<!-- an HTML comment --><table><tr><td>{company_name}</td></tr></table>'],
    ['type' => 'html', 'html' => $hostile],
    ['type' => 'not_a_type', 'html' => 'falls back to html', 'label' => 'dropped'],
    ['type' => 5, 'html' => 7],
    ['html' => 'no type'],
    ['type' => 'text', 'html' => ['an', 'array']],
    ['type' => 'text', 'html' => 42],
    'a string block', null, 17, [], ['x', 'y'],
];

// 1. compileOne, block by block (for every block type and every value above)
$jsOne = $js('return I.map(M.compileOne);', $blocks);
$allSame = is_array($jsOne) && count($jsOne) === count($blocks);
foreach ($blocks as $i => $b) {
    $php = mailTemplateCompileBlock($b);
    $same = is_array($jsOne) && ($jsOne[$i] ?? null) === $php;
    $allSame = $allSame && $same;
    if (!$same) { $ok(false, "compileOne #$i " . json_encode($b) . ": php=" . json_encode($php) . " js=" . json_encode($jsOne[$i] ?? null)); }
}
$ok($allSame, 'compileOne: JS and PHP byte-identical for all ' . count($blocks) . ' blocks (every type: text, quote, button, divider, spacer, reply_marker, html, unknown, malformed)');
foreach (['text', 'quote', 'button', 'divider', 'spacer', 'reply_marker', 'html'] as $type) {
    $sample = array_values(array_filter($blocks, fn($b) => is_array($b) && ($b['type'] ?? null) === $type));
    $jsT = $js('return M.compileHtml(I);', $sample);
    $ok($jsT === mailTemplateCompileBlocks($sample) && $jsT !== '' , "$type blocks (" . count($sample) . "): compileHtml byte-identical");
}

// 2. compileHtml / normalize / serialize over the whole list
$ok($js('return M.compileHtml(I);', $blocks) === mailTemplateCompileBlocks($blocks), 'compileHtml of the whole list: byte-identical');
$ok($js('return I.map(M.normalize);', $blocks) === array_map('mailTemplateNormalizeBlock', $blocks), 'normalize: identical structures');
$jsSer = $js('return M.serialize(I);', $blocks);
$phpSer = mailTemplateSerializeBlocks($blocks);
$ok($jsSer === $phpSer, 'serialize (blocks comment + HTML): byte-identical (' . strlen($phpSer) . ' bytes)');
$ok(substr_count($phpSer, '-->') === 1 + substr_count(mailTemplateCompileBlocks($blocks), '-->'), 'the JSON in the comment never contains "-->" (<, >, & are \\u-escaped), even with "-->" in a block');

// 3. Every shipped default, compiled by both: the browser rebuilds exactly what the server seeded
foreach (mailTemplateDefaultBlocks() as $event => $eventBlocks) {
    $ok($js('return M.serialize(I);', $eventBlocks) === mailTemplateDefaults()[$event]['body'], "default \"$event\": JS serialize === mailTemplateDefaults() body");
}

// 4. decompile + round trips, both directions
$ok($js('return M.decompile(I);', $phpSer) === mailTemplateBlocksFromBody($phpSer), 'decompile of a serialized body: identical block lists');
$ok($js('return M.serialize(M.decompile(I));', $phpSer) === $phpSer && mailTemplateSerializeBlocks(mailTemplateBlocksFromBody($phpSer)) === $phpSer, 'serialize(decompile(x)) === x in both');
$ok(mailTemplateNormalizeBody($jsSer) === $jsSer, 'the server re-compile on save (mailTemplateNormalizeBody) leaves a browser-serialized body byte-for-byte unchanged');
$weird = [
    'legacy plain body', '<!--RIVETMSP_BLOCKS:not json-->tail', '<!--RIVETMSP_BLOCKS:{"v":1,"blocks":{"0":{"type":"text","html":"obj"}}}-->x',
    '<!--RIVETMSP_BLOCKS:{"v":1,"blocks":[]}-->', '<!--RIVETMSP_BLOCKS:{"v":1}-->x', '<!--RIVETMSP_BLOCKS:[1,2]-->x', '<!--RIVETMSP_BLOCKS:{"v":1,"blocks":[',
    ' <!--RIVETMSP_BLOCKS:{"v":1,"blocks":[]}-->leading space', '',
];
$jsWeird = $js('return I.map(M.decompile);', $weird);
foreach ($weird as $i => $w) {
    $ok(($jsWeird[$i] ?? null) === mailTemplateBlocksFromBody($w), 'decompile of a hand-edited/broken body #' . $i . ': identical (' . json_encode(mailTemplateBlocksFromBody($w)) . ')');
}

// 5. The comment stripper (what keeps builder state out of real email)
$strip = [$phpSer, 'a<!--RIVETMSP_BLOCKS:{}-->b<!--RIVETMSP_BLOCKS:{}-->c', 'x<!--RIVETMSP_BLOCKS:unterminated', 'no comment', '<!--RIVETMSP_BLOCKS:-->'];
$ok($js('return I.map(M.stripComment);', $strip) === array_map('mailTemplateStripBlocksComment', $strip), 'stripComment: identical on comment-first, repeated, unterminated and plain bodies');
foreach (array_map('mailTemplateStripBlocksComment', $strip) as $i => $s) { $ok(!str_contains($s, 'RIVETMSP_BLOCKS'), "stripped body #$i carries no builder comment"); }
$ok(mailTemplateStripBlocksComment($phpSer) === mailTemplateCompileBlocks($blocks), 'stripping the comment leaves exactly the compiled HTML');

// 6. Save-time warnings agree (the page warns before Save, the handler after)
$cases = [];
foreach (mailTemplateDefaults() as $event => $tpl) {
    $cases[] = [$event, $tpl['body']];
    $cases[] = [$event, mailTemplateSerializeBlocks(array_values(array_filter(mailTemplateDefaultBlocks()[$event], fn($b) => $b['type'] !== 'reply_marker')))];
    $cases[] = [$event, mailTemplateSerializeBlocks(array_merge([['type' => 'text', 'html' => 'first']], mailTemplateDefaultBlocks()[$event]))];
    $cases[] = [$event, 'no link, no marker'];
}
$jsW = $js('return I.map(function(c){return M.warnings(c[1], c[2], c[3]);});', array_map(fn($c) => [$c[0], $c[1], mailTemplateRequiresReplyMarker($c[0]), mailTemplateRequiresPortalLink($c[0])], $cases));
$same = true;
foreach ($cases as $i => [$event, $body]) { $same = $same && ($jsW[$i] ?? null) === mailTemplateSaveWarnings($event, $body); }
$ok($same, 'save-time warnings: JS and PHP agree on all ' . count($cases) . ' cases (marker missing / not first, portal link missing)');

echo "\n$passes passed, $fails failed\n";
exit($fails === 0 ? 0 : 1);
