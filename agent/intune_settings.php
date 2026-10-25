<?php

/*
 * Microsoft 365 / Entra ID tenant credentials, PER CLIENT (roadmap #10 slice 1).
 *
 * RivetIT's own version of this page (admin/settings_integrations.php, "Directory Sync" tab) is
 * installation-wide: one microsoft_integrations row for the whole app, every client's Intune devices and
 * Entra directory synced from the SAME tenant. That is wrong for an MSP managing many separate client
 * organizations, each with their own Microsoft 365 tenant - see the roadmap issue's own wording, "needs a
 * per-client tenant credential model, not RivetIT's single tenant".
 *
 * This is SLICE 1 ONLY: the per-client schema (microsoft_integrations now has a client_id with a UNIQUE key -
 * one tenant per department) and this page to store/edit/remove those credentials, encrypted at rest through
 * the same encryptSetting()/ColumnRegistry mechanism every other integration secret in this app uses. There is
 * NO "Test Connection" button and NO sync here - wiring GraphClientFactory/the token cache/the sync cron to
 * actually use a chosen client's tenant (instead of RivetIT's one global row) is slice 2, held back on purpose:
 * getting per-tenant OAuth token caching and the sync loop wrong is a real cross-client data-leak risk, not
 * just a UI bug, and deserves its own focused pass rather than riding along with the schema/UI work here.
 */

require_once "includes/inc_all_client.php";   // sets $client_id, enforces module_client + enforceClientAccess()

enforceUserPermission('module_support', 1);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations WHERE client_id = $client_id LIMIT 1"));

$ms_id = intval($row['microsoft_integration_id'] ?? 0);
$ms_tenant_id = nullable_htmlentities($row['tenant_id'] ?? '');
$ms_azure_client_id = nullable_htmlentities($row['azure_client_id'] ?? '');
$ms_has_secret = !empty($row['client_secret_enc']);
$ms_enabled = intval($row['enabled'] ?? 0);
$ms_intune_sync_enabled = intval($row['intune_sync_enabled'] ?? 0);
$ms_directory_sync_enabled = intval($row['directory_sync_enabled'] ?? 0);
$ms_created_at = $row['created_at'] ?? null;
$ms_updated_at = $row['updated_at'] ?? null;

?>

<div class="alga-theme">

    <div class="card card-dark mb-3">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fab fa-fw fa-microsoft me-2"></i>Microsoft 365 / Entra ID</h3>
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">This department's own Microsoft 365 tenant connection - separate from every other client's. Stored here, not yet used for anything: syncing Intune devices or Entra users from this tenant is a later piece of work, not part of this page.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Tenant Credentials</h5>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">

                <div class="form-group">
                    <label>Tenant ID</label>
                    <input type="text" class="form-control" name="tenant_id" value="<?php echo $ms_tenant_id; ?>" placeholder="e.g. 72f988bf-86f1-41af-91ab-2d7cd011db47">
                </div>
                <div class="form-group">
                    <label>Application (Client) ID</label>
                    <input type="text" class="form-control" name="azure_client_id" value="<?php echo $ms_azure_client_id; ?>">
                    <small class="form-text text-muted">The Azure AD App Registration's own "Application (client) ID" - a different value from the department picked above.</small>
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="password" class="form-control" name="client_secret" placeholder="<?php echo $ms_has_secret ? 'Stored - leave blank to keep current' : 'Enter client secret'; ?>" autocomplete="new-password">
                </div>
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" class="form-check-input" name="enabled" value="1" id="msEnabled" <?php if ($ms_enabled) { echo "checked"; } ?>>
                    <label class="form-check-label" for="msEnabled">Enabled</label>
                </div>
                <div class="form-check form-switch mb-1">
                    <input type="checkbox" class="form-check-input" name="intune_sync_enabled" value="1" id="msIntuneSyncEnabled" <?php if ($ms_intune_sync_enabled) { echo "checked"; } ?>>
                    <label class="form-check-label" for="msIntuneSyncEnabled">Sync Intune devices (not yet implemented)</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" class="form-check-input" name="directory_sync_enabled" value="1" id="msDirectorySyncEnabled" <?php if ($ms_directory_sync_enabled) { echo "checked"; } ?>>
                    <label class="form-check-label" for="msDirectorySyncEnabled">Sync users from Entra ID (not yet implemented)</label>
                </div>
                <small class="text-muted d-block mb-3">These two switches are stored for later use only. Nothing in this app reads this tenant's credentials or calls Microsoft Graph yet - there is no sync engine wired up to this page.</small>

                <button type="submit" name="save_microsoft_integration" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
                <?php if ($ms_id) { ?>
                    <a class="btn btn-danger confirm-link" href="post.php?delete_microsoft_integration=<?php echo $ms_id; ?>&client_id=<?php echo $client_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                        <i class="fas fa-fw fa-trash-alt me-2"></i>Remove
                    </a>
                <?php } ?>
            </form>
        </div>
        <?php if ($ms_updated_at) { ?>
        <div class="card-footer text-muted small">
            Last updated <?php echo nullable_htmlentities(date('M d, Y g:i A', strtotime($ms_updated_at))); ?>
        </div>
        <?php } ?>
    </div>

</div>

<?php
require_once "../includes/footer.php";
