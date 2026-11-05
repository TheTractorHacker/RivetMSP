<?php

/*
 * ITFlow - User GET/POST request handler
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

// Define a variable that we can use to only allow running post files via inclusion (prevents people/bots poking them)
define('FROM_POST_HANDLER', true);

// A database rejection of the submitted values (value too long, bad format, missing required value) used to end as a blank HTTP 500
// page. Log it and send the user back with a readable message instead. Other exceptions keep the default behaviour.
set_exception_handler(function (Throwable $e) {
    if ($e instanceof mysqli_sql_exception && in_array((int) $e->getCode(), [1048, 1264, 1265, 1292, 1366, 1406], true)) {
        error_log('post.php rejected input: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        flash_alert('Could not save: one of the values is too long or not in the expected format. Please shorten or correct it and try again.', 'error');
        redirect();
    }
    throw $e;
});


// Determine which files we should load

// Parse URL & get the path
$path = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH);

// Get the base name (the page name)
$module = explode(".", basename($path))[0];

// Strip off any _details bits
$module = str_ireplace('_details', '', $module);

// Dynamically load admin-related module POST logic

// Load all module POST logic
//  Loads everything in post
//  Eventually, it would be nice to only specifically load what we need like we do for admins

foreach (glob("post/*.php") as $user_module) {
    if (!preg_match('/_model\.php$/', basename($user_module))) {
        require_once $user_module;
    }
}


// Logout is the same for user and admin
require_once "../post/logout.php";

// TODO: Find a home for these
require_once "../post/misc.php";
