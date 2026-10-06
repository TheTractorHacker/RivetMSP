<?php
/*
 * Administration > Webhooks > Add / Edit (guided).
 *   webhook_form.php                      step 1: pick a platform (searchable cards by category)
 *   webhook_form.php?destination=<id>     step 2: the form for that platform, with its setup guide beside it
 *   webhook_form.php?id=<webhook id>      edit (same form)
 * Posts to admin/post/settings_webhooks.php through admin/post.php (handler chosen by this page's name: post/webhook_form.php).
 */
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_form_lib.php";
require_once "includes/webhook_guide.php";
require_once "../includes/event_picker.php";

use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\EventCatalog;

$wid = intval($_GET['id'] ?? 0);
$existing = null;
if ($wid > 0) {
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $wid LIMIT 1")) ?: null;
    if (!$existing) {
        flash_alert('Webhook not found.', 'error');
        redirect('settings_webhooks.php');
    }
}

$dest_id = $existing ? ((string) $existing['webhook_destination'] !== '' ? (string) $existing['webhook_destination'] : 'generic-json') : trim((string) ($_GET['destination'] ?? ''));
$dest = $dest_id !== '' ? Destinations::get($dest_id) : null;
$legacy_row = $existing && (string) $existing['webhook_destination'] === '';
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['csrf_token'];

// ---------------------------------------------------------------- step 1: choose a platform
if ($dest === null) { ?>
    <div class="card mb-3">
        <div class="card-header py-3 d-flex flex-wrap align-items-center gap-2">
            <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-satellite-dish me-2"></i>Add webhook: choose a platform</h3>
            <a href="webhook_guides.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book me-1"></i>Guides</a>
            <a href="settings_webhooks.php" class="btn btn-light btn-sm">Cancel</a>
        </div>
        <div class="card-body">
            <p class="text-muted">Pick where the events should go. Each platform has a preset: the right body format, the address shape, the authentication it supports and a step-by-step setup guide. Choose <strong>Generic JSON</strong> for anything not listed, or <strong>Custom template</strong> to write the body yourself.</p>
            <div class="wh-search mb-3">
                <i class="fas fa-search wh-search-icon" aria-hidden="true"></i>
                <input type="search" class="form-control" id="wh_platform_search" data-wh-platform-search placeholder="Search platforms (n8n, Slack, ntfy, Telegram...)" aria-label="Search platforms" autocomplete="off">
            </div>
            <div id="wh_platform_empty" class="text-muted py-3" hidden role="status">No platform matches. Use Generic JSON or Custom template for other services.</div>
            <?php foreach (Destinations::byCategory() as $cat => $items) { ?>
                <section class="wh-cat mb-4" data-wh-cat>
                    <h6 class="text-uppercase text-muted small mb-2"><?= $h(Destinations::categoryLabels()[$cat] ?? $cat) ?></h6>
                    <div class="wh-cards">
                        <?php foreach ($items as $d) { ?>
                            <a class="wh-card" href="webhook_form.php?destination=<?= $h(urlencode($d->id)) ?>" data-wh-card data-id="<?= $h($d->id) ?>"
                               data-search="<?= $h(strtolower($d->id . ' ' . $d->name . ' ' . $d->description . ' ' . $d->format . ' ' . $cat)) ?>">
                                <span class="wh-card-icon" aria-hidden="true"><i class="fas <?= $h(rivetWebhookIcon($d->id, $d->category)) ?>"></i></span>
                                <span class="wh-card-body">
                                    <span class="wh-card-name"><?= $h($d->name) ?></span>
                                    <span class="wh-card-desc"><?= $h($d->description) ?></span>
                                </span>
                            </a>
                        <?php } ?>
                    </div>
                </section>
            <?php } ?>
        </div>
    </div>
    <?php
    require_once "../includes/footer.php";
    return;
}

// ---------------------------------------------------------------- step 2: the form
$draft = $_SESSION['webhook_form_draft'] ?? null;
unset($_SESSION['webhook_form_draft']);
if ($draft && (($draft['webhook_destination'] ?? '') !== $dest->id || (int) ($draft['webhook_id'] ?? 0) !== $wid)) {
    $draft = null;
}

