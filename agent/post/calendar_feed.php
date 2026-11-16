<?php

/*
 * RivetMSP - calendar feed token (MSP-DS-49): generate or replace the signed-in user's own feed link.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['regenerate_calendar_feed_token'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once dirname(__DIR__, 2) . '/includes/calendar_feed_token.php';

    // The plain token is shown once on the calendar page and is not stored anywhere readable.
    $_SESSION['calendar_feed_new_token'] = calendarFeedTokenRotate($mysqli, (int) $session_user_id);

    logAction("Calendar", "Edit", "$session_name generated a new calendar feed link");

    flash_alert("New calendar feed link generated. Any link you used before no longer works.");

    redirect();

}
