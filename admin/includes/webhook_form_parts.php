<?php

/*
 * The fields of the Add webhook wizard (admin/webhook_new.php) and the Edit webhook tabs (admin/webhook_edit.php), split by what a person
 * needs to see first:
 *
 *   webhookFormContext()   everything the three parts share (stored values, which fields exist for the platform, where each one lives)
 *   webhookPartConnect()   the REQUIRED fields only: name, address, the platform's own required inputs, a required auth credential
 *   webhookPartAdvanced()  everything optional: other inputs, auth mode, signing secret, method, retry notes
 *   webhookPartEvents()    quick preset chips + the searchable event picker
 *
 * Field names are the ones the save handler has always read; the platform's own inputs post as extra[name]. The server (rivetWebhookCollect() in
 * webhook_form_lib.php: RivetCore Destination / Authentication / PayloadTemplate / UrlPolicy) stays the only authority. A stored secret is never echoed: its field shows
 * "Saved. Leave blank to keep it." and the server keeps it when the field is blank. Behaviour lives in js/webhook_wizard.js (data-wz-* hooks).
 */

use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\PayloadTemplate;

require_once __DIR__ . '/webhook_form_lib.php';
require_once __DIR__ . '/../../includes/event_picker.php';
require_once __DIR__ . '/../../includes/webhook_guide.php';

