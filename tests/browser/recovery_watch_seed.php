<?php
// Seeds integrations and sync runs for tests/browser/recovery_watch_smoke.mjs into the scratch database named by config.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../config.php';
if (!preg_match('/scratch|test/i', (string) $database)) { fwrite(STDERR, "Refusing: not a scratch database\n"); exit(2); }
$q = fn (string $s) => mysqli_query($mysqli, $s) or die(mysqli_error($mysqli) . "\n$s\n");
foreach (['rmm_sync_log', 'unifi_sync_log', 'rmm_integrations', 'unifi_integrations', 'recovery_watch_overrides', 'recovery_alerts'] as $t) { $q("DELETE FROM `$t`"); }
$q("UPDATE settings SET config_module_enable_rmm = 1 WHERE company_id = 1");
$int = fn (int $id, string $name, string $type) => $q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES ($id, '$name', '$type', 'https://x', 'x', 1, NOW() - INTERVAL 400 DAY)");
$run = fn (int $id, string $st, string $ago, ?string $err = null) => $q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, errors, triggered_by) VALUES ($id, NOW() - INTERVAL $ago, NOW() - INTERVAL $ago, '$st', " . ($err === null ? 'NULL' : "'$err'") . ", 0)");
$int(1, 'SW Healthy', 'tactical_rmm'); $run(1, 'success', '2 MINUTE');
$int(2, 'SW Failing', 'level'); foreach (['3 MINUTE', '2 MINUTE', '1 MINUTE'] as $a) { $run(2, 'failed', $a, 'HTTP 502'); }
$int(3, 'SW Stale', 'action1'); $run(3, 'success', '5 HOUR');
$int(4, 'SW Dormant', 'sophos_central'); $run(4, 'success', '94 DAY');
$int(5, 'SW Muted', 'tactical_rmm'); $q("INSERT INTO recovery_watch_overrides (override_source, override_integration_id, override_muted, override_note) VALUES ('rmm', 5, 1, 'retired')");
$int(6, 'SW Daily', 'tactical_rmm'); $run(6, 'success', '20 HOUR'); $q("INSERT INTO recovery_watch_overrides (override_source, override_integration_id, override_expected_interval_s, override_note) VALUES ('rmm', 6, 86400, 'daily script')");
$int(7, 'SW Agent', 'rivetit_agent'); $run(7, 'failed', '1 MINUTE', 'Endpoint agent devices are managed from the device page, not through an RMM client.');
echo "seeded\n";
