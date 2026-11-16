<?php
// MSP-DS-96: the Docker PHP overrides turn display_errors and expose_php off. Run: php tests/msp_ds_96_docker_php_ini.php
$pass = $fail = 0;
$ok = function (bool $c, string $l) use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } };
$ini = parse_ini_file(dirname(__DIR__) . '/docker/php-overrides.ini', false, INI_SCANNER_TYPED);
$ok(($ini['display_errors'] ?? null) === false, 'display_errors is off');
$ok(($ini['display_startup_errors'] ?? null) === false, 'display_startup_errors is off');
$ok(($ini['expose_php'] ?? null) === false, 'expose_php is off');
$ok(($ini['log_errors'] ?? null) === true, 'errors still go to the log');
$ok(($ini['upload_max_filesize'] ?? '') === '500M' && ($ini['opcache.validate_timestamps'] ?? null) == 1, 'existing overrides unchanged');
$d = file_get_contents(dirname(__DIR__) . '/docker/Dockerfile');
$ok(str_contains($d, 'COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-itflow-overrides.ini'), 'the Dockerfile installs this file');
echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0);