if (!function_exists('webhookFormContext')) {
    /** @return array<string,mixed> */
    function webhookFormContext(Destination $d, ?array $wh, bool $editing): array
    {
        global $mysqli;
        $c = ['d' => $d, 'wh' => $wh, 'editing' => $editing];
        // "same": the stored row is this platform (a different platform picked on the Edit page starts from empty fields).
        $c['same'] = $editing && $wh !== null && rivetWebhookEffectiveDestination($wh) === $d->id;
        $stored_extra = $c['same'] ? (json_decode((string) ($wh['webhook_extra'] ?? ''), true) ?: []) : [];
        $c['stored_extra'] = is_array($stored_extra['fields'] ?? null) ? $stored_extra['fields'] : [];
        $stored_auth = $c['same'] ? (json_decode(decryptSetting((string) ($wh['webhook_auth_enc'] ?? '')), true) ?: []) : [];
        $stored_mode = $c['same'] && (string) ($wh['webhook_auth_mode'] ?? '') !== '' ? (string) $wh['webhook_auth_mode'] : $d->defaultAuth;
        $c['stored_auth'] = ['mode' => $stored_mode] + (is_array($stored_auth) ? $stored_auth : []);
        $c['is_chat'] = false;
        $c['url_secret'] = rivetWebhookUrlIsSecret($d->id);
        // An edit never shows the stored address (it can carry a token): a blank field keeps it. A different platform starts from empty fields.
        $c['url_saved'] = $c['same'] && (string) ($wh['webhook_url'] ?? '') !== '';
        $c['has_url_fields'] = false;
        foreach ($d->extraFields as $f) {
            if ($f->target === 'url') {
                $c['has_url_fields'] = true;
            }
        }
        $c['allow_http'] = $c['same'] && ($stored_extra['allow_http'] ?? false) === true;
        $c['url_prefill'] = '';
        if (!$editing && (str_contains($d->urlHint, '{') || $c['has_url_fields'])) {
            $c['url_prefill'] = $d->urlHint;     // keeps {topic}, {txn} ... in view so it is clear what the preset builds
        }
        $mode = (string) ($c['stored_auth']['mode'] ?? $d->defaultAuth);
        $c['auth_mode'] = in_array($mode, $d->authModes, true) ? $mode : $d->defaultAuth;
        $c['has_auth'] = count($d->authModes) > 1 || $d->authModes[0] !== 'none';
        // The credential belongs above the fold when the platform cannot work without one (its default mode carries a secret) or one is already saved.
        $c['auth_in_connect'] = $c['has_auth'] && in_array($c['auth_mode'], ['bearer', 'basic', 'header'], true);
        $c['wh_events'] = $wh ? array_values(array_filter(array_map('trim', explode(',', (string) $wh['webhook_events'])))) : [];
        $wh_method = $c['same'] ? (string) ($wh['webhook_method'] ?? '') : '';
        $c['methods'] = rivetWebhookAllowedMethods($d);
        $c['method'] = in_array($wh_method, $c['methods'], true) ? $wh_method : $c['methods'][0];
        $c['tpl'] = $c['same'] && (string) ($wh['webhook_template'] ?? '') !== '' ? (string) $wh['webhook_template'] : (string) ($d->formatOptions['template'] ?? '');
        $c['tpl_enc'] = (string) ($stored_extra['template_encoding'] ?? ($d->formatOptions['template_encoding'] ?? 'json'));
        $c['has_secret'] = $c['same'] && (string) ($wh['webhook_secret'] ?? '') !== '' && decryptSetting((string) $wh['webhook_secret']) !== '';
        $c['signs'] = $d->verifySnippets !== [];
        $c['gen_secret'] = !$editing && $c['signs'] ? bin2hex(random_bytes(20)) : '';
        // A preset with a fixed host and parts filled from its own fields (Telegram) hides the address in Advanced: nobody types it.
        $c['url_in_advanced'] = $c['has_url_fields'] && $d->urlPattern !== null && $d->id !== 'custom-template';
        $c['uid'] = 'wz' . bin2hex(random_bytes(3));
        $c['name'] = (string) ($wh['webhook_name'] ?? '');

        return $c;
    }

    /** One secret input: show/hide, copy and (optionally) generate. A saved secret shows only its "leave blank to keep" hint. */
    function webhookSecretField(string $name, string $id, string $label, array $o = []): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $saved = !empty($o['saved']);
        $ph = $saved ? 'Saved. Leave blank to keep it.' : (string) ($o['placeholder'] ?? '');
        $badge = !empty($o['required']) ? ' <span class="text-danger" aria-hidden="true">*</span>' : (isset($o['optional']) ? ' <small class="text-secondary">(optional)</small>' : '');
        ?>
        <div class="wz-field" data-wz-field>
            <?php if ($label !== '') { ?><label class="form-label" for="<?= $h($id) ?>"><?= $label . $badge ?></label><?php } ?>
            <div class="input-group">
                <input type="<?= !empty($o['visible']) ? 'text' : 'password' ?>" class="form-control font-monospace" id="<?= $h($id) ?>" name="<?= $h($name) ?>" autocomplete="new-password" spellcheck="false"
                       maxlength="<?= intval($o['maxlength'] ?? 2048) ?>" value="<?= $h($o['value'] ?? '') ?>" placeholder="<?= $h($ph) ?>" data-wz-secret<?= !empty($o['required']) && !$saved ? ' data-wz-required' : '' ?>
                       <?= isset($o['label_plain']) ? 'aria-label="' . $h($o['label_plain']) . '"' : '' ?>>
                <button type="button" class="btn btn-outline-secondary" data-wz-reveal aria-pressed="false" aria-label="Show"><i class="fas fa-eye" aria-hidden="true"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-wz-copyval aria-label="Copy"><i class="far fa-copy" aria-hidden="true"></i></button>
                <?php if (!empty($o['generate'])) { ?><button type="button" class="btn btn-outline-secondary" data-wz-gen><i class="fas fa-dice me-1" aria-hidden="true"></i><?= $h($o['generate']) ?></button><?php } ?>
            </div>
            <?php if (!empty($o['help'])) { ?><div class="form-text"><?= $h($o['help']) ?></div><?php } ?>
            <div class="wz-err" data-wz-err hidden></div>
        </div>
        <?php
    }

    function webhookAuthBlock(array $c): void
    {
        /** @var Destination $d */
        $d = $c['d'];
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $uid = $c['uid'];
        $editing = $c['editing'] && $c['same'];
        $sa = $c['stored_auth'];
        $labels = ['none' => 'None', 'hmac' => 'None, rely on our signature', 'bearer' => 'Bearer token', 'basic' => 'Basic (username and password)', 'header' => 'Custom header'];
        ?>
        <fieldset class="wz-auth" data-wz-auth>
            <legend class="wz-legend">Authentication <small class="text-secondary">(what <?= $h($d->name) ?> expects from us)</small></legend>
            <?php if (count($d->authModes) > 1) { ?>
            <select class="form-select mb-2" name="webhook_auth_mode" aria-label="Authentication mode" data-wz-auth-mode>
                <?php foreach ($d->authModes as $m) { ?><option value="<?= $h($m) ?>" <?= $c['auth_mode'] === $m ? 'selected' : '' ?>><?= $h($labels[$m] ?? $m) ?></option><?php } ?>
            </select>
            <?php } else { ?>
            <input type="hidden" name="webhook_auth_mode" value="<?= $h($d->authModes[0]) ?>" data-wz-auth-mode>
            <?php } ?>
            <div data-wz-auth-fields="bearer">
                <?php webhookSecretField('auth_token', $uid . '-tok', 'Bearer token', ['required' => true, 'saved' => $editing && ($sa['token'] ?? '') !== '', 'placeholder' => 'Token', 'maxlength' => 2048]); ?>
            </div>
            <div data-wz-auth-fields="basic">
                <div class="row g-2">
                    <div class="col-sm-6 wz-field" data-wz-field>
                        <label class="form-label" for="<?= $uid ?>-bu">Username <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control" id="<?= $uid ?>-bu" name="auth_username" autocomplete="off" maxlength="256" value="<?= $h($sa['username'] ?? '') ?>" placeholder="Username" data-wz-required-if-basic>
                        <div class="wz-err" data-wz-err hidden></div>
                    </div>
                    <div class="col-sm-6"><?php webhookSecretField('auth_password', $uid . '-bp', 'Password', ['required' => true, 'saved' => $editing && ($sa['password'] ?? '') !== '', 'placeholder' => 'Password', 'maxlength' => 1024]); ?></div>
                </div>
            </div>
            <div data-wz-auth-fields="header">
                <div class="row g-2">
                    <div class="col-sm-5 wz-field" data-wz-field>
                        <label class="form-label" for="<?= $uid ?>-hn">Header name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control font-monospace" id="<?= $uid ?>-hn" name="auth_header_name" autocomplete="off" maxlength="64"
                               value="<?= $h(($sa['header_name'] ?? '') !== '' ? $sa['header_name'] : ($d->defaultAuthHeader ?? '')) ?>" placeholder="e.g. <?= $h($d->defaultAuthHeader ?? 'X-Api-Key') ?>">
                        <div class="wz-err" data-wz-err hidden></div>
                    </div>
                    <div class="col-sm-7"><?php webhookSecretField('auth_header_value', $uid . '-hv', 'Header value', ['required' => true, 'saved' => $editing && ($sa['header_value'] ?? '') !== '', 'placeholder' => 'Header value', 'maxlength' => 2048]); ?></div>
                </div>
                <div class="form-text">Host, Content-Length, Content-Type, Connection and our own X-Rivet-* / *-Signature headers cannot be used.</div>
            </div>
            <div class="form-text" data-wz-auth-none>Our signature headers (<code>X-Rivet-Signature-V2</code>) are always sent.</div>
        </fieldset>
        <?php
    }

    /** A platform's own input (topic, chat id, room id ...). */
    function webhookExtraField(array $c, \RivetCore\Webhooks\DestinationField $f): void
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $fid = $c['uid'] . '-x-' . $f->name;
        $is_url = $f->target === 'url';
        $val = !$is_url && $f->type !== 'secret' ? (string) ($c['stored_extra'][$f->name] ?? '') : '';
        $saved = $c['editing'] && $c['same'] && ($is_url || $f->type === 'secret');
        if ($f->type === 'secret') {
            webhookSecretField('extra[' . $f->name . ']', $fid, $h($f->label), ['required' => $f->required, 'optional' => !$f->required, 'saved' => $saved, 'placeholder' => $f->example, 'help' => $f->help, 'maxlength' => 200]);

            return;
        }
        ?>
        <div class="wz-field" data-wz-field>
            <label class="form-label" for="<?= $h($fid) ?>"><?= $h($f->label) ?><?= $f->required ? ' <span class="text-danger" aria-hidden="true">*</span>' : ' <small class="text-secondary">(optional)</small>' ?></label>
            <input type="<?= $f->type === 'number' ? 'number' : 'text' ?>" class="form-control" id="<?= $h($fid) ?>" name="extra[<?= $h($f->name) ?>]" value="<?= $h($val) ?>" autocomplete="off" spellcheck="false" maxlength="200"
                   placeholder="<?= $h($saved ? 'Saved. Leave blank to keep it.' : $f->example) ?>"<?= $f->required && !$saved ? ' data-wz-required' : '' ?><?= $is_url ? ' data-wz-urlpart' : '' ?>>
            <div class="form-text"><?= $h($f->help) ?></div>
            <div class="wz-err" data-wz-err hidden></div>
        </div>
        <?php
    }

    function webhookUrlField(array $c, bool $inAdvanced = false): void
    {
        /** @var Destination $d */
        $d = $c['d'];
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $uid = $c['uid'];
        $label = $inAdvanced ? 'Address (changed only for a self-hosted server)' : (!$c['url_secret'] ? 'Endpoint URL' : $d->name . ' URL');
        ?>
        <div class="wz-field" data-wz-field>
            <label class="form-label" for="<?= $uid ?>-url"><?= $h($label) ?><?= $inAdvanced ? '' : ' <span class="text-danger" aria-hidden="true">*</span>' ?></label>
            <input type="text" inputmode="url" class="form-control font-monospace" id="<?= $uid ?>-url" name="webhook_url" autocomplete="off" spellcheck="false" aria-describedby="<?= $uid ?>-urlstate"
                   value="<?= $h($c['url_prefill']) ?>" placeholder="<?= $h($c['url_saved'] ? 'Saved. Leave blank to keep it.' : $d->urlHint) ?>"
                   data-wz-url data-wz-hint="<?= $h($d->urlHint) ?>"<?= $c['url_saved'] || $inAdvanced ? '' : ' data-wz-required' ?>>
            <?php if (!$inAdvanced) { ?>
            <div class="form-text">
                Looks like <code class="text-break"><?= $h($d->urlHint) ?></code>.
                It is stored encrypted and never shown again.
                <?php if ($c['has_url_fields']) { ?>Parts in braces are filled from the fields below.<?php } ?>
            </div>
            <?php if (empty($c['is_chat'])) { ?>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="<?= $uid ?>-allow-http" name="webhook_allow_http" value="1"<?= !empty($c['allow_http']) ? ' checked' : '' ?>>
                <label class="form-check-label" for="<?= $uid ?>-allow-http">Allow plain <code>http://</code> for this destination</label>
                <div class="form-text text-warning-emphasis">Webhooks are sent over https only. Tick this only for an address that starts with <code>http://</code> on a network you trust: the event data (and the signature) travel unencrypted. It is ignored for an <code>https://</code> address, and every change is audit-logged.</div>
            </div>
            <?php } ?>
            <?php } ?>
        </div>
        <?php
    }

    function webhookUrlState(array $c): void
    {
        ?>
        <div class="wz-urlstate" id="<?= htmlspecialchars($c['uid']) ?>-urlstate" data-wz-urlstate role="status" aria-live="polite" hidden></div>
        <?php
    }

    /** Required fields only. */
    function webhookPartConnect(array $c): void
    {
        /** @var Destination $d */
        $d = $c['d'];
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $uid = $c['uid'];
        $default_name = $d->name;
        ?>
        <div class="wz-field" data-wz-field>
            <label class="form-label" for="<?= $uid ?>-name">Name <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="<?= $uid ?>-name" name="webhook_name" maxlength="200" value="<?= $h($c['name']) ?>" placeholder="e.g. <?= $h($default_name) ?> alerts"
                   data-wz-name data-wz-dest-name="<?= $h($d->name) ?>" data-wz-required<?= $c['editing'] ? ' data-wz-touched="1"' : '' ?>>
            <div class="form-text">Only for you: how this webhook is named in lists and the audit log.</div>
            <div class="wz-err" data-wz-err hidden></div>
        </div>

        <?php if (!$c['url_in_advanced']) { webhookUrlField($c); } ?>

        <?php foreach ($d->extraFields as $f) {
            if ($f->required || $f->target === 'url') { webhookExtraField($c, $f); }
        } ?>
        <?php webhookUrlState($c); ?>

        <?php if ($c['auth_in_connect']) { webhookAuthBlock($c); } ?>

        <?php if ($d->id === 'custom-template') {
            $ctx = PayloadTemplate::sampleContext('ticket.created');
            $paths = ['event', 'timestamp'];
            foreach (array_keys($ctx['data']) as $k) { $paths[] = 'data.' . $k; }
            foreach (['title', 'summary', 'url', 'severity', 'actor', 'client'] as $k) { $paths[] = 'summary.' . $k; }
            ?>
        <div class="wz-field" data-wz-field data-wz-template>
            <label class="form-label" for="<?= $uid ?>-tpl">Body template <span class="text-danger" aria-hidden="true">*</span></label>
            <div class="d-flex flex-wrap gap-2 mb-1 align-items-center">
                <select class="form-select form-select-sm w-auto" name="webhook_template_encoding" aria-label="Template encoding" data-wz-tpl-enc>
                    <?php foreach (PayloadTemplate::ENCODINGS as $enc) { ?><option value="<?= $h($enc) ?>" <?= $c['tpl_enc'] === $enc ? 'selected' : '' ?>><?= $h($enc === 'json' ? 'JSON body' : ($enc === 'text' ? 'Plain text body' : 'Form-encoded body')) ?></option><?php } ?>
                </select>
                <span class="small text-secondary" data-wz-tpl-state role="status" aria-live="polite"></span>
            </div>
            <textarea class="form-control font-monospace" id="<?= $uid ?>-tpl" name="webhook_template" rows="7" spellcheck="false" maxlength="8192" data-wz-tpl-input data-wz-required><?= $h($c['tpl']) ?></textarea>
            <details class="mt-1">
                <summary class="small">Placeholders you can use (click to insert)</summary>
                <div class="small mt-1">
                    <?php foreach ($paths as $pth) { ?><button type="button" class="btn btn-sm btn-outline-secondary py-0 me-1 mb-1 font-monospace" data-wz-insert="{{<?= $h($pth) ?>}}">{{<?= $h($pth) ?>}}</button><?php } ?>
                    <p class="mb-1 mt-1">Filters: <code>|default:"x"</code> <code>|upper</code> <code>|lower</code> <code>|trim</code> <code>|truncate:80</code> <code>|json</code> (last; inserts numbers, lists and objects as JSON). In a JSON body a plain <code>{{x}}</code> belongs between quotes. No loops, conditions or code. Up to 8 KB.</p>
                </div>
            </details>
            <div class="wz-err" data-wz-err hidden></div>
        </div>
        <?php } ?>
        <?php
    }

    /** Optional settings. $heading false when a tab already carries the title. */
    function webhookPartAdvanced(array $c): void
    {
        /** @var Destination $d */
        $d = $c['d'];
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $uid = $c['uid'];

        if ($c['url_in_advanced']) { webhookUrlField($c, true); }

        foreach ($d->extraFields as $f) {
            if (!$f->required && $f->target !== 'url') { webhookExtraField($c, $f); }
        }

        if ($c['has_auth'] && !$c['auth_in_connect']) { webhookAuthBlock($c); }

        if ($c['signs'] || in_array('hmac', $d->authModes, true)) {
            ?>
        <div class="wz-field wz-signing" data-wz-field>
            <?php webhookSecretField('webhook_secret', $uid . '-secret',
                'Signing secret <small class="text-secondary">(HMAC-SHA256 key for the signature headers)</small>',
                ['saved' => $c['has_secret'], 'value' => $c['gen_secret'], 'visible' => !$c['editing'] && $c['gen_secret'] !== '', 'placeholder' => 'your-secret-here', 'maxlength' => 200, 'generate' => $c['editing'] ? 'Generate new' : 'Generate',
                    'help' => $c['editing'] ? 'Generating a new secret replaces the saved one when you save; update the receiver too.' : 'Generated for you. Copy it into your receiver (n8n, your script) now: it is stored encrypted and not shown again.']); ?>
        </div>
        <?php }

        if (count($c['methods']) > 1) { ?>
        <div class="wz-field" data-wz-field>
            <label class="form-label" for="<?= $uid ?>-method">HTTP method</label>
            <select class="form-select w-auto" id="<?= $uid ?>-method" name="webhook_method">
                <?php foreach ($c['methods'] as $m) { ?><option value="<?= $h($m) ?>" <?= $c['method'] === $m ? 'selected' : '' ?>><?= $h($m) ?></option><?php } ?>
            </select>
        </div>
        <?php } else { ?>
        <input type="hidden" name="webhook_method" value="<?= $h($c['methods'][0]) ?>">
        <?php }

        if ($d->id !== 'custom-template') { ?>
        <p class="small text-secondary mb-3"><i class="fas fa-file-code me-1" aria-hidden="true"></i>Body format: <code><?= $h($d->format) ?></code> over <code><?= $h($c['methods'][0]) ?></code>, set by the <?= $h($d->name) ?> preset. Use the Review step to see the exact payload before you save.</p>
        <?php }

        ?>
        <p class="small text-secondary mb-0"><i class="fas fa-redo me-1" aria-hidden="true"></i>Retries: a failed delivery is tried again after 1, 5, 30 and 120 minutes, then set aside as failed (see the Job queue page).</p>
        <?php
    }

    /** Quick presets and the picker. */
    function webhookPartEvents(array $c): void
    {
        /** @var Destination $d */
        $d = $c['d'];
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $critical = [];
        foreach (\RivetCore\Webhooks\EventCatalog::all() as $e) {
            if ($e->severity === 'critical') {
                $critical[] = $e->id;
            }
        }
        $presets = rivetWebhookEventPresets($d, $critical);
        ?>
        <div class="wz-presets" data-wz-presets role="group" aria-label="Quick selections">
            <span class="wz-presets-label">Quick picks</span>
            <?php foreach ($presets as $p) { ?>
            <button type="button" class="wz-chip<?= $p['recommended'] ? ' is-recommended' : '' ?>" data-wz-preset="<?= $h($p['key']) ?>" data-values="<?= $h((string) json_encode($p['values'])) ?>" aria-pressed="false" title="<?= $h($p['hint']) ?>">
                <?php if ($p['recommended']) { ?><i class="fas fa-star me-1" aria-hidden="true"></i><?php } ?><?= $h($p['label']) ?>
            </button>
            <?php } ?>
        </div>
        <div class="wz-field" data-wz-field data-wz-events>
            <?php eventPickerField('webhook_events[]', $c['wh_events'], ['id' => $c['uid'] . '-events']); ?>
            <div class="wz-err" data-wz-err hidden></div>
        </div>
        <?php
    }

    /** The platform's brand glyph: a Font Awesome brand where one exists, else the category icon. @return array{0:string,1:string} [style class, icon] */
    function webhookBrandIcon(?Destination $d): array
    {
        if ($d === null) {
            return ['fas', 'fa-plug'];
        }
        $brands = ['slack' => 'fa-slack', 'discord' => 'fa-discord', 'telegram' => 'fa-telegram-plane', 'teams' => 'fa-microsoft'];
        if (isset($brands[$d->id])) {
            return ['fab', $brands[$d->id]];
        }

        return ['fas', webhookGuideIcon($d)];
    }
}
