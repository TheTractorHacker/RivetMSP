<?php
require_once "includes/inc_all_admin.php";

$vault_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_vault_canonical_key, config_vault_canonical_key_set_at FROM settings WHERE company_id = 1"));
$vault_canonical_key_set = !empty($vault_row['config_vault_canonical_key']);
$vault_canonical_key_set_at = $vault_row['config_vault_canonical_key_set_at'] ?? null;

$vault_unsynced_users = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM users WHERE user_status = 1 AND (user_specific_encryption_ciphertext IS NULL OR user_specific_encryption_ciphertext = '')"))['c']);

?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-key me-2"></i>Vault Encryption</h3>
    </div>
    <div class="card-body">

        <p class="text-muted">
            The credential vault is protected by a single shared encryption key. A canonical copy of
            this key is stored here (encrypted) so that user accounts which lose their personal copy
            (e.g. an archived/reactivated user) can automatically re-sync to the correct key on their
            next login, instead of being issued a new, incompatible one.
        </p>

        <?php if ($vault_canonical_key_set) { ?>
        <p>
            <i class="fas fa-fw fa-check-circle text-success me-1"></i>
            Canonical vault key established<?php if ($vault_canonical_key_set_at) { ?> on <?php echo nullable_htmlentities($vault_canonical_key_set_at); ?><?php } ?>.
        </p>
        <?php } else { ?>
        <p>
            <i class="fas fa-fw fa-exclamation-circle text-warning me-1"></i>
            No canonical vault key has been established yet.
        </p>
        <?php } ?>

        <?php if ($vault_unsynced_users > 0) { ?>
        <p class="text-muted">
            <?php echo $vault_unsynced_users; ?> active user(s) currently have no vault key of their own
            and will automatically re-sync to the canonical key on their next login.
        </p>
        <?php } ?>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <button type="submit" name="establish_canonical_vault_key" class="btn btn-secondary confirm-link">
                <i class="fas fa-key me-2"></i><?php echo $vault_canonical_key_set ? "Re-establish from my session" : "Establish from my session"; ?>
            </button>
        </form>

    </div>
</div>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-shield-alt me-2"></i>Security</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Login Message</label>
                <textarea class="form-control" name="config_login_message" rows="5" placeholder="Enter a message to be displayed on the login screen"><?php echo nullable_htmlentities($config_login_message); ?></textarea>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_login_key_required" <?php if ($config_login_key_required == 1) { echo "checked"; } ?> value="1" id="customSwitch1">
                    <label class="form-check-label" for="customSwitch1">Require a login key to access the technician login page?</label>
                </div>
            </div>

            <div class="form-group">
                <label>Login key secret value <small class="text-secondary">(This must be provided in the URL as /login.php?key=<?php echo nullable_htmlentities($config_login_key_secret)?>)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                    </div>
                    <input type="text" class="form-control" name="config_login_key_secret" pattern="\w{3,99}" placeholder="Something really easy for techs to remember: e.g. MYSECRET" value="<?php echo nullable_htmlentities($config_login_key_secret); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>2FA Remember Me Expire <small class="text-secondary">(The amount of days before a device 2FA remember me token will expire. Administrators and users with vault access must always pass two-factor again unless the setting in the Sign-in policy card allows remember-me to skip it.)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_login_remember_me_expire" placeholder="Enter Days to Expire" value="<?php echo intval($config_login_remember_me_expire); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Maximum session length <small class="text-secondary">(Minutes after which a sign-in always ends, however active &mdash; default 10080 = 7 days, up to 129600 = 90 days)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-hourglass-half"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_login_session_lifetime" min="60" max="129600" placeholder="Minutes (10080 = 7 days)" value="<?php echo intval($config_login_session_lifetime); ?>">
                    <div class="input-group-append">
                        <span class="input-group-text">minutes</span>
                    </div>
                </div>
                <small class="form-text text-secondary">The idle timeout in the Sign-in policy card below signs a session out sooner when nobody is using it.</small>
            </div>

            <div class="form-group">
                <label>Log retention <small class="text-secondary">(The amount of days before app/audit/auth logs are deleted during nightly cron)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_log_retention" placeholder="Enter days to retain" value="<?php echo intval($config_log_retention); ?>">
                </div>
            </div>

            <hr>

            <button type="submit" name="edit_security_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/security_policy.php";
