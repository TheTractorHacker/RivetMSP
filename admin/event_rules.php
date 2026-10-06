<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";
require_once "../includes/event_rules_lib.php";
require_once "includes/webhook_events.php";
require_once "../includes/event_picker.php";

use RivetCore\Automation\AutomationRuleStore;
use RivetCore\Webhooks\EventCatalog;
use RivetMSP\Automation\RuleRecipes;
use RivetMSP\Automation\RuleSummary;

$csrf = $_SESSION['csrf_token'];
$ready = class_exists(AutomationRuleStore::class) && rivetTableExists($mysqli, 'automation_rules');
$store = $ready ? new AutomationRuleStore(rivetCoreDb($mysqli)) : null;
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

// One hue per event group: badges are tinted from it in both themes (css/itflow_custom.css, .er-badge).
$group_hues = ['tickets' => 210, 'sla' => 25, 'approvals' => 270, 'workflows' => 160, 'itil' => 190, 'assets' => 120, 'clients' => 300, 'billing' => 45, 'security' => 0, 'audit' => 240, 'system' => 90, 'automation' => 330, 'integrations' => 180, 'training' => 60];
$group_labels = [];
foreach (EventCatalog::groups() as $k => $g) {
    $group_labels[$k] = $g['label'];
}
$actions = eventRulesActionMeta();

$names = $ready ? eventRulesNames($mysqli) : ['users' => [], 'clients' => []];
$rules = $ready ? $store->all() : [];
$stats = $ready ? eventRulesStats($mysqli) : [];

// What the page is showing: the list, or the editor for ?edit=ID, ?new=1 or ?recipe=slug (a recipe only prefills the form).
$mode = 'list';
$form = ['rule_id' => 0, 'name' => '', 'event' => '', 'conditions' => [], 'type' => 'notify_user', 'cfg' => [], 'enabled' => true, 'recipe' => ''];
$not_found = false;
if ($ready && isset($_GET['edit'])) {
    $edit = $store->find(intval($_GET['edit']));
    if ($edit) {
        $mode = 'edit';
        $form = ['rule_id' => (int) $edit['rule_id'], 'name' => $edit['name'], 'event' => $edit['trigger_event'], 'conditions' => RuleSummary::conditions($edit), 'type' => $edit['action_type'], 'cfg' => RuleSummary::config($edit), 'enabled' => (bool) $edit['is_enabled'], 'recipe' => ''];
    } else {
        $not_found = true;
    }
} elseif ($ready && isset($_GET['recipe']) && ($rc = RuleRecipes::get((string) $_GET['recipe']))) {
    $mode = 'edit';
    $form = ['rule_id' => 0, 'name' => $rc['name'], 'event' => $rc['trigger_event'], 'conditions' => $rc['conditions'], 'type' => $rc['action_type'], 'cfg' => $rc['config'], 'enabled' => true, 'recipe' => (string) $_GET['recipe']];
} elseif ($ready && isset($_GET['new'])) {
    $mode = 'edit';
}
$cfg = $form['cfg'];

$notice = '';
$highlight = intval($_GET['rule'] ?? 0);
if (($_GET['done'] ?? '') === 'saved') {
    $notice = 'Rule saved.';
} elseif (($_GET['done'] ?? '') === 'copied') {
    $notice = 'Rule copied. The copy is switched off until you turn it on.';
}

$rows = [];
$total_enabled = 0;
$total_day = 0;
$total_day_failed = 0;
foreach ($rules as $r) {
    $id = (int) $r['rule_id'];
    $st = $stats[$id] ?? null;
    $group = eventRulesGroupOf((string) $r['trigger_event']);
    $rows[] = ['r' => $r, 'id' => $id, 'st' => $st, 'group' => $group, 'summary' => RuleSummary::describe($r + ['names' => $names]), 'label' => RuleSummary::eventLabel((string) $r['trigger_event'])];
    $total_enabled += $r['is_enabled'] ? 1 : 0;
    $total_day += $st['day'] ?? 0;
    $total_day_failed += $st['day_failed'] ?? 0;
}
$present_groups = array_values(array_unique(array_filter(array_column($rows, 'group'))));
$present_actions = array_values(array_unique(array_column($rules, 'action_type')));