$extra = $existing ? (json_decode((string) $existing['webhook_extra'], true) ?: []) : [];
$auth_old = $existing ? (json_decode(decryptSetting((string) $existing['webhook_auth_enc']), true) ?: []) : [];
$stored_url = $existing ? decryptSetting((string) $existing['webhook_url']) : '';
$url_hidden = $existing && str_starts_with((string) $existing['webhook_url'], 'ENC:');
$has_secret = $existing && decryptSetting((string) $existing['webhook_secret']) !== '';

$v_name = $draft['webhook_name'] ?? ($existing['webhook_name'] ?? $dest->name);
$v_enabled = $draft ? isset($draft['webhook_enabled']) : (!$existing || (int) $existing['webhook_enabled'] === 1);
$v_events = $draft['webhook_events'] ?? ($existing ? array_values(array_filter(array_map('trim', explode(',', (string) $existing['webhook_events'])))) : []);
$v_fields = $draft['webhook_field'] ?? (is_array($extra['fields'] ?? null) ? $extra['fields'] : []);
$v_mode = $draft['webhook_auth_mode'] ?? ($existing && $existing['webhook_auth_mode'] !== '' && !$legacy_row ? $existing['webhook_auth_mode'] : $dest->defaultAuth);
if (!in_array($v_mode, $dest->authModes, true)) {
    $v_mode = $dest->defaultAuth;
}
$v_method = $draft['webhook_method'] ?? ($existing['webhook_method'] ?? $dest->method);
$v_template = $draft['webhook_template'] ?? ($existing ? (string) $existing['webhook_template'] : '');
if ($v_template === '' && $dest->id === 'custom-template') {
    $v_template = (string) ($dest->formatOptions['template'] ?? '');
}
$v_enc = $draft['webhook_template_encoding'] ?? ($extra['template_encoding'] ?? ($dest->formatOptions['template_encoding'] ?? 'json'));
$v_user = $draft['auth_username'] ?? ($auth_old['username'] ?? '');
$v_hname = $draft['auth_header_name'] ?? ($auth_old['header_name'] ?? ($dest->defaultAuthHeader ?? ''));
$has_url_fields = false;
foreach ($dest->extraFields as $f) {
    $has_url_fields = $has_url_fields || $f->target === 'url';
}
$url_value = '';
if ($existing && !$url_hidden) {
    $url_value = $stored_url;
} elseif (!$existing && $has_url_fields) {
    $url_value = $dest->urlHint;
}
$other_events = webhook_event_groups()['Other events seen on this server'] ?? [];
$mode_labels = ['none' => 'None', 'hmac' => 'Signature only (HMAC)', 'bearer' => 'Bearer token', 'basic' => 'Basic (username and password)', 'header' => 'Custom header'];
$saved = static fn (bool $has) => $has ? '(saved - leave blank to keep)' : '';
$ticket_fields = EventCatalog::get('ticket.created')->payloadFields ?? [];
$title = $existing ? 'Edit webhook' : 'Add webhook';
?>
<style nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    .wh-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
    @media (min-width: 1200px) { .wh-layout { grid-template-columns: minmax(0, 7fr) minmax(0, 5fr); align-items: start; } .wh-guide-card { position: sticky; top: 1rem; max-height: calc(100vh - 2rem); overflow-y: auto; } }
</style>

<nav class="mb-2 small"><a href="settings_webhooks.php"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Webhooks</a><?php if (!$existing) { ?> <span class="text-muted mx-1">/</span> <a href="webhook_form.php">Choose another platform</a><?php } ?></nav>

