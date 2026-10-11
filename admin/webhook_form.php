<?php
/*
 * Administration > Webhooks > Add / Edit (guided).
 *   webhook_form.php                                   step 1 of 4: choose a platform (Recently used, Popular, searchable grid with category chips)
 *   webhook_form.php?dest=<id>[&step=connect|events|review]   steps 2-4 (deep-linkable; ?destination=<id> still works)
 *   webhook_form.php?id=<webhook id>[&tab=connection|events|advanced|deliveries]   edit, in tabs
 *   webhook_form.php?id=<id>&created=1[&test=1]        the success screen after creating one
 * One <form> carries every field of every step (the same field names as ever), so the server stays authoritative: the handler
 * admin/post/settings_webhooks.php (reached through admin/post.php and post/webhook_form.php) validates with RivetCore. The steps are only
 * a presentation: js/webhook_form.js shows one pane at a time and asks the server (webhook_url_check.php, webhook_tools.php) before moving on.
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

$dest_id = $existing ? ((string) $existing['webhook_destination'] !== '' ? (string) $existing['webhook_destination'] : 'generic-json') : trim((string) ($_GET['dest'] ?? $_GET['destination'] ?? ''));
$dest = $dest_id !== '' ? Destinations::get($dest_id) : null;
$legacy_row = $existing && (string) $existing['webhook_destination'] === '';
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrf = $_SESSION['csrf_token'];
$css_v = @filemtime(__DIR__ . '/../css/webhook_form.css') ?: time();

/** Brand-tinted platform icon. */
function whfIcon(string $id, string $category, string $extra = ''): string
{
    [$cls, $color] = rivetWebhookBrand($id, $category);

    return '<span class="whf-icon ' . $extra . '" style="--whf-brand:' . htmlspecialchars($color, ENT_QUOTES) . '" aria-hidden="true"><i class="' . htmlspecialchars($cls, ENT_QUOTES) . '"></i></span>';
}

/** The 4-step progress header. $current: platform | connect | events | review. */
function whfStepper(string $current, ?string $destId): void
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $steps = ['platform' => 'Platform', 'connect' => 'Connect', 'events' => 'Events', 'review' => 'Review & test'];
    $keys = array_keys($steps);
    $idx = array_search($current, $keys, true);
    ?>
    <nav class="whf-stepper" aria-label="Add webhook progress" data-whf-stepper>
        <div class="whf-progress" role="progressbar" aria-valuemin="1" aria-valuemax="4" aria-valuenow="<?= $idx + 1 ?>" aria-label="Step <?= $idx + 1 ?> of 4"><span class="whf-progress-bar whf-p<?= $idx + 1 ?>"></span></div>
        <ol class="whf-steps">
            <?php foreach ($steps as $k => $label) {
                $i = array_search($k, $keys, true);
                $cls = $i < $idx ? 'is-done' : ($i === $idx ? 'is-current' : '');
                ?>
                <li class="whf-step <?= $cls ?>" data-whf-stepper-item="<?= $k ?>"<?= $i === $idx ? ' aria-current="step"' : '' ?>>
                    <?php if ($k === 'platform' && $destId !== null) { ?>
                        <a href="webhook_form.php" class="whf-step-link" data-whf-change-platform><span class="whf-num"><i class="fas fa-check" aria-hidden="true"></i></span><span class="whf-lbl"><?= $h($label) ?></span></a>
                    <?php } else { ?>
                        <button type="button" class="whf-step-link" data-whf-goto="<?= $k ?>" <?= $i < $idx ? '' : 'disabled' ?>><span class="whf-num"><?= $i < $idx ? '<i class="fas fa-check" aria-hidden="true"></i>' : $i + 1 ?></span><span class="whf-lbl"><?= $h($label) ?></span></button>
                    <?php } ?>
                </li>
            <?php } ?>
        </ol>
    </nav>
    <?php
}

/** Password-style input with show/hide, copy and optional Generate. */
function whfSecret(string $id, string $name, string $placeholder, array $o = []): void
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="input-group whf-secret">
        <input type="password" class="form-control font-monospace" id="<?= $h($id) ?>" name="<?= $h($name) ?>" autocomplete="new-password" maxlength="<?= (int) ($o['maxlength'] ?? 2048) ?>"
               value="<?= $h($o['value'] ?? '') ?>" placeholder="<?= $h($placeholder) ?>" <?= !empty($o['required']) ? 'required' : '' ?> <?= !empty($o['attrs']) ? $o['attrs'] : '' ?>>
        <button type="button" class="btn btn-outline-secondary" data-whf-reveal="#<?= $h($id) ?>" aria-label="Show or hide the value" title="Show / hide"><i class="fas fa-eye" aria-hidden="true"></i></button>
        <button type="button" class="btn btn-outline-secondary" data-whf-copyval="#<?= $h($id) ?>" aria-label="Copy the value" title="Copy"><i class="fas fa-copy" aria-hidden="true"></i></button>
        <?php if (!empty($o['generate'])) { ?><button type="button" class="btn btn-outline-secondary" data-wh-generate="#<?= $h($id) ?>"><i class="fas fa-random me-1" aria-hidden="true"></i>Generate secret</button><?php } ?>
    </div>
    <?php
}