$runs = [];
if ($ready) {
    $res = mysqli_query($mysqli, "SELECT created_at, action, summary FROM audit_events WHERE event_type = 'automation.rule_fired' ORDER BY audit_id DESC LIMIT 25");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $runs[] = $r;
    }
}

$page_data = [
    'csrf' => $csrf,
    'tools' => 'event_rules_tools.php',
    'mode' => $mode,
    'ruleId' => $form['rule_id'],
    'notice' => $notice,
    'highlight' => $highlight,
    'fields' => $ready ? eventRulesFieldCatalog() : ['sets' => [], 'events' => []],
    'lists' => $ready ? eventRulesValueLists($mysqli, $names) : [],
    'actions' => $actions,
    'urlRule' => $ready ? rivetWebhookRuleText($mysqli) : '',
    'groups' => $group_labels,
];
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex flex-wrap gap-2 align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Event rules</h3>
        <?php if ($ready && $mode === 'list') { ?>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-er-recipes-toggle aria-expanded="<?= $rows ? 'false' : 'true' ?>" aria-controls="er-recipes"><i class="fas fa-fw fa-magic me-1"></i>Recipes</button>
                <a class="btn btn-primary btn-sm" href="event_rules.php?new=1"><i class="fas fa-fw fa-plus me-1"></i>New rule</a>
            </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted mb-1">When something happens in <?= $h(APP_NAME) ?> (a ticket is created, a login fails, a setting changes), a rule can create a ticket, notify technicians or call another service. This is the same stream of events webhooks can subscribe to.</p>
        <p class="text-muted small mb-0">Rules run in the background through the job queue, so a slow or failing action never slows what a person is doing; failures are retried and shown on the <a href="job_queue.php">Job queue</a> page. Think of every rule as <strong>When</strong> (the event) &rarr; <strong>If</strong> (optional conditions, all must match) &rarr; <strong>Then</strong> (the action).</p>
    </div>
</div>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Update) to turn on event rules.</div>
<?php } else { ?>

<?php if ($not_found) { ?><div class="alert alert-warning" role="alert">That rule no longer exists.</div><?php } ?>
<div class="er-toasts" aria-live="polite" data-er-toasts></div>
<script type="application/json" id="er-data"><?= json_encode($page_data, $jsonFlags) ?></script>

<!-- ============================== LIST ============================== -->
<section id="er-list" <?= $mode === 'list' ? '' : 'hidden' ?> aria-label="Your rules">
    <div class="er-strip" role="group" aria-label="Summary">
        <div class="er-stat"><span class="er-stat-n" data-er-stat="total"><?= count($rows) ?></span><span class="er-stat-l">rules</span></div>
        <div class="er-stat"><span class="er-stat-n" data-er-stat="enabled"><?= $total_enabled ?></span><span class="er-stat-l">enabled</span></div>
        <div class="er-stat"><span class="er-stat-n"><?= $total_day ?></span><span class="er-stat-l">fired in the last 24 h<?= $total_day_failed ? ' (' . $total_day_failed . ' failed)' : '' ?></span></div>
    </div>

    <?php if ($rows) { ?>
    <div class="er-toolbar card card-body py-2 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small mb-0" for="er-search">Search</label>
                <input type="search" id="er-search" class="form-control form-control-sm" placeholder="Name, event or action" autocomplete="off" data-er-search>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="er-f-group">Event group</label>
                <select id="er-f-group" class="form-select form-select-sm" data-er-filter="group">
                    <option value="">All groups</option>
                    <?php foreach ($present_groups as $g) { ?><option value="<?= $h($g) ?>"><?= $h($group_labels[$g] ?? $g) ?></option><?php } ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="er-f-action">Action</label>
                <select id="er-f-action" class="form-select form-select-sm" data-er-filter="action">
                    <option value="">All actions</option>
                    <?php foreach ($present_actions as $a) { ?><option value="<?= $h($a) ?>"><?= $h($actions[$a]['label'] ?? $a) ?></option><?php } ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="er-f-status">Status</label>
                <select id="er-f-status" class="form-select form-select-sm" data-er-filter="enabled">
                    <option value="">On and off</option>
                    <option value="1">Enabled</option>
                    <option value="0">Disabled</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-0" for="er-sort">Sort by</label>
                <select id="er-sort" class="form-select form-select-sm" data-er-sort>
                    <option value="name">Name</option>
                    <option value="trigger">Event</option>
                    <option value="last">Last fired</option>
                </select>
            </div>
        </div>
        <div class="small text-muted mt-2" aria-live="polite" data-er-count></div>
    </div>

    <div class="er-rules" data-er-rules>
        <?php foreach ($rows as $row) {
            $r = $row['r']; $id = $row['id']; $st = $row['st']; $type = (string) $r['action_type'];
            $meta = $actions[$type] ?? ['label' => $type, 'icon' => 'fa-bolt'];
            $on = (bool) $r['is_enabled'];
            $last = $st ? $st['ago'] : null; ?>
            <article class="er-rule<?= $on ? '' : ' er-off' ?><?= $highlight === $id ? ' er-flash' : '' ?>" data-er-rule="<?= $id ?>"
                     data-name="<?= $h(mb_strtolower($r['name'])) ?>" data-text="<?= $h(mb_strtolower($r['name'] . ' ' . $row['summary'] . ' ' . $r['trigger_event'] . ' ' . $row['label'] . ' ' . ($meta['label'] ?? ''))) ?>"
                     data-group="<?= $h($row['group']) ?>" data-action="<?= $h($type) ?>" data-enabled="<?= $on ? 1 : 0 ?>" data-trigger="<?= $h($r['trigger_event']) ?>" data-last="<?= $last === null ? '' : $last ?>" data-title="<?= $h($r['name']) ?>">
                <div class="er-rule-switch">
                    <label class="er-switch">
                        <input type="checkbox" role="switch" data-er-toggle <?= $on ? 'checked' : '' ?> aria-label="<?= $h($r['name']) ?> is <?= $on ? 'on' : 'off' ?>">
                        <span class="er-switch-track" aria-hidden="true"></span>
                    </label>
                </div>
                <div class="er-rule-body">
                    <div class="er-rule-title">
                        <a href="event_rules.php?edit=<?= $id ?>" class="er-rule-name"><?= $h($r['name']) ?></a>
                        <span class="er-badge" style="--er-h: <?= (int) ($group_hues[$row['group']] ?? 220) ?>" title="<?= $h($r['trigger_event']) ?>"><?= $h($row['label']) ?></span>
                        <span class="er-badge er-badge-action" title="<?= $h($meta['label']) ?>"><i class="fas fa-fw <?= $h($meta['icon']) ?> me-1" aria-hidden="true"></i><?= $h($meta['label']) ?></span>
                        <span class="badge text-bg-secondary er-off-badge" data-er-off-badge <?= $on ? 'hidden' : '' ?>>Off</span>
                    </div>
                    <p class="er-rule-summary"><?= $h($row['summary']) ?></p>
                    <div class="er-rule-meta">
                        <?php if ($st) { ?>
                            <span title="<?= $h($st['last_at']) ?>"><i class="far fa-fw fa-clock" aria-hidden="true"></i> Last fired <?= $h(eventRulesAgo($st['ago'])) ?></span>
                            <span class="<?= $st['last_ok'] ? '' : 'text-danger' ?>"><?= $st['last_ok'] ? 'succeeded' : 'failed' ?></span>
                            <span><?= (int) $st['runs'] ?> run<?= $st['runs'] === 1 ? '' : 's' ?> in total</span>
                        <?php } else { ?>
                            <span class="text-muted"><i class="far fa-fw fa-clock" aria-hidden="true"></i> Has not fired yet</span>
                        <?php } ?>
                    </div>
                </div>
                <div class="er-rule-actions">
                    <a class="btn btn-sm btn-light" href="event_rules.php?edit=<?= $id ?>" title="Edit"><i class="fas fa-fw fa-edit" aria-hidden="true"></i><span class="er-lbl">Edit</span><span class="visually-hidden"> <?= $h($r['name']) ?></span></a>
                    <button type="button" class="btn btn-sm btn-light" data-er-test title="Test (dry run, nothing is executed)"><i class="fas fa-fw fa-flask" aria-hidden="true"></i><span class="er-lbl">Test</span><span class="visually-hidden"> <?= $h($r['name']) ?></span></button>
                    <button type="button" class="btn btn-sm btn-light" data-er-history title="Run history"><i class="fas fa-fw fa-history" aria-hidden="true"></i><span class="er-lbl">History</span><span class="visually-hidden"> <?= $h($r['name']) ?></span></button>
                    <button type="button" class="btn btn-sm btn-light" data-er-duplicate title="Duplicate (the copy is off)"><i class="fas fa-fw fa-copy" aria-hidden="true"></i><span class="er-lbl">Duplicate</span><span class="visually-hidden"> <?= $h($r['name']) ?></span></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-er-delete title="Delete"><i class="fas fa-fw fa-trash" aria-hidden="true"></i><span class="er-lbl">Delete</span><span class="visually-hidden"> <?= $h($r['name']) ?></span></button>
                </div>
            </article>
        <?php } ?>
        <div class="er-nomatch text-center text-muted py-4" data-er-nomatch hidden>No rules match these filters.</div>
    </div>
    <?php } else { ?>
        <div class="er-empty card card-body text-center">
            <div class="er-empty-icon" aria-hidden="true"><i class="fas fa-bolt"></i></div>
            <h4>No rules yet</h4>
            <p class="text-muted mb-3">Pick a recipe below to start from a ready-made rule, or build one from scratch.</p>
            <div><a class="btn btn-primary" href="event_rules.php?new=1"><i class="fas fa-fw fa-plus me-1"></i>New rule</a></div>
        </div>
    <?php } ?>

    <div id="er-recipes" class="er-recipes mt-3" <?= $rows ? 'hidden' : '' ?>>
        <h4 class="h5 mb-1">Recipes</h4>
        <p class="text-muted small mb-2">A recipe fills in the editor for you. Nothing is saved until you press Save, and you can change anything first.</p>
        <div class="er-recipe-grid">
            <?php foreach (RuleRecipes::all() as $key => $rc) { ?>
                <a class="er-recipe" href="event_rules.php?recipe=<?= $h($key) ?>">
                    <span class="er-recipe-icon" aria-hidden="true"><i class="fas fa-fw <?= $h($rc['icon']) ?>"></i></span>
                    <span class="er-recipe-body"><span class="er-recipe-title"><?= $h($rc['title']) ?></span><span class="er-recipe-blurb"><?= $h($rc['blurb']) ?></span></span>
                </a>
            <?php } ?>
        </div>
    </div>

    <?php if ($rows) { ?>
    <details class="card mt-3">
        <summary class="card-header py-2 er-activity-summary">Recent activity (last 25 runs of any rule)</summary>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>When</th><th>Result</th><th>What happened</th></tr></thead>
                <tbody>
                <?php if (!$runs) { ?><tr><td colspan="3" class="text-center text-muted py-3">Nothing has run yet.</td></tr><?php } ?>
                <?php foreach ($runs as $r) { ?>
                    <tr><td class="text-nowrap text-secondary"><?= $h($r['created_at']) ?></td><td><?= $r['action'] === 'ok' ? '<span class="badge text-bg-success">ok</span>' : '<span class="badge text-bg-danger">failed</span>' ?></td><td class="small"><?= $h($r['summary']) ?></td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php } ?>
</section>

<!-- ============================== EDITOR ============================== -->
<form id="er-editor" action="post.php" method="post" autocomplete="off" novalidate <?= $mode === 'edit' ? '' : 'hidden' ?> aria-label="<?= $form['rule_id'] ? 'Edit rule' : 'New rule' ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="rule_id" value="<?= (int) $form['rule_id'] ?>">
    <div class="d-flex align-items-center mb-3 gap-2">
        <a class="btn btn-sm btn-light" href="event_rules.php" data-er-back><i class="fas fa-fw fa-arrow-left me-1" aria-hidden="true"></i>All rules</a>
        <h4 class="mb-0"><?= $form['rule_id'] ? 'Edit rule' : ($form['recipe'] ? 'New rule from a recipe' : 'New rule') ?></h4>
    </div>
    <?php if ($form['recipe']) { ?><div class="alert alert-info py-2 small" role="note">This is a recipe. Nothing is saved until you press Save rule.</div><?php } ?>
    <div class="er-error-summary alert alert-danger" role="alert" tabindex="-1" hidden data-er-errors></div>

    <div class="er-editor-grid">
        <div class="er-steps">
            <section class="er-step" aria-labelledby="er-h-when">
                <header class="er-step-head"><span class="er-num" aria-hidden="true">1</span><div><h5 id="er-h-when" class="mb-0">When</h5><p class="text-muted small mb-0">The event that starts this rule.</p></div></header>
                <div class="er-step-body">
                    <div class="er-event-info" data-er-event-info hidden></div>
                    <details class="er-picker-wrap" data-er-picker-wrap <?= $form['event'] === '' ? 'open' : '' ?>>
                        <summary class="er-picker-summary" data-er-picker-summary><?= $form['event'] === '' ? 'Choose the event' : 'Choose a different event' ?></summary>
                        <?php eventPickerField('trigger_event', $form['event'] !== '' ? [$form['event']] : [], ['mode' => 'single', 'id' => 'trigger_event_picker', 'other' => webhook_event_groups()['Other events seen on this server'] ?? [], 'label' => 'Search for the event that starts this rule']); ?>
                    </details>
                    <div class="er-err" data-er-err="trigger_event" hidden></div>
                </div>
            </section>

            <section class="er-step" aria-labelledby="er-h-if">
                <header class="er-step-head"><span class="er-num" aria-hidden="true">2</span><div><h5 id="er-h-if" class="mb-0">If <span class="text-muted fw-normal small">(optional)</span></h5><p class="text-muted small mb-0">Only run when the event looks like this. All conditions must match.</p></div></header>
                <div class="er-step-body">
                    <div data-er-conds>
                        <?php foreach ($form['conditions'] as $f => $v) { ?>
                            <div class="er-cond-seed" data-field="<?= $h($f) ?>" data-value="<?= $h($v) ?>"></div>
                        <?php } ?>
                    </div>
                    <p class="er-cond-empty text-muted mb-2" data-er-cond-empty hidden></p>
                    <div class="er-err" data-er-err="conditions" hidden></div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-er-add-cond><i class="fas fa-fw fa-plus me-1" aria-hidden="true"></i>Add condition</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-er-check><i class="fas fa-fw fa-flask me-1" aria-hidden="true"></i>Check against a recent event</button>
                    </div>
                    <p class="small text-muted mt-2 mb-0">This edition compares for an exact match ("is"). Every condition must match; there is no "any of" and no nested groups.</p>
                </div>
            </section>

            <section class="er-step" aria-labelledby="er-h-then">
                <header class="er-step-head"><span class="er-num" aria-hidden="true">3</span><div><h5 id="er-h-then" class="mb-0">Then</h5><p class="text-muted small mb-0">What the rule does.</p></div></header>
                <div class="er-step-body">
                    <label class="visually-hidden" for="er-action-search">Search actions</label>
                    <input type="search" id="er-action-search" class="form-control form-control-sm mb-2" placeholder="Search actions" data-er-action-search>
                    <div class="er-action-grid" role="radiogroup" aria-label="Action">
                        <?php foreach ($actions as $k => $a) { ?>
                            <label class="er-action<?= $form['type'] === $k ? ' er-selected' : '' ?>" data-er-action-card data-text="<?= $h(mb_strtolower($a['label'] . ' ' . $a['desc'])) ?>">
                                <input type="radio" name="action_type" value="<?= $h($k) ?>" <?= $form['type'] === $k ? 'checked' : '' ?>>
                                <span class="er-action-icon" aria-hidden="true"><i class="fas fa-fw <?= $h($a['icon']) ?>"></i></span>
                                <span class="er-action-text"><span class="er-action-name"><?= $h($a['label']) ?></span><span class="er-action-desc"><?= $h($a['desc']) ?></span></span>
                            </label>
                        <?php } ?>
                    </div>
                    <div class="er-err" data-er-err="action_type" hidden></div>

                    <div class="er-chips mt-3" data-er-chips hidden>
                        <div class="small fw-bold mb-1">Insert a value from the event <span class="fw-normal text-muted">(click to insert at the cursor)</span></div>
                        <div data-er-chip-list></div>
                    </div>

                    <div class="er-cfg mt-3" data-er-cfg="create_ticket" hidden>
                        <div class="mb-2"><label class="form-label" for="cfg_subject">Ticket subject</label><input class="form-control" id="cfg_subject" name="cfg_subject" maxlength="500" data-er-ph value="<?= $h($cfg['subject'] ?? '') ?>" placeholder="e.g. Failed sign-in: {summary}"><div class="er-err" data-er-err="cfg_subject" hidden></div></div>
                        <div class="mb-2"><label class="form-label" for="cfg_details">Ticket details <span class="text-muted small">(optional)</span></label><textarea class="form-control" id="cfg_details" name="cfg_details" rows="3" maxlength="5000" data-er-ph><?= $h($cfg['details'] ?? '') ?></textarea></div>
                        <div class="row g-2">
                            <div class="col-sm-6"><label class="form-label" for="cfg_priority">Priority</label>
                                <select class="form-select" id="cfg_priority" name="cfg_priority"><?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= ($cfg['priority'] ?? 'Low') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php } ?></select></div>
                            <div class="col-sm-6"><label class="form-label" for="cfg_client_id">Client <span class="text-muted small">(optional)</span></label>
                                <select class="form-select" id="cfg_client_id" name="cfg_client_id"><option value="0">No client</option><?php foreach ($names['clients'] as $cid => $cn) { ?><option value="<?= $cid ?>" <?= (int) ($cfg['client_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= $h($cn) ?></option><?php } ?></select></div>
                        </div>
                        <p class="small text-muted mt-2 mb-0">The ticket is assigned by the normal ticket rules. Priority and client are fixed here; event data cannot change them.</p>
                    </div>
                    <div class="er-cfg mt-3" data-er-cfg="send_webhook" hidden>
                        <div class="mb-2"><label class="form-label" for="cfg_url">Webhook URL</label><input class="form-control" id="cfg_url" name="cfg_url" maxlength="500" placeholder="https://..." value="<?= $h($cfg['url'] ?? '') ?>" aria-describedby="cfg_url_help"><div class="er-err" data-er-err="cfg_url" hidden></div>
                            <div class="form-text" id="cfg_url_help"><?= $h(ucfirst(rivetWebhookRuleText($mysqli))) ?>. Administrators can allow internal networks under Administration &rarr; Webhooks.</div></div>
                        <div class="mb-2"><label class="form-label" for="cfg_secret">Signing secret <span class="text-muted small">(optional)</span></label><input class="form-control" type="password" id="cfg_secret" name="cfg_secret" maxlength="200" autocomplete="new-password" value="<?= $h($cfg['secret'] ?? '') ?>"><div class="form-text">When set, each request carries an HMAC-SHA256 signature in the signature header so the receiver can verify it.</div></div>
                        <p class="small text-muted mb-0">The whole event is sent as JSON. The URL is never filled in from event data.</p>
                    </div>
                    <div class="er-cfg mt-3" data-er-cfg="notify_user" hidden>
                        <div class="mb-2"><label class="form-label" for="cfg_message">Notification message</label><textarea class="form-control" id="cfg_message" name="cfg_message" rows="3" maxlength="1000" data-er-ph><?= $h($cfg['message'] ?? '') ?></textarea><div class="er-err" data-er-err="cfg_message" hidden></div></div>
                        <p class="small text-muted mb-0">Shown to every active technician in their notifications (this edition cannot target a single person).</p>
                    </div>

                    <div class="er-preview mt-3" data-er-preview hidden>
                        <div class="small fw-bold mb-1">Preview with sample data</div>
                        <dl class="er-preview-list mb-0" data-er-preview-list></dl>
                    </div>
                </div>
            </section>

            <section class="er-step" aria-labelledby="er-h-set">
                <header class="er-step-head"><span class="er-num" aria-hidden="true">4</span><div><h5 id="er-h-set" class="mb-0">Settings</h5><p class="text-muted small mb-0">Name the rule and choose whether it is active.</p></div></header>
                <div class="er-step-body">
                    <div class="mb-3"><label class="form-label" for="rule_name">Name</label><input class="form-control" id="rule_name" name="rule_name" maxlength="200" value="<?= $h($form['name']) ?>" placeholder="e.g. Alert the team when a High ticket arrives"><div class="er-err" data-er-err="rule_name" hidden></div>
                        <div class="form-text">Only for you: it appears in the list, the history and the audit trail.</div></div>
                    <label class="er-switch-row"><span class="er-switch"><input type="checkbox" role="switch" name="is_enabled" value="1" id="rule_enabled" <?= $form['enabled'] ? 'checked' : '' ?>><span class="er-switch-track" aria-hidden="true"></span></span><span><strong>Rule is on</strong><span class="d-block small text-muted">A rule that is off keeps its settings but never runs.</span></span></label>
                </div>
            </section>
        </div>

        <aside class="er-summary" aria-label="Rule summary">
            <div class="er-summary-card">
                <div class="small text-uppercase fw-bold text-muted mb-1">Rule summary</div>
                <p class="er-sentence" data-er-sentence aria-live="polite">Choose an event to begin.</p>
                <ul class="er-checks list-unstyled mb-2" data-er-checks aria-label="Validation"></ul>
                <div class="er-state" data-er-state role="status"></div>
            </div>
        </aside>
    </div>

    <div class="er-actionbar">
        <button type="submit" class="btn btn-primary" name="save_event_rule" value="1" data-er-submit="save"><i class="fas fa-fw fa-check me-1" aria-hidden="true"></i>Save rule</button>
        <button type="submit" class="btn btn-outline-primary" name="save_event_rule" value="1" data-er-submit="save_test"><i class="fas fa-fw fa-flask me-1" aria-hidden="true"></i>Save and test</button>
        <a class="btn btn-light" href="event_rules.php" data-er-back>Cancel</a>
    </div>
</form>

<!-- ============================== DRAWER (test + history) ============================== -->
<div class="er-backdrop" data-er-backdrop hidden></div>
<aside class="er-drawer" id="er-drawer" role="dialog" aria-modal="true" aria-labelledby="er-drawer-title" tabindex="-1" hidden>
    <header class="er-drawer-head">
        <h5 class="mb-0" id="er-drawer-title" data-er-drawer-title>Details</h5>
        <button type="button" class="btn-close" aria-label="Close" data-er-drawer-close></button>
    </header>
    <div class="er-drawer-body" data-er-drawer-body></div>
</aside>

<script src="/js/event_rules.js?v=<?= (int) @filemtime(__DIR__ . '/../js/event_rules.js') ?>" defer></script>
<?php } ?>

<?php require_once "../includes/footer.php";
