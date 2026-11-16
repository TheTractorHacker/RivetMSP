<?php
// MSP-DS-48: credential_uri hrefs go through sanitize_url(), and sanitize_url() cannot be fooled by whitespace/control chars.
require __DIR__ . '/lib/ds_src.php';
$f = ds_src('functions.php');
$i = strpos($f, 'function sanitize_url'); $j = strpos($f, "\n}\n", $i) + 2;
eval(substr($f, $i, $j - $i));
foreach (["javascript:alert(1)", " javascript:alert(1)", "java\tscript:alert(1)", "\x01javascript:alert(1)", "JaVaScRiPt:alert(1)", "data:text/html,x", "vbscript:x"] as $bad) {
    ds_t('neutralised: ' . json_encode($bad), strpos(sanitize_url($bad), 'unsupported://') === 0);
}
foreach (["https://example.com/a?b=1&c=2" => "https://example.com/a?b=1&amp;c=2", "rdp://host:3389" => "rdp://host:3389", "file://srv/My Docs/a.txt" => "file://srv/My Docs/a.txt", "/relative/path" => "/relative/path", "" => ""] as $good => $exp) {
    ds_t('kept: ' . $good, sanitize_url($good) === $exp);
}
ds_t('null ok', sanitize_url(null) === '');
ds_t('unparseable URL treated as unsafe', strpos(sanitize_url('http:///x'), 'unsupported://') === 0);
foreach (['agent/asset_details.php', 'agent/contact_details.php'] as $p) {
    ds_t("$p link uses sanitize_url", strpos(ds_src($p), "<a href='\" . sanitize_url(\$row['credential_uri']) . \"' target='_blank'>") !== false);
}
ds_done();
