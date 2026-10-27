<?php
/*
 * Roadmap #29: a technician's skill tags, used by dispatchAssignTicket() (includes/dispatch_functions.php)
 * to match a ticket's category to a technician who holds the category's required skill. Mirrors
 * agent/modals/ticket/ticket_tags.php exactly, but for user_tags / tag_type = 7 ("Technician").
 */

require_once '../../../includes/modal_header.php';

$user_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $user_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
if (!$row) {
    exit('User not found');
}
$user_name = nullable_htmlentities($row['user_name']);

$user_tag_id_array = array();
$sql_user_tags = mysqli_query($mysqli, "SELECT user_tag_tag_id FROM user_tags WHERE user_tag_user_id = $user_id");
while ($tag_row = mysqli_fetch_assoc($sql_user_tags)) {
    $user_tag_id_array[] = intval($tag_row['user_tag_tag_id']);
}

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tags me-2"></i>Skills: <strong><?php echo $user_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Skills <small class="text-secondary">(matched against a Ticket category's Required Skill by auto-dispatch)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                </div>
                <select class="form-control select2" name="tags[]" data-placeholder="Add some skills" multiple>
                    <?php
                    $sql_tags_select = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 7 ORDER BY tag_name ASC");
                    while ($tag_select_row = mysqli_fetch_assoc($sql_tags_select)) {
                        $tag_id_select = intval($tag_select_row['tag_id']);
                        $tag_name_select = nullable_htmlentities($tag_select_row['tag_name']);
                        ?>
                        <option value="<?php echo $tag_id_select; ?>" <?php if (in_array($tag_id_select, $user_tag_id_array)) { echo "selected"; } ?>><?php echo $tag_name_select; ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="text-muted">No Technician tags yet? Create them under Settings &gt; Tags &gt; Technician.</small>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="update_user_skills" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save Skills</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