// ================================================================ the success screen after creating
if ($existing && isset($_GET['created'])) {
    $ex_dest = Destinations::get($dest_id);
    $ex_host = rivetWebhookUrlMasked(decryptSetting((string) $existing['webhook_url']));
    $ex_sum = rivetWebhookEventsSummary((string) $existing['webhook_events']);
    ?>
    <link rel="stylesheet" href="/css/webhook_form.css?v=<?= $css_v ?>">
    <div class="whf" data-whf-success data-tools-url="webhook_tools.php" data-webhook-id="<?= $wid ?>" data-autotest="<?= isset($_GET['test']) ? '1' : '0' ?>">
        <div class="whf-card-panel whf-success text-center">
            <div class="whf-success-mark" aria-hidden="true"><i class="fas fa-check"></i></div>
            <h3 class="mb-1">Webhook created</h3>
            <p class="text-muted mb-3"><strong><?= $h($existing['webhook_name']) ?></strong> will send <strong><?= $h($ex_sum['label']) ?></strong><?= $ex_sum['count'] && !preg_match('/^\d+ events?$/', $ex_sum['label']) ? ' (' . $ex_sum['count'] . ' event' . ($ex_sum['count'] === 1 ? '' : 's') . ')' : '' ?> to <code><?= $h($ex_host) ?></code><?= (int) $existing['webhook_enabled'] ? '' : ' once you enable it' ?>.</p>
            <div class="wh-result whf-test-result" data-wh-result role="status" aria-live="polite" <?= isset($_GET['test']) ? '' : 'hidden' ?>><span class="text-muted"><i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>Sending a test event...</span></div>
            <div class="whf-actions mt-3">
                <button type="button" class="btn btn-primary" data-whf-test-saved="<?= $wid ?>"><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test again</button>
                <a class="btn btn-outline-secondary" href="webhook_form.php"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add another</a>
                <a class="btn btn-outline-secondary" href="webhook_form.php?id=<?= $wid ?>&tab=deliveries"><i class="fas fa-stream me-1" aria-hidden="true"></i>View deliveries</a>
                <a class="btn btn-outline-secondary" href="webhook_guides.php#<?= $h($dest_id) ?>"><i class="fas fa-book me-1" aria-hidden="true"></i>Open guide</a>
            </div>
            <div class="mt-3 small"><a href="webhook_form.php?id=<?= $wid ?>">Edit settings</a> <span class="text-muted mx-1">/</span> <a href="settings_webhooks.php">Back to all webhooks</a></div>
        </div>
    </div>
    <?php
    require_once "../includes/footer.php";
    return;
}

