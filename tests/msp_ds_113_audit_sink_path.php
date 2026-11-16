<?php
// MSP-DS-113: audit copy path cannot name an executable file or carry markup; lines never contain a literal "<?". Run: php tests/msp_ds_113_audit_sink_path.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
require __DIR__ . '/../src/Audit/AuditSink.php';
use RivetMSP\Audit\AuditSink;
$base = sys_get_temp_dir() . '/ds113_' . getmypid();
mkdir($base);
$app = $base . '/app'; mkdir($app);
$out = $base . '/out'; mkdir($out);
$ok(AuditSink::pathProblem($out . '/audit.jsonl', $app) === null, 'normal .jsonl path allowed');
$ok(AuditSink::pathProblem($out . '/audit.log', $app) === null, '.log allowed');
$ok(AuditSink::pathProblem($out . '/a<?php system($_GET[c]);?>.php', $app) !== null, 'PHP-tag filename refused');
$ok(AuditSink::pathProblem($out . '/shell.php', $app) !== null, '.php refused');
$ok(AuditSink::pathProblem($out . '/audit', $app) !== null, 'no extension refused');
$ok(AuditSink::pathProblem($out . '/a b.log', $app) !== null, 'space refused');
$j = json_encode(['summary' => 'x <?php system($_GET[c]);?> y'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$ok(strpos($j, '<?') === false, 'encoded line has no literal PHP open tag');
$src = file_get_contents(__DIR__ . '/../src/Audit/AuditSink.php');
$ok(strpos($src, 'JSON_HEX_TAG') !== false, 'AuditSink::write encodes with JSON_HEX_TAG');
rmdir($app); rmdir($out); rmdir($base);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