$sp = secSettingsAll($mysqli, true);
?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-user-lock me-2"></i>Sign-in policy</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <h5 class="mb-3"><i class="fas fa-fw fa-mobile-alt me-2"></i>Two-factor authentication</h5>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Who must use two-factor</label>
                    <select class="form-control" name="mfa_policy">
                        <option value="off" <?php if ($sp['mfa_policy'] === 'off') { echo "selected"; } ?>>Nobody (each user can still be required in Users)</option>
                        <option value="admins" <?php if ($sp['mfa_policy'] === 'admins') { echo "selected"; } ?>>Administrators</option>
                        <option value="all" <?php if ($sp['mfa_policy'] === 'all') { echo "selected"; } ?>>All agents</option>
                    </select>
                    <small class="form-text text-secondary">Accounts that sign in through a company identity provider are not affected.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label>Grace period <small class="text-secondary">(days)</small></label>
                    <input type="number" class="form-control" name="mfa_grace_days" min="0" max="90" value="<?php echo intval($sp['mfa_grace_days']); ?>">
                    <small class="form-text text-secondary">A required user without two-factor can still sign in this many days after the policy is switched on (or after the account is created). After that the only page they can open is the one to set it up.</small>
                </div>
            </div>
            <div class="form-group">
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="remember_me_skips_mfa" id="rememberSkipsMfa" value="1" <?php if ($sp['remember_me_skips_mfa'] === '1') { echo "checked"; } ?>>
                    <label class="form-check-label" for="rememberSkipsMfa">Allow remember-me to skip two-factor for administrators and users with vault access</label>
                </div>
                <small class="form-text text-secondary">Off by default: a remember-me cookie never replaces the second factor for those users, and never signs them in without it.</small>
            </div>

            <hr>
            <h5 class="mb-3"><i class="fas fa-fw fa-key me-2"></i>Staff passwords</h5>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Minimum length</label>
                    <input type="number" class="form-control" name="password_min_length" min="8" max="128" value="<?php echo intval($sp['password_min_length']); ?>">
                    <small class="form-text text-secondary">Default 12. A password may not be the same as the person&rsquo;s name or email address.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label class="d-block">Breached-password check</label>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="password_hibp_check" id="hibpCheck" value="1" <?php if ($sp['password_hibp_check'] === '1') { echo "checked"; } ?>>
                        <label class="form-check-label" for="hibpCheck">Reject passwords found in public breaches</label>
                    </div>
                    <small class="form-text text-secondary">Off by default. Uses the Have I Been Pwned range service: only the first 5 characters of the password&rsquo;s SHA-1 hash leave this server. If the service cannot be reached the password is accepted.</small>
                </div>
            </div>

            <hr>
            <h5 class="mb-3"><i class="fas fa-fw fa-hourglass-half me-2"></i>Sessions</h5>
            <div class="form-group">
                <label>Idle timeout <small class="text-secondary">(minutes without activity before a sign-in ends &mdash; default 480 = 8 hours)</small></label>
                <input type="number" class="form-control" name="session_idle_minutes" min="5" max="129600" value="<?php echo intval($sp['session_idle_minutes']); ?>">
                <small class="form-text text-secondary">The maximum session length above still applies. Pages that only poll in the background do not count as activity.</small>
            </div>

            <hr>
            <h5 class="mb-3"><i class="fas fa-fw fa-lock me-2"></i>Credential vault list</h5>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Ask for the password again after <small class="text-secondary">(minutes, 0 = never)</small></label>
                    <input type="number" class="form-control" name="vault_stepup_minutes" min="0" max="1440" value="<?php echo intval($sp['vault_stepup_minutes']); ?>">
                    <small class="form-text text-secondary">Showing or copying a username or password from the credential list needs the account password if it was last entered longer ago than this. Default 15.</small>
                </div>
                <div class="col-md-6 form-group">
                    <label>Reveal limit <small class="text-secondary">(per user, per 10 minutes)</small></label>
                    <input type="number" class="form-control" name="vault_reveal_limit" min="1" max="1000" value="<?php echo intval($sp['vault_reveal_limit']); ?>">
                    <small class="form-text text-secondary">Past this a user is blocked and the administrators are notified. Every reveal and copy is in the audit log. Default 30.</small>
                </div>
            </div>

            <hr>
            <button type="submit" name="edit_security_policy" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save sign-in policy</button>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