// ================================================================ step 1: choose a platform
if ($dest === null) {
    $labels = Destinations::categoryLabels();
    $by_cat = Destinations::byCategory();
    $popular = array_values(array_filter(array_map(static fn ($pid) => Destinations::get($pid), RIVET_WEBHOOK_POPULAR)));
    $card = static function ($d, string $attr) use ($h, $labels) {
        $cat = $d->category;
        ?>
        <a class="whf-card" href="webhook_form.php?dest=<?= $h(urlencode($d->id)) ?>" <?= $attr ?> data-id="<?= $h($d->id) ?>" data-cat="<?= $h($cat) ?>"
           data-search="<?= $h(strtolower($d->id . ' ' . $d->name . ' ' . $d->description . ' ' . $d->format . ' ' . ($labels[$cat] ?? $cat))) ?>">
            <?= whfIcon($d->id, $d->category) ?>
            <span class="whf-card-body">
                <span class="whf-card-name"><?= $h($d->name) ?></span>
                <span class="whf-card-desc"><?= $h($d->description) ?></span>
            </span>
        </a>
        <?php
    };
    ?>
    <link rel="stylesheet" href="/css/webhook_form.css?v=<?= $css_v ?>">
    <div class="whf" data-whf-chooser>
        <div class="whf-head">
            <h3 class="whf-title"><i class="fas fa-fw fa-satellite-dish me-2" aria-hidden="true"></i>Add webhook</h3>
            <a href="webhook_guides.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book me-1" aria-hidden="true"></i>Guides</a>
            <a href="settings_webhooks.php" class="btn btn-light btn-sm">Cancel</a>
        </div>
        <?php whfStepper('platform', null); ?>
        <div class="whf-card-panel">
            <h4 class="whf-h">Where should the events go?</h4>
            <p class="text-muted small mb-3">Each platform has a preset: the right body format, address shape, authentication and a setup guide. Pick <strong>Generic JSON</strong> for anything not listed, or <strong>Custom template</strong> to write the body yourself.</p>
            <div class="whf-toolbar">
                <div class="wh-search">
                    <i class="fas fa-search wh-search-icon" aria-hidden="true"></i>
                    <input type="search" class="form-control" id="wh_platform_search" data-wh-platform-search placeholder="Search platforms (n8n, Slack, ntfy, Telegram...)" aria-label="Search platforms" autocomplete="off" autofocus>
                </div>
                <div class="whf-chips" role="group" aria-label="Filter by category">
                    <button type="button" class="whf-chip is-on" data-whf-cat="" aria-pressed="true">All</button>
                    <?php foreach ($by_cat as $cat => $items) { ?><button type="button" class="whf-chip" data-whf-cat="<?= $h($cat) ?>" aria-pressed="false"><?= $h($labels[$cat] ?? $cat) ?></button><?php } ?>
                </div>
            </div>
            <section class="whf-row" data-whf-recent hidden aria-label="Recently used">
                <h5 class="whf-row-title"><i class="fas fa-history me-1" aria-hidden="true"></i>Recently used</h5>
                <div class="whf-cards" data-whf-recent-cards></div>
            </section>
            <section class="whf-row" data-whf-popular aria-label="Popular">
                <h5 class="whf-row-title"><i class="fas fa-star me-1" aria-hidden="true"></i>Popular</h5>
                <div class="whf-cards">
                    <?php foreach ($popular as $d) { $card($d, 'data-wh-pop'); } ?>
                </div>
            </section>
            <div id="wh_platform_empty" class="text-muted py-3" hidden role="status">No platform matches. Use Generic JSON or Custom template for other services.</div>
            <?php foreach ($by_cat as $cat => $items) { ?>
                <section class="whf-row" data-wh-cat data-cat="<?= $h($cat) ?>">
                    <h5 class="whf-row-title"><?= $h($labels[$cat] ?? $cat) ?></h5>
                    <div class="whf-cards">
                        <?php foreach ($items as $d) { $card($d, 'data-wh-card'); } ?>
                    </div>
                </section>
            <?php } ?>
        </div>
    </div>
    <?php
    require_once "../includes/footer.php";
    return;
}

// ================================================================ steps 2-4 (create) / tabs (edit)
$draft = $_SESSION['webhook_form_draft'] ?? null;
unset($_SESSION['webhook_form_draft']);
if ($draft && (($draft['webhook_destination'] ?? '') !== $dest->id || (int) ($draft['webhook_id'] ?? 0) !== $wid)) {
    $draft = null;
}

$extra = $existing ? (json_decode((string) $existing['webhook_extra'], true) ?: []) : [];
$auth_old = $existing ? (json_decode(decryptSetting((string) $existing['webhook_auth_enc']), true) ?: []) : [];
$stored_url = $existing ? decryptSetting((string) $existing['webhook_url']) : '';
$url_hidden = $existing && rivetWebhookValueSealed((string) $existing['webhook_url']);
$has_secret = $existing && decryptSetting((string) $existing['webhook_secret']) !== '';

$v_name = $draft['webhook_name'] ?? ($existing['webhook_name'] ?? $dest->name);
$name_auto = !$existing && !isset($draft['webhook_name']);
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
$is_custom = $dest->id === 'custom-template';
$auth_above = $dest->defaultAuth !== 'none';   // the platform's own credential belongs on the first screen
$secret_above = $dest->defaultAuth === 'hmac';
$tab = in_array($_GET['tab'] ?? '', ['connection', 'events', 'advanced', 'deliveries'], true) ? $_GET['tab'] : 'connection';
$step = in_array($_GET['step'] ?? '', ['connect', 'events', 'review'], true) ? $_GET['step'] : 'connect';
$rec_kind = $dest->category === 'automation' ? 'work' : (in_array($dest->category, ['chat', 'notify', 'home'], true) ? 'warn' : 'all');
$last_delivery = null;
$dest_slug = $dest->id;
if ($existing) {
    $last_delivery = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT http_status, created_at FROM webhook_deliveries WHERE webhook_id = $wid ORDER BY delivery_id DESC LIMIT 1")) ?: null;
}

