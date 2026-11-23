<?php

// Check if the app is set up
if (file_exists("config.php")) {
    require_once "config.php";

    // Check if setup is enabled (not completed)
    if (!isset($config_enable_setup) || $config_enable_setup == 1) {
        header("Location: /setup");
        exit();
    }

    // Start the session
    require_once "functions.php";
    require_once "includes/session_init.php";

    // If user is a client. MSP-DS-110: checked first, because a portal login also sets $_SESSION['logged'] (login.php) and would otherwise be bounced into
    // /agent/ (which registers a session row for the client user and then logs them out).
    if (isset($_SESSION['client_logged_in'])) {
        header("Location: /client/");
        exit();

    // If user is an agent
    } elseif (isset($_SESSION['logged'])) {
        require_once "includes/load_global_settings.php";
        header("Location: /agent/$config_start_page");
        exit();

    // Not logged in
    } else {
        header("Location: /login.php");
        exit();
    }

} else {
    // If config.php doesn't exist, redirect to setup
    header("Location: /setup");
    exit();
}
