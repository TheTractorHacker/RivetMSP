<?php
// MSP-DS-93: the ticket prefix is preg_quote()d where the email parser matches [PREFIX123]. Run: php tests/msp_ds_93_ticket_prefix_regex.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$s = file_get_contents(dirname(__DIR__) . '/cron/ticket_email_parser.php');
$ok(str_contains($s, "\$config_ticket_prefix_re = preg_quote((string) \$config_ticket_prefix, '/');"), 'prefix is quoted from the raw value');
$ok(substr_count($s, '\[$config_ticket_prefix_re(\d+)\]') === 2 && !str_contains($s, '\[$config_ticket_prefix(\d+)\]'), 'both match sites use the quoted prefix');
foreach (['TCK-' => ['[TCK-42]', 42], 'IT/' => ['Re: [IT/42] printer', 42], 'SR(' => ['[SR(7]', 7], 'T[' => ['[T[9]', 9], 'T.' => ['[TX42]', null], 'TKT+' => ['[TKTT42]', null], "O'B" => ["[O'B5]", 5]] as $prefix => [$subject, $want]) {
    $re = preg_quote($prefix, '/');
    $r = @preg_match("/\[$re(\d+)\]/", $subject, $m);
    $got = $r === 1 ? (int) $m[1] : null;
    $ok($got === $want, "prefix '$prefix' on '$subject' -> " . var_export($got, true));
}
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
