<?php
require_once "includes/inc_all_admin.php";

// Client Portal Branding (Issue #21). MSP-wide white-label settings for the client portal:
// an MSP typically wants ITS OWN brand shown to clients (not per-client branding), so - like
// Theme/Appearance - this is a single company_id = 1 row, not scoped per client.
//
// Each field is optional and falls back to the existing company name/logo/admin accent when
// unset: see includes/load_global_settings.php ($config_portal_*) and
// client/includes/header.php (where the fallbacks are resolved).

require_once __DIR__ . "/../includes/theme_accent.php";

$portal_display_name = nullable_htmlentities($config_portal_display_name ?? '');
$portal_logo = nullable_htmlentities($config_portal_logo ?? '');
$portal_accent_raw = (string) ($config_portal_accent ?? '');
$portal_accent_input = nullable_htmlentities($portal_accent_raw);

// Preview swatch default: the portal's own custom accent if set, else fall back to the admin accent so the
// picker starts somewhere sensible rather than always defaulting to teal.
$portal_accent_preview = preg_match('/^#[0-9A-Fa-f]{6}$/', $portal_accent_raw)
    ? strtoupper($portal_accent_raw)
    : (itflow_theme_accent_hex($config_theme ?? '', $config_theme_accent_custom ?? null) ?: '#0D9488');

?>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-tablet-alt me-2"></i>Client Portal Branding</h3>
    </div>
    <div class="card-body">
        <p class="text-secondary">
            These settings control how the client portal looks to your clients. They apply company-wide across
            the whole portal (every client sees the same branding) - they do not change the admin interface you
            use day to day. Leave a field blank to fall back to your normal Company Details / Theme settings.
        </p>

        <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="row">
                <div class="col-md-3">
                    <?php if ($portal_logo) { ?>
                        <img class="img-thumbnail" src="<?php echo "../uploads/settings/$portal_logo"; ?>" alt="Portal logo">
                        <a href="post.php?remove_portal_logo&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger btn-block">Remove Portal Logo</a>
                        <hr>
                    <?php } ?>
                    <div class="form-group">
                        <label>Portal logo <small class="text-secondary d-block">(optional &mdash; falls back to your company logo)</small></label>
                        <input type="file" class="form-control-file" name="file" accept=".jpg, .jpeg, .png">
                    </div>
                </div>

                <div class="col-md-9">
                    <div class="form-group">
                        <label>Portal display name</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                            </div>
                            <input type="text" class="form-control" name="config_portal_display_name" placeholder="<?php echo nullable_htmlentities($session_company_name ?? ''); ?>" value="<?php echo $portal_display_name; ?>" maxlength="200">
                        </div>
                        <small class="text-secondary">Shown in the portal navbar, browser tab title and footer. Leave blank to use your company name.</small>
                    </div>

                    <hr>

                    <div class="form-group">
                        <label>Portal accent color <small class="text-secondary">(optional &mdash; overrides your admin accent for the portal only)</small></label>
                        <div class="input-group" style="max-width: 320px;">
                            <div class="input-group-prepend">
                                <span class="input-group-text p-1">
                                    <input type="color" id="portal_accent_picker" value="<?php echo $portal_accent_preview; ?>" style="width: 38px; height: 32px; border: none; background: none; padding: 0; cursor: pointer;">
                                </span>
                            </div>
                            <input type="text" class="form-control" id="portal_accent_hex" name="config_portal_accent" placeholder="#0D9488" pattern="^#[0-9A-Fa-f]{6}$" title="Enter a 6-digit hex colour, e.g. #0D9488" value="<?php echo $portal_accent_input; ?>">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" id="portal_accent_clear" title="Clear custom colour"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <small class="text-secondary">Leave blank to use your admin Theme/Appearance accent in the portal too.</small>
                    </div>

                    <hr>

                    <button type="submit" name="edit_portal_branding_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script nonce="<?php echo $csp_nonce; ?>">
(function () {
    var picker = document.getElementById('portal_accent_picker');
    var hex = document.getElementById('portal_accent_hex');
    var clearBtn = document.getElementById('portal_accent_clear');

    picker.addEventListener('input', function () {
        hex.value = picker.value.toUpperCase();
    });

    hex.addEventListener('input', function () {
        if (/^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
            picker.value = hex.value;
        }
    });

    clearBtn.addEventListener('click', function () {
        hex.value = '';
        hex.focus();
    });
})();
</script>

<?php
require_once "../includes/footer.php";
