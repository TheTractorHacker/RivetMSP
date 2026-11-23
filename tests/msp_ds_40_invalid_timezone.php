<?php
// MSP-DS-40: a bad timezone in settings must not crash inc_set_timezone.php; the settings handler allow-lists it.
require __DIR__ . '/lib/ds_src.php';
$tz = ['Not/AZone', '', 'America/Chicago', null];
foreach ($tz as $val) {
    $mysqli = new class($val) { public $v; function __construct($v) { $this->v = $v; } };
    // run the include against a stub DB layer
    $code = file_get_contents(__DIR__ . '/../includes/inc_set_timezone.php');
    $code = preg_replace('/^<\?php/', '', $code);
    $code = str_replace(['mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1")', 'mysqli_fetch_assoc($result)', 'mysqli_query($mysqli, "SET time_zone'], ['1', '["config_timezone" => $mysqli->v]', 'strlen("SET time_zone'], $code);
    $_SESSION = [];
    try { eval($code); ds_t('survives timezone ' . var_export($val, true) . ' -> ' . $_SESSION['session_timezone'], in_array($_SESSION['session_timezone'], DateTimeZone::listIdentifiers(), true)); }
    catch (\Throwable $e) { ds_t('survives ' . var_export($val, true) . ': ' . $e->getMessage(), false); }
}
$h = ds_src('admin/post/settings_localization.php');
ds_t('handler allow-lists identifier', strpos($h, 'in_array($timezone, DateTimeZone::listIdentifiers(), true)') !== false && strpos($h, 'listIdentifiers') < strpos($h, 'UPDATE settings SET config_timezone'));
ds_done();