<div class="wh-layout">
<form action="post.php" method="post" autocomplete="off" id="webhook_form" data-wh-form data-tools-url="webhook_tools.php" data-webhook-id="<?= $wid ?>" data-destination="<?= $h($dest->id) ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="webhook_destination" value="<?= $h($dest->id) ?>">
    <?php if ($existing) { ?><input type="hidden" name="webhook_id" value="<?= $wid ?>"><?php } ?>

    <div class="card mb-3">
        <div class="card-header py-3 d-flex flex-wrap align-items-center gap-2">
            <span class="wh-card-icon" aria-hidden="true"><i class="fas <?= $h(rivetWebhookIcon($dest->id, $dest->category)) ?>"></i></span>
            <h3 class="card-title me-auto mb-0"><?= $h($title) ?>: <?= $h($dest->name) ?></h3>
            <a href="webhook_guides.php#<?= $h($dest->id) ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener"><i class="fas fa-book me-1"></i>Full guide</a>
        </div>
        <div class="card-body">
            <?php if ($legacy_row) { ?><div class="alert alert-info py-2 small">This webhook was created before platform presets existed and sends the plain JSON envelope. Saving it here keeps that behaviour; you can add authentication or a platform-shaped body by choosing another platform when you re-create it.</div><?php } ?>

            <div class="mb-3">
                <label class="form-label" for="webhook_name">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="webhook_name" name="webhook_name" required maxlength="200" value="<?= $h($v_name) ?>" placeholder="e.g. <?= $h($dest->name) ?> alerts">
            </div>

            <div class="mb-3">
                <label class="form-label" for="webhook_url">Endpoint URL <?php if (!$existing) { ?><span class="text-danger">*</span><?php } ?></label>
                <input type="text" inputmode="url" class="form-control font-monospace" id="webhook_url" name="webhook_url" maxlength="2048" <?= $existing ? '' : 'required' ?>
                       value="<?= $h($url_value) ?>" placeholder="<?= $h($existing && $url_hidden ? 'saved: ' . rivetWebhookUrlMasked($stored_url) . ' (leave blank to keep)' : $dest->urlHint) ?>"
                       data-wh-url-hint="<?= $h($dest->urlHint) ?>">
                <div class="form-text">
                    <?php if ($existing && $url_hidden) { ?>The address is stored encrypted and is not shown again. Leave it blank to keep it, or type the whole address to replace it.
                    <?php } else { ?>Looks like <code><?= $h($dest->urlHint) ?></code>.<?php } ?>
                    <?php if ($has_url_fields) { ?> Parts in <code>{braces}</code> are filled from the fields below.<?php } ?>
                    Private addresses must be listed under <a href="settings_webhooks.php#internal-networks">Internal network access</a> first.
                </div>
            </div>

            <?php foreach ($dest->extraFields as $f) {
                $fid = 'webhook_field_' . $f->name;
                $isUrl = $f->target === 'url';
                $val = $isUrl ? '' : ($v_fields[$f->name] ?? '');
                $required = $f->required && ($isUrl ? !$existing : true); ?>
                <div class="mb-3">
                    <label class="form-label" for="<?= $h($fid) ?>"><?= $h($f->label) ?><?= $f->required ? ' <span class="text-danger">*</span>' : '' ?><?= $isUrl ? ' <small class="text-muted">(goes into the address)</small>' : '' ?></label>
                    <input type="<?= $f->type === 'secret' ? 'password' : ($f->type === 'number' ? 'number' : 'text') ?>" class="form-control<?= $f->type === 'number' ? '' : ' font-monospace' ?>" id="<?= $h($fid) ?>" name="webhook_field[<?= $h($f->name) ?>]"
                           value="<?= $h($val) ?>" placeholder="<?= $h($existing && $isUrl ? '(only needed when you re-enter the address)' : $f->example) ?>" <?= $required ? 'required' : '' ?>
                           <?= $f->type === 'number' ? 'min="1" max="5"' : '' ?> maxlength="300" autocomplete="off">
                    <div class="form-text"><?= $h($f->help) ?></div>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-key me-2"></i>Authentication and signing</h4></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="webhook_auth_mode">How should <?= $h($dest->name) ?> recognise us?</label>
                <select class="form-select" id="webhook_auth_mode" name="webhook_auth_mode" data-wh-auth-mode>
                    <?php foreach ($dest->authModes as $m) { ?><option value="<?= $h($m) ?>" <?= $v_mode === $m ? 'selected' : '' ?>><?= $h($mode_labels[$m] ?? $m) ?></option><?php } ?>
                </select>
                <?php if (count($dest->authModes) === 1 && $dest->authModes[0] === 'none') { ?><div class="form-text"><?= $h($dest->name) ?> needs no authentication header: the address itself is the credential.</div><?php } ?>
            </div>
            <div data-wh-auth-panel="none" class="text-muted small mb-3">No extra header is added. Our signature headers are still sent.</div>
            <div data-wh-auth-panel="hmac" class="text-muted small mb-3">The receiver checks our <code>X-Rivet-Signature-V2</code> header with the signing secret below.</div>
            <div data-wh-auth-panel="bearer" class="mb-3">
                <label class="form-label" for="auth_token">Bearer token</label>
                <input type="password" class="form-control font-monospace" id="auth_token" name="auth_token" autocomplete="new-password" maxlength="2048"
                       placeholder="<?= $h($saved($existing && ($existing['webhook_auth_mode'] ?? '') === 'bearer' && ($auth_old['token'] ?? '') !== '')) ?>">
                <div class="form-text">Sent as <code>Authorization: Bearer &lt;token&gt;</code>. Stored encrypted and never shown again.</div>
            </div>
            <div data-wh-auth-panel="basic" class="mb-3">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label" for="auth_username">Username</label><input type="text" class="form-control" id="auth_username" name="auth_username" autocomplete="off" maxlength="256" value="<?= $h($v_user) ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="auth_password">Password</label><input type="password" class="form-control" id="auth_password" name="auth_password" autocomplete="new-password" maxlength="1024"
                           placeholder="<?= $h($saved($existing && ($existing['webhook_auth_mode'] ?? '') === 'basic' && ($auth_old['password'] ?? '') !== '')) ?>"></div>
                </div>
                <div class="form-text">Sent as <code>Authorization: Basic ...</code>. Stored encrypted and never shown again.</div>
            </div>
            <div data-wh-auth-panel="header" class="mb-3">
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label" for="auth_header_name">Header name</label><input type="text" class="form-control font-monospace" id="auth_header_name" name="auth_header_name" maxlength="64" value="<?= $h($v_hname) ?>" placeholder="<?= $h($dest->defaultAuthHeader ?? 'X-Api-Key') ?>"></div>
                    <div class="col-md-7"><label class="form-label" for="auth_header_value">Header value</label><input type="password" class="form-control font-monospace" id="auth_header_value" name="auth_header_value" autocomplete="new-password" maxlength="2048"
                           placeholder="<?= $h($saved($existing && ($existing['webhook_auth_mode'] ?? '') === 'header' && ($auth_old['header_value'] ?? '') !== '')) ?>"></div>
                </div>
                <div class="form-text">Names such as Host, Content-Length, Content-Type and anything starting with <code>X-Rivet-</code> or ending in <code>-Signature</code> are reserved. The value is stored encrypted and never shown again.</div>
            </div>

            <div class="mb-0">
                <label class="form-label" for="webhook_secret">Signing secret <small class="text-muted">(HMAC-SHA256; the receiver uses it to verify we sent the request)</small></label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="webhook_secret" name="webhook_secret" autocomplete="off" maxlength="255" placeholder="<?= $h($has_secret ? '(saved - leave blank to keep)' : 'a long random string') ?>">
                    <button type="button" class="btn btn-outline-secondary" data-wh-generate="#webhook_secret"><i class="fas fa-random me-1" aria-hidden="true"></i>Generate</button>
                </div>
                <div class="form-text">Copy it into the receiver (the verification snippets in the guide use it). Stored encrypted; leave blank on edit to keep the current one.</div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-file-code me-2"></i>Payload</h4></div>
        <div class="card-body">
            <?php if ($dest->id === 'custom-template') { ?>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label" for="webhook_method">Method</label>
                        <select class="form-select" id="webhook_method" name="webhook_method"><?php foreach (['POST', 'PUT'] as $m) { ?><option <?= $v_method === $m ? 'selected' : '' ?>><?= $m ?></option><?php } ?></select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="webhook_template_encoding">Body encoding</label>
                        <select class="form-select" id="webhook_template_encoding" name="webhook_template_encoding" data-wh-template-encoding>
                            <?php foreach (['json' => 'JSON', 'text' => 'Plain text', 'form' => 'Form (urlencoded)'] as $k => $l) { ?><option value="<?= $k ?>" <?= $v_enc === $k ? 'selected' : '' ?>><?= $l ?></option><?php } ?>
                        </select>
                    </div>
                </div>
                <label class="form-label" for="webhook_template">Body template <span class="text-danger">*</span></label>
                <textarea class="form-control font-monospace" id="webhook_template" name="webhook_template" rows="7" maxlength="8192" spellcheck="false" data-wh-template><?= $h($v_template) ?></textarea>
                <div class="form-text" id="webhook_template_status" data-wh-template-status role="status" aria-live="polite"></div>
                <details class="mt-2 small">
                    <summary>Placeholder cheat-sheet (click one to insert it)</summary>
                    <div class="mt-2">
                        <div class="mb-1"><strong>Event</strong>:
                            <?php foreach (['event', 'timestamp', 'summary.title', 'summary.summary', 'summary.url', 'summary.severity', 'summary.actor', 'summary.client'] as $ph) { ?><button type="button" class="wh-ph" data-wh-insert="{{<?= $h($ph) ?>}}"><?= $h($ph) ?></button><?php } ?></div>
                        <div class="mb-1"><strong>Ticket data</strong> (<code>data.*</code>):
                            <?php foreach ($ticket_fields as $pf) { ?><button type="button" class="wh-ph" title="<?= $h($pf['description']) ?>" data-wh-insert="{{data.<?= $h($pf['path']) ?>}}">data.<?= $h($pf['path']) ?></button><?php } ?></div>
                        <div class="text-muted">Filters: <code>|default:"x"</code> <code>|upper</code> <code>|lower</code> <code>|trim</code> <code>|truncate:80</code> <code>|json</code> (numbers, lists and objects, no quotes; must be last). In a JSON body a plain <code>{{x}}</code> is escaped for use inside quotes. No loops, conditions or code; limits: 8 KB template, 64 KB output.</div>
                    </div>
                </details>
            <?php } else { ?>
                <p class="mb-1">Body format: <strong><?= $h($dest->format) ?></strong>, sent with <code><?= $h($dest->method) ?></code>. <?= $h($dest->name) ?> expects exactly this shape, so there is nothing to configure.</p>
                <p class="text-muted small mb-0">Use <em>Preview payload</em> below to see the exact body and headers for a sample event.</p>
            <?php } ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Events <span class="text-danger">*</span></h4></div>
        <div class="card-body">
            <p class="text-muted small">Search and tick what should be sent. Ticking everything in a family saves a pattern such as <code>ticket.*</code>, so events added later are included too.</p>
            <?php eventPickerField('webhook_events[]', $v_events, ['id' => 'webhook_events_picker', 'other' => $other_events]); ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" id="webhook_enabled" name="webhook_enabled" value="1" <?= $v_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="webhook_enabled">Enabled</label>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" name="<?= $existing ? 'edit_webhook' : 'add_webhook' ?>" class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
                <button type="button" class="btn btn-outline-primary" data-wh-action="test"><i class="fas fa-paper-plane me-1"></i>Send test</button>
                <button type="button" class="btn btn-outline-secondary" data-wh-action="preview"><i class="fas fa-eye me-1"></i>Preview payload</button>
                <a href="settings_webhooks.php" class="btn btn-light ms-auto">Cancel</a>
            </div>
            <div id="wh_result" class="wh-result mt-3" data-wh-result role="status" aria-live="polite" hidden></div>
        </div>
    </div>
</form>

<aside class="card wh-guide-card" aria-label="Setup guide for <?= $h($dest->name) ?>">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-book me-2"></i>Setup guide: <?= $h($dest->name) ?></h4></div>
    <div class="card-body">
        <p class="text-muted small"><?= $h($dest->description) ?></p>
        <?php webhookGuideRender($dest, false, 'form'); ?>
    </div>
</aside>
</div>

<?php require_once "../includes/footer.php"; ?>
