<?php

//Alert Feedback
if (!empty($_SESSION['alert_message'])) {
    if (!isset($_SESSION['alert_type'])) {
        $_SESSION['alert_type'] = "success";
    }
    ?>

    <script type="text/javascript" nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES); ?>">

        /* Options are set once, in includes/header.php / guest/includes/guest_header.php,
           so every toast path shares them - including the AJAX ones, which never
           reached this file. */

        /* flash_alert() callers pass 'danger' (Bootstrap word); toastr's method for that is 'error' */
        toastr[<?php echo json_encode(['danger' => 'error', 'info' => 'info', 'warning' => 'warning', 'error' => 'error', 'success' => 'success'][$_SESSION['alert_type']] ?? 'info'); ?>](<?php echo json_encode($_SESSION['alert_message']); ?>)

    </script>

    <?php

    unset($_SESSION['alert_type']);
    unset($_SESSION['alert_message']);

}

?>
