<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_credential');

$credential_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_id = $credential_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$client_id               = intval($row['credential_client_id']);
$credential_name         = nullable_htmlentities($row['credential_name']);
$credential_description  = nullable_htmlentities($row['credential_description']);
$credential_uri          = nullable_htmlentities($row['credential_uri']);
$credential_uri_2        = nullable_htmlentities($row['credential_uri_2']);
$credential_uri_link     = sanitize_url($row['credential_uri']);
$credential_uri_2_link   = sanitize_url($row['credential_uri_2']);
// Nothing here is decrypted into the page. Username and password are drawn masked; the eye and copy buttons fetch one
// field on demand from credential_reveal.php, which audits every reveal/copy and enforces the per-user rate limit and
// password step-up (includes/vault_reveal.php) - the same mechanism agent/credentials.php uses, never a separate path.
$credential_has_username = !empty($row['credential_username']);
$credential_has_password = !empty($row['credential_password']);
$credential_username_display = $credential_has_username
    ? "<span class='cred-cell'><span class='cred-mask js-cred-value' data-masked='1'>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>"
        . "<button class='btn btn-sm cred-btn js-cred-reveal' type='button' title='Show username' aria-label='Show username' data-credential-id='$credential_id' data-field='username'><i class='far fa-eye text-secondary'></i></button>"
        . "<button class='btn btn-sm cred-btn js-cred-copy' type='button' title='Copy username' aria-label='Copy username' data-credential-id='$credential_id' data-field='username'><i class='far fa-copy text-secondary'></i></button></span>"
    : '';
$credential_password_display = $credential_has_password
    ? "<span class='cred-cell'><span class='cred-mask js-cred-value' data-masked='1'>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>"
        . "<button class='btn btn-sm cred-btn js-cred-reveal' type='button' title='Show password' aria-label='Show password' data-credential-id='$credential_id' data-field='password'><i class='far fa-eye text-secondary'></i></button>"
        . "<button class='btn btn-sm cred-btn js-cred-copy' type='button' title='Copy password' aria-label='Copy password' data-credential-id='$credential_id' data-field='password'><i class='far fa-copy text-secondary'></i></button></span>"
    : '';
// Same as agent/credentials.php: only the ciphertext's presence is checked here, never decrypted - the hover trigger
// below fetches the live code from agent/ajax.php's get_totp_token_via_id, which is the audited path (logAction "View TOTP").
$credential_otp_secret   = nullable_htmlentities($row['credential_otp_secret'] ?? '');
$credential_note         = nullable_htmlentities($row['credential_note']);
$credential_created_at   = nullable_htmlentities($row['credential_created_at']);
// Same "reminder only" badge as agent/credentials.php's list row.
$credential_rotation_due_at = $row['credential_rotation_due_at'] ?? null;
$credential_rotation_display = '<span class="text-muted">—</span>';
if ($credential_rotation_due_at) {
    $rotation_days = (strtotime($credential_rotation_due_at) - time()) / 86400;
    $rotation_class = $rotation_days <= 0 ? 'text-bg-danger' : ($rotation_days <= 14 ? 'text-bg-warning' : 'text-bg-success');
    $credential_rotation_display = nullable_htmlentities($credential_rotation_due_at) . " <span class='badge $rotation_class ms-1'>" . ($rotation_days <= 0 ? 'Overdue' : ($rotation_days <= 14 ? 'Due soon' : 'OK')) . '</span>';
}

if (empty($credential_otp_secret)) {
    $otp_display = '<span class="text-muted">—</span>';
} else {
    $otp_display = "<span class='otp-reveal-trigger' data-credential-id='$credential_id'><i class='far fa-clock me-1'></i><span id='otp_$credential_id'><em class='text-muted'>Hover to reveal…</em></span></span>";
}

enforceClientAccess();

ob_start();
?>

<div class="modal-header bg-dark text-white">
    <div class="d-flex align-items-center">
        <i class="fas fa-fw fa-key fa-2x me-3"></i>
        <div>
            <h5 class="modal-title mb-0"><?= $credential_name ?></h5>
            <?php if ($credential_description) { ?>
                <small class="text-muted"><?= $credential_description ?></small>
            <?php } ?>
        </div>
    </div>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body">

    <table class="table table-sm table-borderless mb-0">
        <tbody>

            <tr>
                <td class="text-muted" style="width:130px;white-space:nowrap;"><i class="fas fa-fw fa-user me-1"></i>Username</td>
                <td>
                    <?php if ($credential_has_username) { echo $credential_username_display; } else { ?>
                        <span class="text-muted">—</span>
                    <?php } ?>
                </td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-lock me-1"></i>Password</td>
                <td>
                    <?php if ($credential_has_password) { echo $credential_password_display; } else { ?>
                        <span class="text-muted">—</span>
                    <?php } ?>
                </td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-shield-alt me-1"></i>TOTP</td>
                <td><?= $otp_display ?></td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-link me-1"></i>URI</td>
                <td>
                    <?php if ($credential_uri) { ?>
                        <a href="<?= $credential_uri_link ?>" target="_blank" rel="noopener noreferrer"><?= $credential_uri ?></a>
                        <button class="btn btn-sm clipboardjs ms-1" type="button" title="Copy URI" data-clipboard-text="<?= $credential_uri ?>"><i class="far fa-copy text-secondary"></i></button>
                    <?php } else { ?>
                        <span class="text-muted">—</span>
                    <?php } ?>
                </td>
            </tr>

            <?php if ($credential_uri_2) { ?>
            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-link me-1"></i>URI 2</td>
                <td>
                    <a href="<?= $credential_uri_2_link ?>" target="_blank" rel="noopener noreferrer"><?= $credential_uri_2 ?></a>
                    <button class="btn btn-sm clipboardjs ms-1" type="button" title="Copy URI 2" data-clipboard-text="<?= $credential_uri_2 ?>"><i class="far fa-copy text-secondary"></i></button>
                </td>
            </tr>
            <?php } ?>

            <?php if ($credential_note) { ?>
            <tr>
                <td class="text-muted align-top"><i class="fas fa-fw fa-sticky-note me-1"></i>Notes</td>
                <td style="white-space:pre-wrap;"><?= $credential_note ?></td>
            </tr>
            <?php } ?>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-history me-1"></i>Rotation Due</td>
                <td><?= $credential_rotation_display ?></td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-calendar-alt me-1"></i>Created</td>
                <td class="text-muted"><?= $credential_created_at ?></td>
            </tr>

        </tbody>
    </table>

</div>

<div class="modal-footer">
    <?php if (lookupUserPermission('module_credential') >= 2) { ?>
    <a href="#" class="btn btn-outline-secondary ajax-modal"
        data-modal-url="modals/credential/credential_edit.php?id=<?= $credential_id ?>">
        <i class="fas fa-edit me-1"></i>Edit
    </a>
    <?php } ?>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Close</button>
</div>

<div id="credential-vault-list" hidden data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>" data-reveal-url="credential_reveal.php"></div>
<script src="js/credential_show_otp_via_id.js"></script>
<?php if ($credential_has_username || $credential_has_password) { ?>
<script src="js/credential_vault_list.js"></script>
<?php } ?>

<?php
require_once '../../../includes/modal_footer.php';
