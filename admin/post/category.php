<?php

/*
 * ITFlow - GET/POST request handler for categories ('category')
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'category_model.php';

    // Roadmap #29: optional required skill tag (Ticket categories only; the add form only
    // ever renders/submits this field for type = 'Ticket', but validate the tag itself here
    // too - a stale/cross-type id must never be stored).
    $required_skill_tag_id = intval($_POST['required_skill_tag_id'] ?? 0);
    if ($required_skill_tag_id > 0) {
        $valid_skill = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tag_id FROM tags WHERE tag_id = $required_skill_tag_id AND tag_type = 7 LIMIT 1"));
        if (!$valid_skill) {
            $required_skill_tag_id = 0;
        }
    }
    $required_skill_sql = $required_skill_tag_id > 0 ? $required_skill_tag_id : 'NULL';

    mysqli_query($mysqli,"INSERT INTO categories SET category_name = '$name', category_description = '$description', category_type = '$type', category_color = '$color', category_parent = $parent, category_required_skill_tag_id = $required_skill_sql");

    $category_id = mysqli_insert_id($mysqli);

    logAction("Category", "Create", "$session_name created category $type $name", 0, $category_id);

    flash_alert("Category $type <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'category_model.php';

    $category_id = intval($_POST['category_id']);

    $required_skill_tag_id = intval($_POST['required_skill_tag_id'] ?? 0);
    if ($required_skill_tag_id > 0) {
        $valid_skill = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tag_id FROM tags WHERE tag_id = $required_skill_tag_id AND tag_type = 7 LIMIT 1"));
        if (!$valid_skill) {
            $required_skill_tag_id = 0;
        }
    }
    $required_skill_sql = $required_skill_tag_id > 0 ? $required_skill_tag_id : 'NULL';

    mysqli_query($mysqli,"UPDATE categories SET category_name = '$name', category_description = '$description', category_type = '$type', category_color = '$color', category_parent = $parent, category_required_skill_tag_id = $required_skill_sql WHERE category_id = $category_id");

    logAction("Category", "Edit", "$session_name edited category $type $name", 0, $category_id);

    flash_alert("Category $type <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $category_id = intval($_GET['archive_category']);

    // Get Category Name and Type for logging
    $sql = mysqli_query($mysqli,"SELECT category_name, category_type FROM categories WHERE category_id = $category_id");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name']);
    $category_type = sanitizeInput($row['category_type']);

    mysqli_query($mysqli,"UPDATE categories SET category_archived_at = NOW() WHERE category_id = $category_id");

    logAction("Category", "Archive", "$session_name archived category $category_type $category_name", 0, $category_id);

    flash_alert("Category $category_type <strong>$category_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $category_id = intval($_GET['restore_category']);

    // Get Category Name and Type for logging
    $sql = mysqli_query($mysqli,"SELECT category_name, category_type FROM categories WHERE category_id = $category_id");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name']);
    $category_type = sanitizeInput($row['category_type']);

    mysqli_query($mysqli,"UPDATE categories SET category_archived_at = NULL WHERE category_id = $category_id");

    logAction("Category", "Restore", "$session_name retored category $category_type $category_name", 0, $category_id);

    flash_alert("Category $category_type <strong>$category_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $category_id = intval($_GET['delete_category']);

    // Get Category Name and Type for logging
    $sql = mysqli_query($mysqli,"SELECT category_name, category_type FROM categories WHERE category_id = $category_id");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name']);
    $category_type = sanitizeInput($row['category_type']);

    mysqli_query($mysqli,"DELETE FROM categories WHERE category_id = $category_id");

    logAction("Category", "Delete", "$session_name deleted category $category_type $category_name");

    flash_alert("Category $category_type <strong>$category_name</strong> deleted", 'error');

    redirect();

}
