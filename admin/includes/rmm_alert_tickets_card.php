<?php
/* "Alert tickets" card: which ticket category (and priority) tickets made from RMM alerts get. Included by admin/settings_integrations.php. */
require_once dirname(__DIR__, 2) . '/includes/rmm_alert_routes.php';
$at_cats    = rmmAlertTicketCategories($mysqli);
$at_row     = @mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_rmm_alert_ticket_category_id AS c FROM settings WHERE company_id = 1"));
$at_default = (int) ($at_row['c'] ?? 0);
if (!isset($at_cats[$at_default])) { $at_default = 0; }
$at_routes  = rmmAlertRoutesLoad($mysqli);
$at_has_maint = in_array('maintenance', array_map('strtolower', $at_cats), true);
$at_default_is_maint = $at_default > 0 && strtolower($at_cats[$at_default] ?? '') === 'maintenance';
// New RMM tickets: SLA / email defaults, and the label-only backfill preview for existing ones (DB 2.6.105)
require_once dirname(__DIR__, 2) . '/includes/ticket_flags.php';
$at_flags_ready = ticketFlagsReady($mysqli);
$at_flag_defaults = rmmAlertTicketFlagDefaults($mysqli);
$at_backfill = $at_flags_ready ? rmmTicketDefaultsBackfill($mysqli, true) : null;
$at_whens = [
    'Check family' => ['check_prefix', array_map(fn($f) => $f[0], rmmAlertCheckFamilies())],
    'Source'       => ['source', rmmAlertSources()],
    'Severity'     => ['severity', rmmAlertSeverities()],
];
$at_opt = function (string $selType, string $selVal) use ($at_whens): string {
    $h = '<option value="">Choose...</option>';
    foreach ($at_whens as $group => [$type, $items]) {
        $h .= '<optgroup label="' . htmlspecialchars($group) . '">';
        foreach ($items as $k => $label) {
            $h .= '<option value="' . htmlspecialchars($type . '|' . $k) . '"' . (($type === $selType && (string) $k === $selVal) ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h;
};
$at_cat_opt = function (int $sel) use ($at_cats): string {
    $h = '<option value="">Choose...</option>';
    foreach ($at_cats as $id => $name) { $h .= '<option value="' . $id . '"' . ($id === $sel ? ' selected' : '') . '>' . htmlspecialchars($name) . '</option>'; }
    return $h;
};
$at_prio_opt = function (?string $sel): string {
    $h = '<option value="">Keep the usual priority</option>';
    foreach (rmmAlertPriorities() as $p) { $h .= '<option value="' . $p . '"' . ($p === $sel ? ' selected' : '') . '>' . $p . '</option>'; }
    return $h;
};
?>
    <div class="card mb-3" id="alert-tickets-card">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-folder-open me-2"></i>Alert tickets</h3>
        </div>
        <div class="card-body">
            <p class="text-muted small">Choose which ticket category a ticket gets when it is made from an RMM alert, whether automatically or with the "Create ticket" button. Which alerts become tickets by themselves is still decided by the severities above. Leave everything on "No category" to keep tickets from alerts uncategorized, as before.</p>
            <?php if (!$at_cats): ?>
            <div class="alert alert-info">There are no ticket categories yet.</div>
            <?php endif; ?>
            <?php if (!$at_has_maint): ?>
            <form action="post.php" method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="create_alert_maintenance_category" class="btn btn-outline-primary btn-sm"><i class="fas fa-plus me-1"></i>Create a "Maintenance" category and use it</button>
            </form>
            <?php elseif (!$at_default_is_maint): ?>
            <form action="post.php" method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="create_alert_maintenance_category" class="btn btn-outline-primary btn-sm"><i class="fas fa-check me-1"></i>Use the "Maintenance" category for alert tickets</button>
            </form>
            <?php endif; ?>
            <form action="post.php" method="post" id="alert-tickets-form" data-category-names='<?= htmlspecialchars(json_encode($at_cats, JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?: '{}', ENT_QUOTES) ?>'>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="mb-3" style="max-width:420px">
                    <label class="form-label" for="at_default">Category for tickets from alerts</label>
                    <select class="form-select" name="default_category" id="at_default">
                        <option value="0">No category</option>
                        <?php foreach ($at_cats as $id => $name): ?>
                        <option value="<?= $id ?>" <?= $id === $at_default ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">RivetMSP has no ticket boards or queues, so the category is the only place to choose.</small>
                </div>

                <h4 class="h6">Special cases (optional)</h4>
                <p class="text-muted small mb-2">The most specific rule wins: a check family first, then the source, then the severity, then the category above. Each choice can be used once.</p>
                <div class="table-responsive">
                <table class="table table-sm align-middle" id="at-rules">
                    <thead><tr><th>When</th><th>Use category</th><th>Priority (optional)</th><th style="width:1%"></th></tr></thead>
                    <tbody>
                    <?php foreach ($at_routes as $i => $r): ?>
                        <tr class="at-rule">
                            <td><select class="form-select form-select-sm at-when" aria-label="When"><?= $at_opt($r['match_type'], $r['match_value']) ?></select>
                                <input type="hidden" name="route_type[<?= $i ?>]" class="at-type" value="<?= htmlspecialchars($r['match_type']) ?>"><input type="hidden" name="route_value[<?= $i ?>]" class="at-value" value="<?= htmlspecialchars($r['match_value']) ?>"></td>
                            <td><select class="form-select form-select-sm at-cat" name="route_category[<?= $i ?>]" aria-label="Category"><?= $at_cat_opt((int) $r['category_id']) ?></select></td>
                            <td><select class="form-select form-select-sm at-prio" name="route_priority[<?= $i ?>]" aria-label="Priority"><?= $at_prio_opt($r['priority']) ?></select></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger at-remove" aria-label="Remove this rule"><i class="fas fa-times"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="at-add"><i class="fas fa-plus me-1"></i>Add a special case</button>

                <div class="alert alert-secondary py-2" id="at-summary" aria-live="polite"></div>
                <button type="submit" name="save_rmm_alert_ticket_settings" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save alert ticket settings</button>

                <template id="at-row-template">
                    <tr class="at-rule">
                        <td><select class="form-select form-select-sm at-when" aria-label="When"><?= $at_opt('', '') ?></select>
                            <input type="hidden" class="at-type" value=""><input type="hidden" class="at-value" value=""></td>
                        <td><select class="form-select form-select-sm at-cat" aria-label="Category"><?= $at_cat_opt(0) ?></select></td>
                        <td><select class="form-select form-select-sm at-prio" aria-label="Priority"><?= $at_prio_opt(null) ?></select></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger at-remove" aria-label="Remove this rule"><i class="fas fa-times"></i></button></td>
                    </tr>
                </template>
            </form>

            <?php if ($at_flags_ready): ?>
            <hr>
            <h4 class="h6" id="alert-ticket-defaults">New tickets from alerts: SLA and email</h4>
            <p class="text-muted small mb-2">Tickets made from RMM alerts are maintenance work: by default they do not count toward SLA and send no email. These choices are applied when a ticket is created; a technician can still change either one on the ticket itself. Tickets created from an email always send email.</p>
            <form action="post.php" method="post" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" name="config_rmm_alert_ticket_sla" id="at_flag_sla" value="1" <?= $at_flag_defaults['sla'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="at_flag_sla">RMM tickets count toward SLA</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="config_rmm_alert_ticket_email" id="at_flag_email" value="1" <?= $at_flag_defaults['email'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="at_flag_email">Send email for RMM tickets</label>
                </div>
                <button type="submit" name="save_rmm_alert_ticket_flags" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save</button>
            </form>

            <h4 class="h6" id="alert-ticket-backfill">Existing tickets from alerts</h4>
            <p class="text-muted small mb-2">Labels tickets made from RMM alerts before this update (a ticket an RMM alert links to, or one created by "RMM Alert", "RMM Automation" or "RMM Escalation") as RMM tickets and turns their email notifications off. Their SLA is left exactly as it is. Tickets created from an email are never touched. Safe to run again.</p>
            <p class="small mb-2" id="at-backfill-preview"><?= (int) $at_backfill['matched'] ?> ticket(s) from alerts found: <?= (int) $at_backfill['label'] ?> would be labelled, <?= (int) $at_backfill['email'] ?> would have email turned off.</p>
            <form action="post.php" method="post" class="d-flex flex-wrap" style="gap:.5rem;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="rmm_ticket_defaults_backfill" value="dry" class="btn btn-outline-secondary btn-sm"><i class="fas fa-search me-1"></i>Preview (dry run)</button>
                <button type="submit" name="rmm_ticket_defaults_backfill" value="run" class="btn btn-outline-primary btn-sm" data-rivet-confirm="Label <?= (int) $at_backfill['matched'] ?> existing ticket(s) from RMM alerts and turn their email notifications off? Their SLA is not changed. Email already queued for them is cancelled." data-confirm-ok="Apply"><i class="fas fa-check me-1"></i>Apply to existing tickets</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <script src="/js/rmm_alert_routes.js?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/js/rmm_alert_routes.js') ?>" defer></script>