/** The authentication select plus the panel for each mode. */
$render_auth = static function () use ($dest, $v_mode, $mode_labels, $h, $saved, $existing, $auth_old, $v_user, $v_hname) {
    ?>
    <div class="whf-auth mb-3">
        <label class="form-label" for="webhook_auth_mode">Authentication <small class="text-muted">how <?= $h($dest->name) ?> recognises us</small></label>
        <select class="form-select" id="webhook_auth_mode" name="webhook_auth_mode" data-wh-auth-mode <?= count($dest->authModes) === 1 ? 'aria-describedby="whf_auth_single"' : '' ?>>
            <?php foreach ($dest->authModes as $m) { ?><option value="<?= $h($m) ?>" <?= $v_mode === $m ? 'selected' : '' ?>><?= $h($mode_labels[$m] ?? $m) ?></option><?php } ?>
        </select>
        <?php if (count($dest->authModes) === 1 && $dest->authModes[0] === 'none') { ?><div class="form-text" id="whf_auth_single"><?= $h($dest->name) ?> needs no authentication header: the address itself is the credential.</div><?php } ?>
    </div>
    <div data-wh-auth-panel="none" class="text-muted small mb-3">No extra header is added. Our signature headers are still sent.</div>
    <div data-wh-auth-panel="hmac" class="text-muted small mb-3">The receiver checks our <code>X-Rivet-Signature-V2</code> header with the signing secret.</div>
    <div data-wh-auth-panel="bearer" class="mb-3">
        <label class="form-label" for="auth_token">Bearer token</label>
        <?php whfSecret('auth_token', 'auth_token', $saved($existing && ($existing['webhook_auth_mode'] ?? '') === 'bearer' && ($auth_old['token'] ?? '') !== ''), ['maxlength' => 2048]); ?>
        <div class="form-text">Sent as <code>Authorization: Bearer &lt;token&gt;</code>. Stored encrypted and never shown again.</div>
    </div>
    <div data-wh-auth-panel="basic" class="mb-3">
        <div class="row g-2">
            <div class="col-md-6"><label class="form-label" for="auth_username">Username</label><input type="text" class="form-control" id="auth_username" name="auth_username" autocomplete="off" maxlength="256" value="<?= $h($v_user) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="auth_password">Password</label><?php whfSecret('auth_password', 'auth_password', $saved($existing && ($existing['webhook_auth_mode'] ?? '') === 'basic' && ($auth_old['password'] ?? '') !== ''), ['maxlength' => 1024]); ?></div>
        </div>
        <div class="form-text">Sent as <code>Authorization: Basic ...</code>. Stored encrypted and never shown again.</div>
    </div>
    <div data-wh-auth-panel="header" class="mb-3">
        <div class="row g-2">
            <div class="col-md-5"><label class="form-label" for="auth_header_name">Header name</label><input type="text" class="form-control font-monospace" id="auth_header_name" name="auth_header_name" maxlength="64" value="<?= $h($v_hname) ?>" placeholder="<?= $h($dest->defaultAuthHeader ?? 'X-Api-Key') ?>"></div>
            <div class="col-md-7"><label class="form-label" for="auth_header_value">Header value</label><?php whfSecret('auth_header_value', 'auth_header_value', $saved($existing && ($existing['webhook_auth_mode'] ?? '') === 'header' && ($auth_old['header_value'] ?? '') !== ''), ['maxlength' => 2048]); ?></div>
        </div>
        <div class="form-text">Names such as Host, Content-Length, Content-Type and anything starting with <code>X-Rivet-</code> or ending in <code>-Signature</code> are reserved. The value is stored encrypted and never shown again.</div>
    </div>
    <?php
};

