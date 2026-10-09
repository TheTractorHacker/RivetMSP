<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * DB-free: the RivetCore rc.7 migration step (2.6.78) exists, is class-gated, is the highest step, and db.sql matches.
 *   php tests/core_rc7_migration.php
 */
$root = dirname(__DIR__);
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$upd = file_get_contents($root . '/admin/database_updates.php');
$ver = file_get_contents($root . '/includes/database_version.php');
preg_match('/LATEST_DATABASE_VERSION"\s*,\s*"([0-9.]+)"/', $ver, $m);
$latest = $m[1] ?? '';
$ok(version_compare($latest, '2.6.78', '>='), "latest version $latest >= 2.6.78");

$pos = strpos($upd, "== '2.6.77') {");
$ok($pos !== false, "a step gated on 2.6.77 exists");
$block = $pos === false ? '' : substr($upd, $pos, 1400);
$ok(strpos($block, 'class_exists(\\RivetCore\\Mcp\\Migration\\Migration0017McpIdentityBinaryCollation::class)') !== false, 'step is gated on the 0017 class');
foreach (['MigrationRunner', 'MysqliDatabaseAdapter', 'CoreMigrations::all()', 'SystemClock'] as $needle) {
    $ok(strpos($block, $needle) !== false, "step uses $needle");
}
$ok(preg_match('/if \(class_exists\(.*Migration0017.*\) \{.*->run\(\);\s*mysqli_query\(\$mysqli, "UPDATE `settings` SET `config_current_database_version` = \'2\.6\.78\'"\);\s*\}/s', $block) === 1, 'version advances only inside the class guard');

// highest: every "SET config_current_database_version = 'x'" target is <= 2.6.78 or the latest
preg_match_all("/config_current_database_version` = '([0-9.]+)'/", $upd, $all);
$max = array_reduce($all[1], fn($c, $v) => $c === null || version_compare($v, $c, '>') ? $v : $c);
$ok($max === $latest, "highest step target ($max) equals LATEST_DATABASE_VERSION ($latest)");

$sql = file_get_contents($root . '/db.sql');
$ok(preg_match('/CREATE TABLE `mcp_unlinked_identities` \(.*?`issuer` varchar\(255\) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,\s*`subject` varchar\(255\) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL/s', $sql) === 1, 'db.sql: issuer/subject are utf8mb4_bin');
$ok(strpos($sql, "('0017_mcp_identity_binary_collation',") !== false, 'db.sql records migration 0017');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
