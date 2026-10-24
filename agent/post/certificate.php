<?php

/*
 * ITFlow - GET/POST request handler for client SSL certificates
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_certificate'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    require_once 'certificate_model.php';

    $client_id = intval($_POST['client_id']);

    enforceClientAccess();

    // Parse public key data for a manually provided public key
    if (!empty($public_key) && (empty($expire) && empty($issued_by))) {
        // Parse the public certificate key. If successful, set attributes from the certificate
        $public_key_obj = openssl_x509_parse($_POST['public_key']);
        if ($public_key_obj) {
            $expire = date('Y-m-d', $public_key_obj['validTo_time_t']);
            $issued_by = cleanInput($public_key_obj['issuer']['O']);
        }
    }

    if (empty($expire)) {
        $expire = null;
    }

    $stmt = mysqli_prepare($mysqli, "INSERT INTO certificates SET certificate_name = ?, certificate_description = ?, certificate_domain = ?, certificate_issued_by = ?, certificate_expire = ?, certificate_public_key = ?, certificate_notes = ?, certificate_domain_id = ?, certificate_client_id = ?");
    mysqli_stmt_bind_param($stmt, 'sssssssii', $name, $description, $domain, $issued_by, $expire, $public_key, $notes, $domain_id, $client_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $certificate_id = mysqli_insert_id($mysqli);

    logAction("Certificate", "Create", "$session_name created certificate $name", $client_id, $certificate_id);

    flash_alert("Certificate <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_certificate'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    require_once 'certificate_model.php';

    $certificate_id = intval($_POST['certificate_id']);

    $client_id = intval(getFieldById('certificates', $certificate_id, 'certificate_client_id'));

    enforceClientAccess();

    // Parse public key data for a manually provided public key
    if (!empty($public_key) && (empty($expire) && empty($issued_by))) {
        // Parse the public certificate key. If successful, set attributes from the certificate
        $public_key_obj = openssl_x509_parse($_POST['public_key']);
        if ($public_key_obj) {
            $expire = date('Y-m-d', $public_key_obj['validTo_time_t']);
            $issued_by = cleanInput($public_key_obj['issuer']['O']);
        }
    }

    if (empty($expire)) {
        $expire = null;
    }

    // Current certificate info (compared with the updated row below for the change history)
    $certificate_info_sql = "
        SELECT
            certificates.*,
            domains.domain_name
        FROM certificates
        LEFT JOIN domains ON certificate_domain_id = domain_id
        WHERE certificate_id = ?
    ";
    $stmt = mysqli_prepare($mysqli, $certificate_info_sql);
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    $original_certificate_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    // Update certificate
    $stmt = mysqli_prepare($mysqli, "UPDATE certificates SET certificate_name = ?, certificate_description = ?, certificate_domain = ?, certificate_issued_by = ?, certificate_expire = ?, certificate_public_key = ?, certificate_notes = ?, certificate_domain_id = ? WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'sssssssii', $name, $description, $domain, $issued_by, $expire, $public_key, $notes, $domain_id, $certificate_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Fetch the updated info
    $stmt = mysqli_prepare($mysqli, $certificate_info_sql);
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    $new_certificate_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    // Compare/log changes between old/new info
    $ignored_columns = ["certificate_public_key", "certificate_updated_at", "certificate_accessed_at", "certificate_domain_id"];
    foreach ($original_certificate_info as $column => $old_value) {
        $new_value = $new_certificate_info[$column];
        if ($old_value != $new_value && !in_array($column, $ignored_columns)) {
            // Old/new values are stored text read back from the database: bind them, never interpolate them.
            $column = cleanInput($column);
            $old_value = cleanInput($old_value);
            $new_value = cleanInput($new_value);
            $stmt = mysqli_prepare($mysqli, "INSERT INTO certificate_history SET certificate_history_column = ?, certificate_history_old_value = ?, certificate_history_new_value = ?, certificate_history_certificate_id = ?");
            mysqli_stmt_bind_param($stmt, 'sssi', $column, $old_value, $new_value, $certificate_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    logAction("Certificate", "Edit", "$session_name edited certificate $name", $client_id, $certificate_id);

    flash_alert("Certificate <strong>$name</strong> updated");

    redirect();

}

if (isset($_GET['archive_certificate'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $certificate_id = intval($_GET['archive_certificate']);

    // Get Certificate Name and Client ID for logging and alert message
    $stmt = mysqli_prepare($mysqli, "SELECT certificate_name, certificate_client_id FROM certificates WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $certificate_name = sanitizeInput($row['certificate_name']);
    $client_id = intval($row['certificate_client_id']);

    enforceClientAccess();

    $stmt = mysqli_prepare($mysqli, "UPDATE certificates SET certificate_archived_at = NOW() WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction("Certificate", "Archive", "$session_name archived certificate $certificate_name", $client_id, $certificate_id);

    flash_alert("Certificate <strong>$certificate_name</strong> archived", 'alert');

    redirect();

}

if (isset($_GET['restore_certificate'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $certificate_id = intval($_GET['restore_certificate']);

    // Get Certificate Name and Client ID for logging and alert message
    $stmt = mysqli_prepare($mysqli, "SELECT certificate_name, certificate_client_id FROM certificates WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $certificate_name = sanitizeInput($row['certificate_name']);
    $client_id = intval($row['certificate_client_id']);

    enforceClientAccess();

    $stmt = mysqli_prepare($mysqli, "UPDATE certificates SET certificate_archived_at = NULL WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction("Certificate", "Restore", "$session_name restored certificate $certificate_name", $client_id, $certificate_id);

    flash_alert("Certificate <strong>$certificate_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_certificate'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 3);

    $certificate_id = intval($_GET['delete_certificate']);

    // Get Certificate Name and Client ID for logging and alert message
    $stmt = mysqli_prepare($mysqli, "SELECT certificate_name, certificate_client_id FROM certificates WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    $certificate_name = sanitizeInput($row['certificate_name']);
    $client_id = intval($row['certificate_client_id']);

    enforceClientAccess();

    $stmt = mysqli_prepare($mysqli, "DELETE FROM certificates WHERE certificate_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction("Certificate", "Delete", "$session_name deleted certificate $name", $client_id);

    flash_alert("Certificate <strong>$certificate_name</strong> deleted");

    redirect();

}

if (isset($_POST['bulk_delete_certificates'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 3);

    if (isset($_POST['certificate_ids'])) {

        // Get selected count
        $count = count($_POST['certificate_ids']);

        // Cycle through array and delete each certificate
        foreach ($_POST['certificate_ids'] as $certificate_id) {

            $certificate_id = intval($certificate_id);

            // Get Certificate Name and Client ID for logging and alert message
            $stmt = mysqli_prepare($mysqli, "SELECT certificate_name, certificate_client_id FROM certificates WHERE certificate_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $certificate_id);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $certificate_name = sanitizeInput($row['certificate_name']);
            $client_id = intval($row['certificate_client_id']);

            enforceClientAccess();

            $stmt = mysqli_prepare($mysqli, "DELETE FROM certificates WHERE certificate_id = ? AND certificate_client_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $certificate_id, $client_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            logAction("Certificate", "Delete", "$session_name deleted certificate $certificate_name", $client_id);

        }

        logAction("Certificate", "Bulk Delete", "$session_name deleted $count certificates", $client_id);

        flash_alert("Deleted <strong>$count</strong> certificate(s)", 'error');

    }

    redirect();

}

if (isset($_POST['export_certificates_csv'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support');

    if ($_POST['client_id']) {
        $client_id = intval($_POST['client_id']);
        $client_query = "AND certificate_client_id = $client_id";
        $client_name = getFieldById('clients', $client_id, 'client_name');
        $file_name_prepend = "$client_name-";
        enforceClientAccess();
    } else {
        $client_query = '';
        $client_id = 0;
        $file_name_prepend = "$session_company_name-";
    }

    $sql = mysqli_query($mysqli,"SELECT * FROM certificates LEFT JOIN clients ON client_id = certificate_client_id WHERE certificate_archived_at IS NULL $client_query $access_permission_query ORDER BY certificate_name ASC");

    $num_rows = mysqli_num_rows($sql);

    if ($num_rows > 0) {
        $delimiter = ",";
        $enclosure = '"';
        $escape    = '\\';   // backslash
        $filename = sanitize_filename($file_name_prepend . "Certificates-" . date('Y-m-d_H-i-s') . ".csv");

        //create a file pointer
        $f = fopen('php://memory', 'w');

        //set column headers
        $fields = array('Name', 'Description', 'Domain', 'Issuer', 'Expiration Date');
        fputcsv($f, $fields, $delimiter, $enclosure, $escape);

        //output each row of the data, format line as csv and write to file pointer
        while($row = $sql->fetch_assoc()) {
            $lineData = array($row['certificate_name'], $row['certificate_description'], $row['certificate_domain'], $row['certificate_issued_by'], $row['certificate_expire']);
            fputcsv($f, $lineData, $delimiter, $enclosure, $escape);
        }

        //move back to beginning of file
        fseek($f, 0);

        //set headers to download file rather than displayed
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //output all remaining data on a file pointer
        fpassthru($f);
    }

    logAction("Certificate", "Export", "$session_name exported $num_rows certificate(s) to a CSV file", $client_id);

    exit;

}
