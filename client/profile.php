<?php
/*
 * Client Portal
 * User profile
 */

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

?>

    <div class="row mb-4">
        <div class="col">
            <h3><i class="fas fa-fw fa-user me-2"></i>Profile</h3>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card card-outline card-primary h-100">
                <div class="card-body">
                    <dl class="row mb-0 portal-dl">
                        <dt class="col-sm-5">Name</dt>
                        <dd class="col-sm-7"><?php echo stripslashes(nullable_htmlentities($session_contact_name)); ?></dd>
                        <dt class="col-sm-5">Email</dt>
                        <dd class="col-sm-7"><?php echo $session_contact_email ?></dd>
                        <?php if ($session_contact_pin !== '') { ?>
                        <dt class="col-sm-5">PIN</dt>
                        <dd class="col-sm-7"><?php echo $session_contact_pin ?></dd>
                        <?php } ?>
                        <dt class="col-sm-5">Client</dt>
                        <dd class="col-sm-7"><?php echo $session_client_name ?></dd>
                        <dt class="col-sm-5">Primary contact</dt>
                        <dd class="col-sm-7"><?php echo $session_contact_primary == 1 ? 'Yes' : 'No'; ?></dd>
                        <dt class="col-sm-5">Technical contact</dt>
                        <dd class="col-sm-7"><?php echo $session_contact_is_technical_contact ? 'Yes' : 'No'; ?></dd>
                        <dt class="col-sm-5">Billing contact</dt>
                        <dd class="col-sm-7"><?php echo $session_contact_is_billing_contact ? 'Yes' : 'No'; ?></dd>
                        <dt class="col-sm-5">Login via</dt>
                        <dd class="col-sm-7"><?php echo nullable_htmlentities($_SESSION['login_method']) ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    <!--  // Show option to change password if auth provider is local -->
<?php if ($_SESSION['login_method'] == 'local'): ?>
        <div class="col-lg-6">
            <div class="card card-outline card-primary h-100">
                <div class="card-header"><h4 class="card-title h5 mb-0">Change password</h4></div>
                <div class="card-body">
                    <form action="post.php" method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="mb-3">
                            <label class="form-label" for="new_password">New password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                                <input type="password" class="form-control" id="new_password" minlength="8" required data-toggle="password" name="new_password" placeholder="At least 8 characters" autocomplete="new-password">
                            </div>
                        </div>
                        <button type="submit" name="edit_profile" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save password</button>
                    </form>
                </div>
            </div>
        </div>
<?php endif ?>
    </div>

<?php
require_once 'includes/footer.php';