$render_secret = static function () use ($has_secret, $h) {
    ?>
    <div class="mb-3" data-whf-secret-block>
        <label class="form-label" for="webhook_secret">Signing secret <small class="text-muted">the receiver uses it to verify we sent the request (HMAC-SHA256)</small></label>
        <?php whfSecret('webhook_secret', 'webhook_secret', $has_secret ? '(saved - leave blank to keep)' : 'a long random string', ['maxlength' => 255, 'generate' => true]); ?>
        <div class="form-text" data-whf-secret-help>Copy it into the receiver (the guide's verification snippets use it). Stored encrypted; leave blank on edit to keep the current one.</div>
    </div>
    <?php
};

/** Extra platform fields; $required_only true = the ones needed on the first screen. */
$render_fields = static function (bool $required_only) use ($dest, $existing, $v_fields, $h) {
    foreach ($dest->extraFields as $f) {
        $isUrl = $f->target === 'url';
        $above = $f->required || $isUrl;
        if ($above !== $required_only) {
            continue;
        }
        $fid = 'webhook_field_' . $f->name;
        $val = $isUrl ? '' : ($v_fields[$f->name] ?? '');
        $required = $f->required && ($isUrl ? !$existing : true); ?>
        <div class="mb-3">
            <label class="form-label" for="<?= $h($fid) ?>"><?= $h($f->label) ?><?= $f->required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '' ?><?= $isUrl ? ' <small class="text-muted">(goes into the address)</small>' : '' ?></label>
            <?php if ($f->type === 'secret') {
                whfSecret($fid, 'webhook_field[' . $f->name . ']', $existing && $isUrl ? '(only needed when you re-enter the address)' : $f->example, ['value' => $val, 'maxlength' => 300, 'required' => $required, 'attrs' => $isUrl ? 'data-whf-urlpart' : '']);
            } else { ?>
                <input type="<?= $f->type === 'number' ? 'number' : 'text' ?>" class="form-control<?= $f->type === 'number' ? '' : ' font-monospace' ?>" id="<?= $h($fid) ?>" name="webhook_field[<?= $h($f->name) ?>]"
                       value="<?= $h($val) ?>" placeholder="<?= $h($existing && $isUrl ? '(only needed when you re-enter the address)' : $f->example) ?>" <?= $required ? 'required' : '' ?>
                       <?= $f->type === 'number' ? 'min="1" max="5"' : '' ?> maxlength="300" autocomplete="off" <?= $isUrl ? 'data-whf-urlpart' : '' ?>>
            <?php } ?>
            <div class="form-text"><?= $h($f->help) ?></div>
        </div>
    <?php }
};

$render_payload = static function () use ($dest, $is_custom, $v_method, $v_enc, $v_template, $ticket_fields, $h) {
    if ($is_custom) { ?>
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
        <label class="form-label" for="webhook_template">Body template <span class="text-danger" aria-hidden="true">*</span></label>
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
        <p class="text-muted small mb-0">The Review step shows the exact body and headers for any sample event.</p>
    <?php }
};

$render_advanced = static function () use ($render_auth, $render_secret, $render_fields, $render_payload, $auth_above, $secret_above, $is_custom, $dest) {
    if (!$auth_above) {
        echo '<h5 class="whf-subh">Authentication</h5>';
        $render_auth();
    }
    if (!$secret_above) {
        $render_secret();
    }
    $render_fields(false);
    if (!$is_custom) {
        echo '<h5 class="whf-subh">Payload</h5>';
        $render_payload();
    }
    ?>
    <h5 class="whf-subh">Retries</h5>
    <p class="small text-muted mb-0">If the receiver does not answer with a success status, the delivery is retried after 1, 5, 30 and 120 minutes and then set aside as failed (see the Job queue page). Every attempt is kept in the delivery log.</p>
    <?php
};

$title = $existing ? 'Edit webhook' : 'Add webhook';
$test_hint_attr = $h($dest->name);
?>
<link rel="stylesheet" href="/css/webhook_form.css?v=<?= $css_v ?>">

<div class="whf <?= $existing ? 'whf-edit' : 'whf-create' ?>" data-whf data-mode="<?= $existing ? 'edit' : 'create' ?>" data-step="<?= $existing ? '' : $step ?>" data-tab="<?= $existing ? $tab : '' ?>"
     data-dest="<?= $h($dest->id) ?>" data-dest-name="<?= $h($dest->name) ?>" data-dest-cat="<?= $h($dest->category) ?>" data-format="<?= $h($dest->format) ?>" data-method="<?= $h($dest->method) ?>"
     data-rec="<?= $rec_kind ?>" data-tools-url="webhook_tools.php" data-check-url="webhook_url_check.php" data-webhook-id="<?= $wid ?>" data-has-url="<?= $url_hidden ? '1' : '0' ?>" data-name-auto="<?= $name_auto ? '1' : '0' ?>">

    <?php if ($existing) { ?>
        <div class="whf-edit-head">
            <?= whfIcon($dest->id, $dest->category, 'whf-icon-lg') ?>
            <div class="whf-edit-title">
                <h3 class="mb-0"><?= $h($existing['webhook_name']) ?></h3>
                <div class="small text-muted"><?= $h($dest->name) ?> &middot; <code><?= $h(rivetWebhookUrlMasked($stored_url)) ?></code></div>
            </div>
            <div class="whf-edit-tools">
                <?php if ($last_delivery) {
                    $okd = (int) $last_delivery['http_status'] >= 200 && (int) $last_delivery['http_status'] < 300; ?>
                    <span class="badge <?= $okd ? 'text-bg-success' : 'text-bg-danger' ?>" title="Last delivery <?= $h($last_delivery['created_at']) ?>">Last: <?= $last_delivery['http_status'] ? 'HTTP ' . (int) $last_delivery['http_status'] : 'no response' ?>, <?= $h(timeAgo($last_delivery['created_at'])) ?></span>
                <?php } else { ?><span class="badge text-bg-secondary">No deliveries yet</span><?php } ?>
                <label class="whf-switch" title="Enable or disable this webhook right away">
                    <input type="checkbox" role="switch" data-whf-toggle="<?= $wid ?>" <?= $v_enabled ? 'checked' : '' ?>>
                    <span class="whf-switch-track" aria-hidden="true"></span><span class="whf-switch-label" data-whf-toggle-label><?= $v_enabled ? 'Enabled' : 'Disabled' ?></span>
                </label>
                <button type="button" class="btn btn-outline-primary btn-sm" data-wh-action="test"><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-whf-duplicate="<?= $wid ?>"><i class="fas fa-clone me-1" aria-hidden="true"></i>Duplicate</button>
                <a href="post.php?delete_webhook=<?= $wid ?>&csrf_token=<?= $h($csrf) ?>" class="btn btn-outline-danger btn-sm confirm-link" data-confirm-title="Delete webhook" aria-label="Delete <?= $h($existing['webhook_name']) ?>"><i class="fas fa-trash me-1" aria-hidden="true"></i>Delete</a>
                <button type="button" class="btn btn-light btn-sm" data-whf-guide-open aria-controls="whf_guide"><i class="fas fa-life-ring me-1" aria-hidden="true"></i>Need help?</button>
            </div>
        </div>
        <div class="wh-result whf-test-result mb-3" data-wh-result-head role="status" aria-live="polite" hidden></div>
        <div class="whf-tabs" role="tablist" aria-label="Webhook sections">
            <?php foreach (['connection' => 'Connection', 'events' => 'Events', 'advanced' => 'Payload & advanced', 'deliveries' => 'Deliveries'] as $tk => $tl) { ?>
                <button type="button" role="tab" class="whf-tab <?= $tab === $tk ? 'is-on' : '' ?>" id="whf_tab_<?= $tk ?>" data-whf-tab="<?= $tk ?>" aria-selected="<?= $tab === $tk ? 'true' : 'false' ?>" aria-controls="whf_pane_<?= $tk ?>"><?= $h($tl) ?></button>
            <?php } ?>
        </div>
    <?php } else { ?>
        <div class="whf-head">
            <?= whfIcon($dest->id, $dest->category) ?>
            <h3 class="whf-title"><?= $h($title) ?>: <?= $h($dest->name) ?></h3>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-whf-guide-open aria-controls="whf_guide"><i class="fas fa-life-ring me-1" aria-hidden="true"></i>Need help?</button>
            <a href="webhook_form.php" class="btn btn-light btn-sm" data-whf-change-platform>Change platform</a>
            <a href="settings_webhooks.php" class="btn btn-light btn-sm">Cancel</a>
        </div>
        <?php whfStepper($step === 'review' ? 'review' : ($step === 'events' ? 'events' : 'connect'), $dest->id); ?>
        <div class="visually-hidden" aria-live="polite" data-whf-live></div>
    <?php } ?>

    <div class="whf-layout">
    <form action="post.php" method="post" autocomplete="off" id="webhook_form" class="whf-form" data-wh-form novalidate data-tools-url="webhook_tools.php" data-webhook-id="<?= $wid ?>" data-destination="<?= $h($dest->id) ?>">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <input type="hidden" name="webhook_destination" value="<?= $h($dest->id) ?>">
        <input type="hidden" name="wizard" value="1">
        <input type="hidden" name="after" value="" data-whf-after>
        <?php if ($existing) { ?><input type="hidden" name="webhook_id" value="<?= $wid ?>"><?php } ?>

        <!-- ============ Connect / Connection -->
        <section class="whf-pane" data-whf-pane="connect" id="whf_pane_connection" <?= $existing ? 'role="tabpanel" aria-labelledby="whf_tab_connection"' : '' ?>>
            <div class="whf-card-panel">
                <h4 class="whf-h" tabindex="-1" data-whf-focus>Connect to <?= $h($dest->name) ?></h4>
                <?php if ($legacy_row) { ?><div class="alert alert-info py-2 small">This webhook was created before platform presets existed and sends the plain JSON envelope. Saving it here keeps that behaviour; you can add authentication or a platform-shaped body by choosing another platform when you re-create it.</div><?php } ?>
                <div data-whf-errors="connect" class="whf-errors" role="alert" hidden></div>

                <div class="mb-3">
                    <label class="form-label" for="webhook_url">Endpoint URL <?php if (!$existing) { ?><span class="text-danger" aria-hidden="true">*</span><?php } ?></label>
                    <input type="text" inputmode="url" class="form-control font-monospace" id="webhook_url" name="webhook_url" maxlength="2048" <?= $existing ? '' : 'required' ?>
                           value="<?= $h($url_value) ?>" placeholder="<?= $h($existing && $url_hidden ? 'saved: ' . rivetWebhookUrlMasked($stored_url) . ' (leave blank to keep)' : $dest->urlHint) ?>"
                           data-wh-url-hint="<?= $h($dest->urlHint) ?>" aria-describedby="whf_url_status whf_url_help" spellcheck="false" autocapitalize="off">
                    <div class="whf-urlstatus" id="whf_url_status" data-whf-url-status role="status" aria-live="polite"></div>
                    <div class="form-text" id="whf_url_help">
                        <?php if ($existing && $url_hidden) { ?>Stored encrypted and not shown again. Leave blank to keep it, or paste the whole address to replace it.
                        <?php } else { ?>Paste it from <?= $h($dest->name) ?>. It looks like <code><?= $h($dest->urlHint) ?></code>.<?php } ?>
                        <?php if ($has_url_fields) { ?> Parts in <code>{braces}</code> are filled from the fields below.<?php } ?>
                    </div>
                </div>

                <?php $render_fields(true); ?>

                <div class="mb-3">
                    <label class="form-label" for="webhook_name">Name <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="webhook_name" name="webhook_name" required maxlength="200" value="<?= $h($v_name) ?>" placeholder="e.g. <?= $h($dest->name) ?> alerts" data-whf-name data-suggest="<?= $h($dest->name) ?>">
                    <div class="form-text">Only for you: it appears in the list and the delivery log.</div>
                </div>

                <?php if ($is_custom) { ?><h5 class="whf-subh">Body</h5><?php $render_payload(); } ?>
                <?php if ($auth_above) {
                    echo '<h5 class="whf-subh">Authentication</h5>';
                    $render_auth();
                    if ($secret_above) {
                        $render_secret();
                    }
                } ?>

                <?php if (!$existing) { ?>
                    <details class="whf-adv" data-whf-adv>
                        <summary><i class="fas fa-sliders-h me-2" aria-hidden="true"></i>Advanced options <span class="text-muted small">authentication method, signing, payload, retries</span></summary>
                        <div class="whf-adv-body"><?php $render_advanced(); ?></div>
                    </details>
                <?php } ?>
            </div>
        </section>

        <!-- ============ Events -->
        <section class="whf-pane" data-whf-pane="events" id="whf_pane_events" <?= $existing ? 'role="tabpanel" aria-labelledby="whf_tab_events"' : '' ?> hidden>
            <div class="whf-card-panel">
                <h4 class="whf-h" tabindex="-1" data-whf-focus>Which events should <?= $h($dest->name) ?> receive?</h4>
                <div data-whf-errors="events" class="whf-errors" role="alert" hidden></div>
                <p class="text-muted small mb-2">Pick a quick set, then fine-tune below. Ticking everything in a family saves a pattern such as <code>ticket.*</code>, so events added later are included too.</p>
                <div class="whf-presets" data-whf-presets role="group" aria-label="Quick event sets"></div>
                <?php eventPickerField('webhook_events[]', $v_events, ['id' => 'webhook_events_picker', 'other' => $other_events]); ?>
            </div>
        </section>

        <?php if ($existing) { ?>
        <!-- ============ Payload & advanced (edit only; on create it is the disclosure of the Connect step) -->
        <section class="whf-pane" data-whf-pane="advanced" id="whf_pane_advanced" role="tabpanel" aria-labelledby="whf_tab_advanced" hidden>
            <div class="whf-card-panel">
                <h4 class="whf-h" tabindex="-1" data-whf-focus>Payload &amp; advanced</h4>
                <div data-whf-errors="advanced" class="whf-errors" role="alert" hidden></div>
                <?php $render_advanced(); ?>
                <?php if ($is_custom) { /* the template itself is on the Connection tab */ ?><p class="small text-muted mt-3 mb-0">The body template and encoding are on the Connection tab.</p><?php } ?>
                <div class="form-check form-switch mt-3">
                    <input type="checkbox" class="form-check-input" id="webhook_enabled" name="webhook_enabled" value="1" <?= $v_enabled ? 'checked' : '' ?> data-whf-enabled-field>
                    <label class="form-check-label" for="webhook_enabled">Enabled <span class="text-muted small">(the switch in the header changes this right away)</span></label>
                </div>
            </div>
        </section>
        <section class="whf-pane" data-whf-pane="deliveries" id="whf_pane_deliveries" role="tabpanel" aria-labelledby="whf_tab_deliveries" hidden>
            <div class="whf-card-panel">
                <h4 class="whf-h" tabindex="-1" data-whf-focus>Recent deliveries</h4>
                <p class="text-muted small">The latest 25 attempts, including retries. "View payload" shows the request body as it was sent (secret-looking fields hidden).</p>
                <div data-whf-deliveries aria-live="polite"><span class="text-muted">Loading...</span></div>
            </div>
        </section>
        <?php } else { ?>
        <!-- ============ Review & test -->
        <section class="whf-pane" data-whf-pane="review" id="whf_pane_review" hidden>
            <div class="whf-card-panel">
                <h4 class="whf-h" tabindex="-1" data-whf-focus>Review &amp; test</h4>
                <div data-whf-errors="review" class="whf-errors" role="alert" hidden></div>
                <dl class="whf-summary" data-whf-summary></dl>
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" class="form-check-input" id="webhook_enabled" name="webhook_enabled" value="1" <?= $v_enabled ? 'checked' : '' ?> data-whf-enabled-field>
                    <label class="form-check-label" for="webhook_enabled">Start sending right away</label>
                </div>
                <h5 class="whf-subh">Payload preview</h5>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <label class="small text-muted mb-0" for="whf_sample">Sample event</label>
                    <select class="form-select form-select-sm whf-sample" id="whf_sample" data-whf-sample aria-label="Sample event for the payload preview"></select>
                    <button type="button" class="btn btn-sm btn-light" data-wh-action="preview"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Refresh</button>
                </div>
                <div class="wh-result" data-whf-preview aria-live="polite" hidden></div>
                <h5 class="whf-subh">Send a test</h5>
                <p class="small text-muted mb-2">Sends one sample event now through the real delivery path (nothing is saved). It appears in the delivery log as <code>test</code>.</p>
                <button type="button" class="btn btn-outline-primary" data-wh-action="test"><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test</button>
                <div class="wh-result whf-test-result mt-3" data-wh-result role="status" aria-live="polite" hidden></div>
            </div>
        </section>
        <?php } ?>

        <div class="whf-footer" data-whf-footer>
            <?php if ($existing) { ?>
                <span class="whf-dirty" data-whf-dirty hidden><i class="fas fa-circle me-1" aria-hidden="true"></i>Unsaved changes</span>
                <a href="settings_webhooks.php" class="btn btn-light ms-auto">Cancel</a>
                <button type="submit" name="edit_webhook" value="1" class="btn btn-primary whf-primary"><i class="fas fa-check me-1" aria-hidden="true"></i>Save changes</button>
            <?php } else { ?>
                <button type="button" class="btn btn-light" data-whf-back><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Back</button>
                <span class="whf-hint d-none d-md-inline" data-whf-hint>Press Enter to continue, Esc to go back</span>
                <button type="button" class="btn btn-primary whf-primary ms-auto" data-whf-next>Continue <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i></button>
                <button type="submit" name="add_webhook" value="1" class="btn btn-primary whf-primary ms-auto" data-whf-create hidden><i class="fas fa-check me-1" aria-hidden="true"></i>Create webhook</button>
                <button type="submit" name="add_webhook" value="1" class="btn btn-outline-primary" data-whf-create data-after="test" hidden><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Create and send test</button>
            <?php } ?>
        </div>
    </form>

    <!-- Setup guide: a slide-over (a full-screen sheet on phones); on wide screens it can be docked as a slim column -->
    <aside class="whf-guide" id="whf_guide" aria-label="Setup guide for <?= $h($dest->name) ?>" hidden>
        <div class="whf-guide-head">
            <h4 class="mb-0 me-auto"><i class="fas fa-fw fa-book me-2" aria-hidden="true"></i><?= $h($dest->name) ?> setup</h4>
            <button type="button" class="btn btn-light btn-sm d-none d-xxl-inline-flex" data-whf-guide-dock aria-pressed="false" title="Keep the guide beside the form"><i class="fas fa-columns me-1" aria-hidden="true"></i>Dock</button>
            <button type="button" class="btn btn-light btn-sm" data-whf-guide-close aria-label="Close the guide"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="whf-guide-body">
            <p class="text-muted small"><?= $h($dest->description) ?></p>
            <h6 class="wh-h">Set it up</h6>
            <ol class="wh-steps"><?php foreach ($dest->setupSteps as $st) { ?><li><?= $h($st) ?></li><?php } ?></ol>
            <?php if (preg_match('#^https?://#', $dest->docsUrl)) { ?><p class="small"><a href="<?= $h($dest->docsUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt me-1" aria-hidden="true"></i><?= $h($dest->name) ?> documentation</a></p><?php } ?>
            <?php if ($dest->notes) { ?>
                <h6 class="wh-h">Good to know</h6>
                <ul class="wh-notes"><?php foreach ($dest->notes as $n) { ?><li><?= $h($n) ?></li><?php } ?></ul>
            <?php } ?>
            <details class="whf-lazy mt-3" data-whf-lazy>
                <summary>Try it from a terminal, and verify our signature</summary>
                <template>
                    <?php webhookCodeBlock('form-curl-' . $dest->id, $dest->sampleCurl, 'Copy the sample curl command'); ?>
                    <h6 class="wh-h mt-3">Verify our signature</h6>
                    <p class="small text-muted mb-2">Every request carries <code>X-Rivet-Signature-V2</code>: an HMAC-SHA256 of <code>&lt;timestamp&gt;.&lt;raw body&gt;</code> with your signing secret. Reject it when it does not match or is older than 5 minutes.</p>
                    <?php webhookVerifyTabs($dest, 'form-' . preg_replace('/[^a-z0-9]+/', '-', $dest->id)); ?>
                </template>
                <div data-whf-lazy-target></div>
            </details>
            <p class="small mt-3 mb-0"><a href="webhook_guides.php#<?= $h($dest->id) ?>" target="_blank" rel="noopener"><i class="fas fa-book me-1" aria-hidden="true"></i>Full guide</a></p>
        </div>
    </aside>
    <div class="whf-scrim" data-whf-guide-close hidden></div>
    </div>
</div>

<dialog id="wh_dialog" class="wh-dialog" aria-labelledby="wh_dialog_title">
    <div class="wh-dialog-head">
        <h5 class="mb-0 me-auto" id="wh_dialog_title" data-wh-dialog-title></h5>
        <button type="button" class="btn btn-light btn-sm" data-wh-dialog-close aria-label="Close">Close</button>
    </div>
    <div class="wh-dialog-body" data-wh-dialog-body></div>
</dialog>

<?php require_once "../includes/footer.php"; ?>
