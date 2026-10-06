<?php

/**
 * Renders a destination's setup guide from the RivetCore catalog (steps, docs link, pitfalls, sample curl, signature
 * verification snippets). Used by the "Setup guide" side panel of the add/edit form and by the Guides page, so the two can never
 * disagree. Tabs and copy buttons are wired by js/webhook_form.js (delegated).
 */

use RivetCore\Webhooks\Destination;

const WEBHOOK_VERIFY_LANGS = ['node' => 'Node.js', 'python' => 'Python', 'php' => 'PHP', 'bash' => 'Bash', 'n8n-code' => 'n8n Code node'];

function webhookH($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** A <pre> block with a Copy button. */
function webhookCodeBlock(string $id, string $code, string $label = 'Copy'): void
{
    ?>
    <div class="wh-code">
        <button type="button" class="btn btn-sm btn-outline-secondary wh-copy" data-wh-copy="#<?= webhookH($id) ?>" aria-label="<?= webhookH($label) ?>"><i class="fas fa-copy me-1" aria-hidden="true"></i>Copy</button>
        <pre id="<?= webhookH($id) ?>" class="mb-0"><code><?= webhookH($code) ?></code></pre>
    </div>
    <?php
}

/** Signature-verification snippets in tabs, one Copy button each. */
function webhookVerifyTabs(Destination $d, string $prefix): void
{
    if (!$d->verifySnippets) {
        ?><p class="text-muted small mb-0"><?= webhookH($d->name) ?> cannot verify our signature (the receiver does not expose the raw request body), so keep the address private.</p><?php
        return;
    }
    $first = true;
    ?>
    <div class="wh-tabs" data-wh-tabs>
        <div class="wh-tablist" role="tablist" aria-label="Verify our signature">
            <?php foreach (WEBHOOK_VERIFY_LANGS as $key => $label) {
                if (!isset($d->verifySnippets[$key])) { continue; }
                $tid = $prefix . '-tab-' . $key; ?>
                <button type="button" role="tab" class="wh-tab<?= $first ? ' active' : '' ?>" id="<?= webhookH($tid) ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" aria-controls="<?= webhookH($tid) ?>-panel" data-wh-tab="<?= webhookH($tid) ?>-panel"><?= webhookH($label) ?></button>
            <?php $first = false; } ?>
        </div>
        <?php $first = true;
        foreach (WEBHOOK_VERIFY_LANGS as $key => $label) {
            if (!isset($d->verifySnippets[$key])) { continue; }
            $tid = $prefix . '-tab-' . $key; ?>
            <div role="tabpanel" class="wh-tabpanel" id="<?= webhookH($tid) ?>-panel" aria-labelledby="<?= webhookH($tid) ?>" <?= $first ? '' : 'hidden' ?>>
                <?php webhookCodeBlock($prefix . '-code-' . $key, $d->verifySnippets[$key], 'Copy the ' . $label . ' verification snippet'); ?>
            </div>
        <?php $first = false; } ?>
    </div>
    <?php
}

/**
 * @param bool $full true on the Guides page (heading, description and how-to-receive context); false in the form's side panel
 */
function webhookGuideRender(Destination $d, bool $full = false, string $idPrefix = 'g'): void
{
    $prefix = $idPrefix . '-' . preg_replace('/[^a-z0-9]+/', '-', $d->id);
    ?>
    <div class="wh-guide">
        <?php if ($full) { ?>
            <p class="text-muted"><?= webhookH($d->description) ?></p>
            <dl class="wh-facts row small mb-3">
                <dt class="col-sm-3">Body format</dt><dd class="col-sm-9"><code><?= webhookH($d->format) ?></code> sent with <code><?= webhookH($d->method) ?></code></dd>
                <dt class="col-sm-3">Address looks like</dt><dd class="col-sm-9"><code><?= webhookH($d->urlHint) ?></code></dd>
                <dt class="col-sm-3">Authentication</dt><dd class="col-sm-9"><?= webhookH(implode(', ', $d->authModes)) ?> (default <?= webhookH($d->defaultAuth) ?>)</dd>
                <?php if ($d->extraFields) { ?><dt class="col-sm-3">Extra fields</dt><dd class="col-sm-9"><?= webhookH(implode(', ', array_map(static fn ($f) => $f->label . ($f->required ? ' (required)' : ''), $d->extraFields))) ?></dd><?php } ?>
            </dl>
        <?php } ?>
        <h6 class="wh-h">Set it up</h6>
        <ol class="wh-steps">
            <?php foreach ($d->setupSteps as $step) { ?><li><?= webhookH($step) ?></li><?php } ?>
        </ol>
        <?php if (preg_match('#^https?://#', $d->docsUrl)) { ?>
            <p class="small mb-3"><a href="<?= webhookH($d->docsUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt me-1" aria-hidden="true"></i><?= webhookH($d->name) ?> documentation</a></p>
        <?php } ?>
        <?php if ($d->notes) { ?>
            <h6 class="wh-h">Good to know</h6>
            <ul class="wh-notes">
                <?php foreach ($d->notes as $n) { ?><li><?= webhookH($n) ?></li><?php } ?>
            </ul>
        <?php } ?>
        <h6 class="wh-h">Try it from a terminal</h6>
        <p class="small text-muted mb-1">A sample request in the shape <?= webhookH($d->name) ?> expects. Replace the placeholders; <code>&lt;token&gt;</code> style values are your own.</p>
        <?php webhookCodeBlock($prefix . '-curl', $d->sampleCurl, 'Copy the sample curl command'); ?>
        <h6 class="wh-h mt-3">Verify our signature</h6>
        <p class="small text-muted mb-2">Every request carries <code>X-Rivet-Signature-V2</code>: an HMAC-SHA256 of <code>&lt;timestamp&gt;.&lt;raw body&gt;</code> with your signing secret. Reject it when it does not match or is older than 5 minutes.</p>
        <?php webhookVerifyTabs($d, $prefix); ?>
    </div>
    <?php
}
