<?php
/*
 * Client Portal
 * Includes for all pages (except login)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/functions.php';
// The portal header prints the whole page chrome, but many pages still do their access check afterwards and redirect
// with header("Location: ..."): without a buffer that raised "headers already sent" and left a blank page.
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/header.php';
