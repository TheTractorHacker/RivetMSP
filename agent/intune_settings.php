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
$ms_last_sync_at = $row['last_sync_at'] ?? null;

$ms_devices = [];
$ms_last_log = null;
if ($ms_id) {
    $res = mysqli_query($mysqli, "SELECT * FROM asset_intune_links WHERE microsoft_integration_id = $ms_id ORDER BY hostname ASC");
    $ms_devices = [];
    while ($res && ($d = mysqli_fetch_assoc($res))) { $ms_devices[] = $d; }
    $ms_last_log = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM intune_sync_log WHERE microsoft_integration_id = $ms_id ORDER BY id DESC LIMIT 1"));
}

?>

<div class="alga-theme">

    <div class="card card-dark mb-3">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fab fa-fw fa-microsoft me-2"></i>Microsoft 365 / Entra ID</h3>
        </div>
        <div class="card-body">
            <p class="text-muted mb-0">This department's own Microsoft 365 tenant connection - separate from every other client's. Turn on "Sync Intune devices" below and use Sync Now to pull this tenant's Intune-managed devices into this department's own assets. Syncing Entra users is still a later piece of work, not part of this page.</p>
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
                    <label for="msTenantId">Tenant ID</label>
                    <input type="text" class="form-control" id="msTenantId" name="tenant_id" value="<?php echo $ms_tenant_id; ?>" placeholder="e.g. 72f988bf-86f1-41af-91ab-2d7cd011db47">
                </div>
                <div class="form-group">
                    <label for="msAzureClientId">Application (Client) ID</label>
                    <input type="text" class="form-control" id="msAzureClientId" name="azure_client_id" value="<?php echo $ms_azure_client_id; ?>" placeholder="e.g. 0f1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b">
                    <small class="form-text text-muted">The Azure AD App Registration's own "Application (client) ID" - a different value from the Tenant ID above.</small>
                </div>
                <div class="form-group">
                    <label for="msClientSecret">Client Secret</label>
                    <input type="password" class="form-control" id="msClientSecret" name="client_secret" placeholder="<?php echo $ms_has_secret ? 'Stored - leave blank to keep current' : 'Enter client secret'; ?>" autocomplete="new-password">
                </div>
                <div class="form-check form-switch mb-1">
                    <input type="checkbox" class="form-check-input" name="enabled" value="1" id="msEnabled" <?php if ($ms_enabled) { echo "checked"; } ?>>
                    <label class="form-check-label" for="msEnabled">Enabled</label>
                </div>
                <div class="form-check form-switch mb-1">
                    <input type="checkbox" class="form-check-input" name="intune_sync_enabled" value="1" id="msIntuneSyncEnabled" <?php if ($ms_intune_sync_enabled) { echo "checked"; } ?>>
                    <label class="form-check-label" for="msIntuneSyncEnabled">Sync Intune devices (hourly, via cron, when enabled)</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" class="form-check-input" name="directory_sync_enabled" value="1" id="msDirectorySyncEnabled" <?php if ($ms_directory_sync_enabled) { echo "checked"; } ?>>
                    <label class="form-check-label" for="msDirectorySyncEnabled">Sync users from Entra ID (not yet implemented)</label>
                </div>
                <small class="text-muted d-block mb-3">Entra directory sync is stored for later use only - nothing reads it yet. Intune device sync is live: Sync Now below (or the hourly cron, once "Sync Intune devices" is on and saved) calls Microsoft Graph for THIS tenant only and maps its devices into THIS department's own assets.</small>

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

    <?php if ($ms_id) { ?>
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Intune Devices</h5>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                <input type="hidden" name="microsoft_integration_id" value="<?php echo $ms_id; ?>">
                <button type="submit" name="sync_microsoft_intune" class="btn btn-sm btn-primary" <?php if (!$ms_enabled || !$ms_intune_sync_enabled) { echo 'disabled title="Turn on Enabled and Sync Intune devices, and Save, first"'; } ?>>
                    <i class="fas fa-fw fa-sync me-1"></i>Sync Now
                </button>
            </form>
        </div>
        <div class="card-body">
            <?php if ($ms_last_log) { ?>
                <p class="text-muted small mb-3">
                    Last run <?php echo nullable_htmlentities(date('M d, Y g:i A', strtotime($ms_last_log['started_at']))); ?> -
                    <span class="<?php echo $ms_last_log['status'] === 'success' ? 'text-success' : ($ms_last_log['status'] === 'running' ? 'text-warning' : 'text-danger'); ?>">
                        <?php echo nullable_htmlentities($ms_last_log['status']); ?>
                    </span>
                    <?php if ($ms_last_log['status'] !== 'running') { ?>
                        (<?php echo intval($ms_last_log['devices_created']); ?> created, <?php echo intval($ms_last_log['devices_updated']); ?> updated, <?php echo intval($ms_last_log['devices_matched']); ?> matched, <?php echo intval($ms_last_log['devices_skipped']); ?> skipped)
                    <?php } ?>
                    <?php if (!empty($ms_last_log['errors'])) { ?>
                        <br><span class="text-danger"><?php echo nullable_htmlentities($ms_last_log['errors']); ?></span>
                    <?php } ?>
                </p>
            <?php } ?>

            <?php if (empty($ms_devices)) { ?>
                <p class="text-muted mb-0">No Intune devices synced yet for this department.</p>
            <?php } else { ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>OS</th>
                                <th>Serial</th>
                                <th>Compliance</th>
                                <th>Primary User</th>
                                <th>Last Intune Sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ms_devices as $d) { ?>
                                <tr>
                                    <td><?php echo nullable_htmlentities($d['hostname']); ?></td>
                                    <td><?php echo nullable_htmlentities($d['os_name']); ?></td>
                                    <td><?php echo nullable_htmlentities($d['serial_number']); ?></td>
                                    <td><?php echo nullable_htmlentities($d['compliance_state']); ?></td>
                                    <td><?php echo nullable_htmlentities($d['primary_user_upn']); ?></td>
                                    <td><?php echo $d['intune_last_sync_at'] ? nullable_htmlentities(date('M d, Y g:i A', strtotime($d['intune_last_sync_at']))) : ''; ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

</div>

<?php
require_once "../includes/footer.php";
