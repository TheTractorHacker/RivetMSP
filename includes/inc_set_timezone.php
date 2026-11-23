<?php

$result = mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1");
$row = mysqli_fetch_assoc($result);
$_SESSION['session_timezone'] = $row['config_timezone'] ?? 'UTC';

// MSP-DS-40: a stored zone name that PHP does not know must not take every page, login and cron down; fall back to UTC
try {
    new DateTimeZone((string) $_SESSION['session_timezone']);
} catch (\Throwable $e) {
    $_SESSION['session_timezone'] = 'UTC';
}

// Set PHP timezone
date_default_timezone_set($_SESSION['session_timezone']);

// Calculate UTC offset and store it in session
$session_datetime = new DateTime('now', new DateTimeZone($_SESSION['session_timezone']));
$_SESSION['session_utc_offset'] = $session_datetime->format('P');

// Use the stored timezone and offset
$session_timezone = $_SESSION['session_timezone'];
date_default_timezone_set($session_timezone);

// Set MySQL session time zone
mysqli_query($mysqli, "SET time_zone = '{$_SESSION['session_utc_offset']}'");
