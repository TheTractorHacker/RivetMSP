<?php
// MSP-DS-101: sanitize_url() judges the scheme the way a browser parses it. Run: php tests/msp_ds_101_sanitize_url.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$f = file_get_contents(dirname(__DIR__) . '/functions.php');
preg_match('/function sanitize_url\(\$url\) \{.*?\n\}\n/s', $f, $m);
eval($m[0]);
$bad = ["javascript:alert(1)", "java\tscript:alert(1)", "\x01javascript:alert(1)", "  javascript:alert(1)", "javascript:///:%0aalert(1)", "JaVaScRiPt:alert(1)", "java\nscript:alert(1)", "data:text/html,<script>1</script>", "vbscript:x", "\x00javascript:alert(1)"];
foreach ($bad as $u) {
    $r = sanitize_url($u);
    $ok(!preg_match('/^\s*java\s*script:/i', $r) && str_starts_with($r, 'unsupported://'), 'neutralised: ' . json_encode($u) . ' -> ' . $r);
}
$good = ['https://example.com/a?b=1&c=2' => 'https://example.com/a?b=1&amp;c=2', 'rdp://10.0.0.5' => 'rdp://10.0.0.5', 'file://server/share/My Docs' => 'file://server/share/My Docs', 'ssh://u@h:22' => 'ssh://u@h:22', '/relative/path' => '/relative/path', 'example.com' => 'example.com', '' => ''];
foreach ($good as $u => $want) { $ok(sanitize_url($u) === $want, "kept: $u"); }
$ok(sanitize_url(null) === '', 'null is empty');
$ok(sanitize_url('https://x.com/"><script>') === 'https://x.com/&quot;&gt;&lt;script&gt;', 'still HTML-escaped');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
