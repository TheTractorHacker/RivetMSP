<?php
/*
 * Administration > Webhooks > Guides: the guide hub. Every platform preset with its full setup guide, rendered from the RivetCore
 * catalog (steps, notes, sample request, signature snippets, example payload), a general "Signing and verifying" section, an
 * "Allowed internal networks" explainer and a "Receiving in n8n" walk-through. Without JavaScript every guide is a section of one long
 * page; js/webhook_guides.js turns it into a searchable hub (sidebar, index cards, one guide at a time, deep links like #n8n).
 * The add flow (webhook_form.php?destination=<id>) is linked from every guide with the platform preselected.
 */
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_form_lib.php";
require_once "includes/webhook_guides_lib.php";

use RivetCore\Webhooks\Destinations;

$app = defined('APP_NAME') ? APP_NAME : 'RivetMSP';
$labels = Destinations::categoryLabels();
$groups = Destinations::byCategory();
$all = wgOrdered();
$n8n = Destinations::get('n8n');
$generic = Destinations::get('generic-json');
$ver = static fn (string $f): string => (string) (@filemtime(__DIR__ . '/../' . $f) ?: 1);
$nonce = htmlspecialchars($csp_nonce ?? '', ENT_QUOTES);
$allowed_networks = rivetWebhookAllowedNetworks($mysqli);
$add = static fn (string $id): string => 'webhook_form.php?destination=' . urlencode($id);
?>
<link rel="stylesheet" href="/css/webhook_guides.css?v=<?= $ver('css/webhook_guides.css') ?>">
<a class="wg-skip" href="#wg-main">Skip to the guide</a>

<div class="wg" id="wg" data-wg>

    <header class="wg-hero" aria-labelledby="wg-title">
        <div class="wg-hero-top">
            <div class="wg-hero-text">
                <h3 class="wg-title" id="wg-title"><i class="fas fa-fw fa-book-open me-2" aria-hidden="true"></i>Webhook guides</h3>
                <p class="wg-pitch">Connect <?= webhookH($app) ?> events to n8n, Home Assistant, ntfy, Discord and more.</p>
                <p class="wg-lede">What to create on the other side, what to paste here, what to watch out for, a sample request and how to verify our signature. The same guide appears beside the form when you add a webhook.</p>
            </div>
            <nav class="wg-quick" aria-label="Quick links">
                <a class="wg-quick-link" href="#signing"><i class="fas fa-fw fa-shield-alt" aria-hidden="true"></i>How signing works</a>
                <a class="wg-quick-link" href="#networks"><i class="fas fa-fw fa-network-wired" aria-hidden="true"></i>Allowed internal networks</a>
                <a class="wg-quick-link wg-quick-primary" href="webhook_form.php"><i class="fas fa-fw fa-plus" aria-hidden="true"></i>Add a webhook</a>
                <a class="wg-quick-link" href="settings_webhooks.php"><i class="fas fa-fw fa-arrow-left" aria-hidden="true"></i>Back to webhooks</a>
            </nav>
        </div>
        <ol class="wg-flow" aria-label="How it works">
            <li><span class="wg-flow-ico"><i class="fas fa-bolt" aria-hidden="true"></i></span><strong>Event</strong><span>Something happens, such as <code>ticket.created</code>.</span></li>
            <li><span class="wg-flow-ico"><i class="fas fa-puzzle-piece" aria-hidden="true"></i></span><strong>Format</strong><span>The body is shaped for the platform you chose.</span></li>
            <li><span class="wg-flow-ico"><i class="fas fa-signature" aria-hidden="true"></i></span><strong>Sign</strong><span>An HMAC proves it came from us.</span></li>
            <li><span class="wg-flow-ico"><i class="fas fa-paper-plane" aria-hidden="true"></i></span><strong>Deliver</strong><span>Sent over HTTPS to your address.</span></li>
            <li><span class="wg-flow-ico"><i class="fas fa-redo" aria-hidden="true"></i></span><strong>Retry</strong><span>Failures are retried with a growing delay.</span></li>
        </ol>
    </header>

    <div class="wg-layout">
        <aside class="wg-side" aria-label="Guide navigation">
            <button type="button" class="wg-side-toggle" data-wg-side-toggle aria-expanded="false" aria-controls="wg-side-panel"><i class="fas fa-fw fa-list" aria-hidden="true"></i><span data-wg-side-label>All guides</span><i class="fas fa-chevron-down wg-chev" aria-hidden="true"></i></button>
            <div class="wg-side-panel" id="wg-side-panel">
                <div class="wg-search">
                    <i class="fas fa-search wg-search-icon" aria-hidden="true"></i>
                    <label class="visually-hidden" for="wg-search">Search platforms</label>
                    <input type="search" id="wg-search" class="form-control" placeholder="Search platforms" autocomplete="off" data-wg-search aria-controls="wg-nav wg-grid">
                </div>
                <div class="wg-chips" role="group" aria-label="Filter by category">
                    <button type="button" class="wg-chip is-active" data-wg-cat="all" aria-pressed="true">All</button>
                    <?php foreach ($groups as $cat => $items) { ?>
                        <button type="button" class="wg-chip" data-wg-cat="<?= webhookH($cat) ?>" aria-pressed="false"><?= webhookH($labels[$cat] ?? $cat) ?></button>
                    <?php } ?>
                </div>
                <nav class="wg-nav" id="wg-nav" aria-label="Platforms">
                    <div class="wg-nav-group" data-wg-static>
                        <div class="wg-nav-title">Basics</div>
                        <a class="wg-nav-link" href="#signing" data-wg-link="signing"><i class="fas fa-fw fa-shield-alt" aria-hidden="true"></i><span>Signing and verifying</span></a>
                        <a class="wg-nav-link" href="#networks" data-wg-link="networks"><i class="fas fa-fw fa-network-wired" aria-hidden="true"></i><span>Allowed internal networks</span></a>
                        <?php if ($n8n) { ?><a class="wg-nav-link" href="#receiving-in-n8n" data-wg-link="receiving-in-n8n"><i class="fas fa-fw fa-project-diagram" aria-hidden="true"></i><span>Receiving in n8n</span></a><?php } ?>
                    </div>
                    <?php foreach ($groups as $cat => $items) { ?>
                        <div class="wg-nav-group" data-wg-group="<?= webhookH($cat) ?>">
                            <div class="wg-nav-title"><i class="fas fa-fw <?= webhookH(wgCategoryIcon($cat)) ?>" aria-hidden="true"></i><?= webhookH($labels[$cat] ?? $cat) ?></div>
                            <?php foreach ($items as $d) { ?>
                                <a class="wg-nav-link" href="#<?= webhookH($d->id) ?>" data-wg-link="<?= webhookH($d->id) ?>" data-wg-cat-of="<?= webhookH($cat) ?>"
                                   data-wg-hay="<?= webhookH(strtolower($d->id . ' ' . $d->name . ' ' . $d->description . ' ' . $d->format . ' ' . ($labels[$cat] ?? $cat))) ?>"><?= wgBadgeHtml($d, 'xs') ?><span><?= webhookH($d->name) ?></span></a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <p class="wg-nav-empty" data-wg-empty hidden role="status">No platform matches. Use Generic JSON or Custom template for other services.</p>
                </nav>
            </div>
        </aside>

        <main class="wg-main" id="wg-main" tabindex="-1">

            <div class="wg-view" data-wg-view="index">
                <section class="wg-card wg-section" id="signing" aria-labelledby="signing-h">
                    <h4 class="wg-h2" id="signing-h"><i class="fas fa-fw fa-shield-alt me-2" aria-hidden="true"></i>Signing and verifying</h4>
                    <p>Every request carries a signature so the receiver can tell it really came from <?= webhookH($app) ?> and was not changed or replayed. Verifying is optional but recommended. It needs the <strong>signing secret</strong> you set (or generated) on the webhook.</p>
                    <div class="wg-sig-grid">
                        <div class="wg-sig">
                            <span class="wg-pill wg-pill-ok">Current</span>
                            <h5><code class="wg-inline">X-Rivet-Signature-V2</code></h5>
                            <p>Value <code class="wg-inline">t=&lt;unix time&gt;,v1=&lt;hex&gt;</code>. The hex is an HMAC-SHA256, keyed with your secret, over the text <code class="wg-inline">&lt;timestamp&gt;.&lt;raw body&gt;</code>. Because the time is signed, an old request cannot be replayed. The same time is also sent as <code class="wg-inline">X-Rivet-Timestamp</code>.</p>
                        </div>
                        <div class="wg-sig">
                            <span class="wg-pill">Legacy (V1)</span>
                            <h5><code class="wg-inline">X-RivetMSP-Signature</code> / <code class="wg-inline">X-ITFlow-Signature</code></h5>
                            <p>An HMAC-SHA256 of the body only, without a timestamp. Kept so existing receivers keep working. New receivers should use V2.</p>
                        </div>
                    </div>
                    <ol class="wg-mini-steps">
                        <li>Read the <strong>raw</strong> body bytes, before any JSON parsing.</li>
                        <li>Split the V2 header into the time <code class="wg-inline">t</code> and the signature <code class="wg-inline">v1</code>.</li>
                        <li>Reject it when <code class="wg-inline">t</code> is more than <strong>5 minutes (300 seconds)</strong> from your clock. That is the tolerance window.</li>
                        <li>Compute the HMAC-SHA256 of <code class="wg-inline">t + "." + body</code> with your secret and compare it to <code class="wg-inline">v1</code> with a constant-time function.</li>
                    </ol>
                    <?php if ($generic && $generic->verifySnippets) { ?>
                        <h5 class="wg-h3">Verification snippets</h5>
                        <?php wgSnippetTabs($generic->verifySnippets, 'signing'); ?>
                    <?php } ?>
                    <div class="wg-callout wg-callout-info" role="note"><i class="fas fa-info-circle" aria-hidden="true"></i><div>Chat and push platforms such as Discord, Slack, Telegram, ntfy or Home Assistant cannot run verification code. For those the address itself is the secret, so keep it private. Each platform guide says which applies.</div></div>
                </section>

                <section class="wg-card wg-section" id="networks" aria-labelledby="networks-h">
                    <h4 class="wg-h2" id="networks-h"><i class="fas fa-fw fa-network-wired me-2" aria-hidden="true"></i>Allowed internal networks</h4>
                    <p>To keep a webhook from being turned against your own network, deliveries may only go to <strong>public addresses</strong> plus the internal networks an administrator lists. If your receiver (n8n, Home Assistant, Gotify...) lives on your LAN, add that network first, otherwise the delivery log shows <code class="wg-inline">endpoint URL not allowed</code>.</p>
                    <ul class="wg-facts-list">
                        <li><i class="fas fa-check text-success" aria-hidden="true"></i>Private ranges can be listed: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10 and fc00::/7.</li>
                        <li><i class="fas fa-times text-danger" aria-hidden="true"></i>Never allowed: loopback (127.0.0.0/8), link-local (169.254.0.0/16) and cloud-metadata addresses.</li>
                        <li><i class="fas fa-lock" aria-hidden="true"></i>The address is checked again on every attempt, and the connection is pinned to the vetted address.</li>
                    </ul>
                    <p class="wg-networks-now"><?= $allowed_networks ? 'Currently allowed: ' . implode(' ', array_map(static fn ($n) => '<code class="wg-inline">' . webhookH($n) . '</code>', $allowed_networks)) . '.' : 'No internal network is allowed right now, so only public addresses can be reached.' ?></p>
                    <a class="wg-btn wg-btn-primary" href="settings_webhooks.php#internal-networks"><i class="fas fa-fw fa-network-wired" aria-hidden="true"></i>Open Internal network access</a>
                </section>

                <section class="wg-section" id="platforms" aria-labelledby="platforms-h">
                    <h4 class="wg-h2" id="platforms-h"><i class="fas fa-fw fa-th-large me-2" aria-hidden="true"></i>Platforms <span class="wg-count" data-wg-count><?= count($all) ?></span></h4>
                    <div class="wg-grid" id="wg-grid">
                        <?php foreach ($all as $d) {
                            $cat = $d->category; ?>
                            <article class="wg-tile" data-wg-tile="<?= webhookH($d->id) ?>" data-wg-cat-of="<?= webhookH($cat) ?>"
                                     data-wg-hay="<?= webhookH(strtolower($d->id . ' ' . $d->name . ' ' . $d->description . ' ' . $d->format . ' ' . ($labels[$cat] ?? $cat))) ?>">
                                <div class="wg-tile-head">
                                    <?= wgBadgeHtml($d, 'md') ?>
                                    <div class="wg-tile-title"><h5><?= webhookH($d->name) ?></h5><span class="wg-cat wg-cat-<?= webhookH($cat) ?>"><?= webhookH($labels[$cat] ?? $cat) ?></span></div>
                                </div>
                                <p class="wg-tile-desc"><?= webhookH($d->description) ?></p>
                                <p class="wg-needs"><span class="wg-needs-label">Needs:</span> <?php foreach (wgNeeds($d) as $n) { ?><span class="wg-pill"><?= webhookH($n) ?></span> <?php } ?></p>
                                <div class="wg-tile-actions">
                                    <a class="wg-btn" href="#<?= webhookH($d->id) ?>" aria-label="View the <?= webhookH($d->name) ?> guide">View guide</a>
                                    <a class="wg-btn wg-btn-primary" href="<?= webhookH($add($d->id)) ?>" aria-label="Add a <?= webhookH($d->name) ?> webhook"><i class="fas fa-plus" aria-hidden="true"></i>Add webhook</a>
                                </div>
                            </article>
                        <?php } ?>
                    </div>
                    <p class="wg-nav-empty" data-wg-empty hidden role="status">No platform matches. Use Generic JSON or Custom template for other services.</p>
                </section>
            </div>

            <?php if ($n8n) { ?>
            <section class="wg-card wg-section wg-guide" id="receiving-in-n8n" data-wg-view="page" aria-labelledby="n8n-walk-h">
                <div class="wg-guide-head">
                    <?= wgBadgeHtml($n8n, 'lg') ?>
                    <div class="wg-guide-title"><h4 class="wg-h1" id="n8n-walk-h">Receiving in n8n</h4><div class="wg-guide-meta"><span class="wg-cat wg-cat-automation">Walk-through</span></div></div>
                    <div class="wg-guide-cta"><a class="wg-btn wg-btn-primary" href="<?= webhookH($add('n8n')) ?>"><i class="fas fa-plus" aria-hidden="true"></i>Add the n8n webhook</a></div>
                </div>
                <p class="wg-lede">n8n is the most common place to send these events: a workflow can open a ticket in another tool, post to chat, write a row to a sheet, or page someone. This walk-through takes about five minutes. The short reference guide is under <a href="#n8n">n8n</a>.</p>
                <ol class="wg-steps wg-steps-plain">
                    <li><span class="wg-num" aria-hidden="true">1</span><div><strong>Create the trigger.</strong> In n8n add a <em>Webhook</em> node as the first node. Set <em>HTTP Method</em> to <code class="wg-inline">POST</code> (the default is GET) and give it a <em>Path</em>, for example <code class="wg-inline">rivet-events</code>.</div></li>
                    <li><span class="wg-num" aria-hidden="true">2</span><div><strong>Keep the raw body.</strong> In the node's <em>Options</em> add <em>Raw Body</em> and turn it on. n8n parses JSON for you, but signatures are computed over the exact bytes, so this is what makes verification possible.</div></li>
                    <li><span class="wg-num" aria-hidden="true">3</span><div><strong>Copy the Production URL.</strong> It contains <code class="wg-inline">/webhook/</code>. The <code class="wg-inline">/webhook-test/</code> URL only accepts one call after you press <em>Listen for test event</em>; it is for building the workflow, not for production.</div></li>
                    <li><span class="wg-num" aria-hidden="true">4</span><div><strong>Add the webhook here.</strong> <em>Administration &gt; Webhooks &gt; Add webhook &gt; n8n</em>. Paste the URL, press <em>Generate</em> for a signing secret and copy it somewhere you can reach from n8n, and pick the events (for example <code class="wg-inline">ticket.*</code>).</div></li>
                    <li><span class="wg-num" aria-hidden="true">5</span><div><strong>Optional extra lock.</strong> If you set <em>Authentication &gt; Header Auth</em> on the Webhook node, choose <em>Custom header</em> here with the same header name and value. n8n will then reject anything without it, even before signatures.</div></li>
                    <li><span class="wg-num" aria-hidden="true">6</span><div><strong>Verify our signature (recommended).</strong> Add a <em>Code</em> node right after the Webhook node and paste the snippet below. It needs <code class="wg-inline">NODE_FUNCTION_ALLOW_BUILTIN=crypto</code> on the n8n server and your signing secret (an environment variable named <code class="wg-inline">RIVET_WEBHOOK_SECRET</code>, or pasted into the code).</div></li>
                    <li><span class="wg-num" aria-hidden="true">7</span><div><strong>Read the data.</strong> The event is under <code class="wg-inline">$json.body</code>: <code class="wg-inline">{{ $json.body.event }}</code> (such as <code class="wg-inline">ticket.created</code>), <code class="wg-inline">{{ $json.body.timestamp }}</code> and <code class="wg-inline">{{ $json.body.data.ticket_subject }}</code>. Headers are under <code class="wg-inline">$json.headers</code>.</div></li>
                    <li><span class="wg-num" aria-hidden="true">8</span><div><strong>Activate and test.</strong> Switch the workflow to <em>Active</em>, then use <em>Send test</em> in the form or on the webhook list. The run appears in n8n under <em>Executions</em>, and one <code class="wg-inline">test</code> entry is added to the delivery log here.</div></li>
                    <li><span class="wg-num" aria-hidden="true">9</span><div><strong>Behind a firewall?</strong> If n8n is on your own network, add that network under <a href="settings_webhooks.php#internal-networks">Internal network access</a> first, otherwise private addresses are refused.</div></li>
                </ol>
                <?php if (isset($n8n->verifySnippets['n8n-code'])) { ?>
                    <h5 class="wg-h3">n8n Code node: verify the signature</h5>
                    <?php wgCode('n8n-walkthrough-code', $n8n->verifySnippets['n8n-code'], 'javascript', 'n8n Code node', 'Copy the n8n Code node snippet'); ?>
                <?php } ?>
                <h5 class="wg-h3">Good to know</h5>
                <?php foreach ($n8n->notes as $note) { $lvl = wgNoteLevel($note); ?>
                    <div class="wg-callout wg-callout-<?= $lvl ?>" role="note"><i class="fas <?= webhookH(wgCalloutIcons()[$lvl]) ?>" aria-hidden="true"></i><div><?= wgInline($note) ?></div></div>
                <?php } ?>
            </section>
            <?php } ?>

            <?php foreach ($all as $idx => $d) {
                $cat = $d->category;
                $pid = 'g-' . preg_replace('/[^a-z0-9]+/', '-', $d->id);
                $payload = wgSamplePayload($d);
                $prev = $all[$idx - 1] ?? null;
                $next = $all[$idx + 1] ?? null;
                $sections = [['need', 'What you need'], ['steps', 'Set it up']];
                if ($d->notes) { $sections[] = ['notes', 'Good to know']; }
                $sections[] = ['try', 'Try it'];
                if ($payload) { $sections[] = ['payload', 'Example payload']; }
                $sections[] = ['verify', 'Verify our signature'];
                $sections[] = ['trouble', 'Troubleshooting'];
            ?>
            <article class="wg-card wg-section wg-guide" id="<?= webhookH($d->id) ?>" data-wg-view="page" data-wg-guide="<?= webhookH($d->id) ?>" data-wg-name="<?= webhookH($d->name) ?>" aria-labelledby="<?= $pid ?>-h">
                <div class="wg-guide-head">
                    <?= wgBadgeHtml($d, 'lg') ?>
                    <div class="wg-guide-title">
                        <h4 class="wg-h1" id="<?= $pid ?>-h"><?= webhookH($d->name) ?></h4>
                        <div class="wg-guide-meta">
                            <span class="wg-cat wg-cat-<?= webhookH($cat) ?>"><?= webhookH($labels[$cat] ?? $cat) ?></span>
                            <span class="wg-pill" title="Body format">Format: <code><?= webhookH($d->format) ?></code></span>
                            <?php if (preg_match('#^https?://#', $d->docsUrl)) { ?><a class="wg-doclink" href="<?= webhookH($d->docsUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt" aria-hidden="true"></i><?= webhookH($d->name) ?> documentation<span class="visually-hidden"> (opens in a new tab)</span></a><?php } ?>
                        </div>
                    </div>
                    <div class="wg-guide-cta"><a class="wg-btn wg-btn-primary" href="<?= webhookH($add($d->id)) ?>"><i class="fas fa-plus" aria-hidden="true"></i>Add <?= webhookH($d->name) ?> webhook</a></div>
                </div>
                <p class="wg-lede"><?= webhookH($d->description) ?></p>

                <div class="wg-guide-body">
                    <nav class="wg-toc" aria-label="On this page: <?= webhookH($d->name) ?>">
                        <div class="wg-toc-title">On this page</div>
                        <ul>
                            <?php foreach ($sections as [$sid, $slabel]) { ?><li><a href="#<?= webhookH($d->id) ?>" data-wg-jump="<?= $pid ?>-<?= $sid ?>"><?= webhookH($slabel) ?></a></li><?php } ?>
                        </ul>
                    </nav>
                    <div class="wg-guide-content">
                        <dl class="wg-facts">
                            <div><dt>Body format</dt><dd><code><?= webhookH($d->format) ?></code> sent with <code><?= webhookH($d->method) ?></code></dd></div>
                            <div><dt>Address looks like</dt><dd><code><?= webhookH($d->urlHint) ?></code></dd></div>
                            <div><dt>Authentication</dt><dd><?php foreach ($d->authModes as $m) { ?><span class="wg-pill<?= $m === $d->defaultAuth ? ' wg-pill-ok' : '' ?>"><?= webhookH(wgAuthLabel($m)) ?><?= $m === $d->defaultAuth ? ' (default)' : '' ?></span> <?php } ?></dd></div>
                            <?php if ($d->extraFields) { ?><div><dt>Extra fields</dt><dd><?= webhookH(implode(', ', array_map(static fn ($f) => $f->label . ($f->required ? ' (required)' : ''), $d->extraFields))) ?></dd></div><?php } ?>
                        </dl>

                        <section class="wg-block" id="<?= $pid ?>-need" aria-labelledby="<?= $pid ?>-need-h">
                            <h5 class="wg-h3" id="<?= $pid ?>-need-h"><i class="fas fa-fw fa-clipboard-check" aria-hidden="true"></i>What you need</h5>
                            <ul class="wg-checklist">
                                <?php foreach (wgChecklist($d) as $item) { ?><li><i class="fas fa-check-circle" aria-hidden="true"></i><span><?= wgInline($item) ?></span></li><?php } ?>
                            </ul>
                        </section>

                        <section class="wg-block" id="<?= $pid ?>-steps" aria-labelledby="<?= $pid ?>-steps-h">
                            <div class="wg-block-head">
                                <h5 class="wg-h3" id="<?= $pid ?>-steps-h"><i class="fas fa-fw fa-list-ol" aria-hidden="true"></i>Set it up</h5>
                                <span class="wg-progress" data-wg-progress aria-live="polite"></span>
                                <button type="button" class="wg-btn-sm" data-wg-reset hidden>Reset</button>
                            </div>
                            <ol class="wg-steps" data-wg-steps="<?= webhookH($d->id) ?>">
                                <?php foreach ($d->setupSteps as $i => $step) { ?>
                                    <li class="wg-step">
                                        <span class="wg-num" aria-hidden="true"><?= $i + 1 ?></span>
                                        <div class="wg-step-text"><?= wgInline($step) ?></div>
                                        <label class="wg-tick"><input type="checkbox" data-wg-tick="<?= $i ?>"><span>Done<span class="visually-hidden">: step <?= $i + 1 ?></span></span></label>
                                    </li>
                                <?php } ?>
                            </ol>
                            <?php if ($d->id === 'n8n') { ?><p class="wg-aside"><i class="fas fa-lightbulb" aria-hidden="true"></i>Prefer a longer walk-through with the signature check? See <a href="#receiving-in-n8n">Receiving in n8n</a>.</p><?php } ?>
                        </section>

                        <?php if ($d->notes) { ?>
                        <section class="wg-block" id="<?= $pid ?>-notes" aria-labelledby="<?= $pid ?>-notes-h">
                            <h5 class="wg-h3" id="<?= $pid ?>-notes-h"><i class="fas fa-fw fa-lightbulb" aria-hidden="true"></i>Good to know</h5>
                            <?php foreach ($d->notes as $note) { $lvl = wgNoteLevel($note); ?>
                                <div class="wg-callout wg-callout-<?= $lvl ?>" role="note"><i class="fas <?= webhookH(wgCalloutIcons()[$lvl]) ?>" aria-hidden="true"></i><div><span class="visually-hidden"><?= ucfirst($lvl) ?>: </span><?= wgInline($note) ?></div></div>
                            <?php } ?>
                        </section>
                        <?php } ?>

                        <section class="wg-block wg-try" id="<?= $pid ?>-try" aria-labelledby="<?= $pid ?>-try-h">
                            <h5 class="wg-h3" id="<?= $pid ?>-try-h"><i class="fas fa-fw fa-terminal" aria-hidden="true"></i>Try it</h5>
                            <p class="wg-muted">A sample request in the shape <?= webhookH($d->name) ?> expects. Replace the placeholders; <code class="wg-inline">&lt;token&gt;</code> style values are your own.</p>
                            <?php wgCode($pid . '-curl', $d->sampleCurl, 'bash', 'curl', 'Copy the sample curl command'); ?>
                            <div class="wg-try-cta">
                                <a class="wg-btn wg-btn-primary wg-btn-lg" href="<?= webhookH($add($d->id)) ?>"><i class="fas fa-plus" aria-hidden="true"></i>Add this webhook</a>
                                <span class="wg-muted">Opens the add form with <?= webhookH($d->name) ?> preselected. Use <em>Send test</em> there to check the connection.</span>
                            </div>
                        </section>

                        <?php if ($payload) { ?>
                        <section class="wg-block" id="<?= $pid ?>-payload" aria-labelledby="<?= $pid ?>-payload-h">
                            <details class="wg-details">
                                <summary><h5 class="wg-h3" id="<?= $pid ?>-payload-h"><i class="fas fa-fw fa-file-code" aria-hidden="true"></i>Example payload</h5><span class="wg-muted">What <?= webhookH($d->name) ?> receives for a sample <code class="wg-inline">ticket.created</code> event</span></summary>
                                <div class="wg-details-body">
                                    <p class="wg-muted">Content-Type <code class="wg-inline"><?= webhookH($payload['content_type']) ?></code><?php foreach ($payload['headers'] as $hk => $hv) { ?>, <code class="wg-inline"><?= webhookH($hk . ': ' . $hv) ?></code><?php } ?>. Generated by the same formatter that sends real events; sample data only.</p>
                                    <?php wgCode($pid . '-payload-code', $payload['body'], $payload['lang'], $payload['lang'] === 'json' ? 'JSON body' : 'Body', 'Copy the example payload'); ?>
                                </div>
                            </details>
                        </section>
                        <?php } ?>

                        <section class="wg-block" id="<?= $pid ?>-verify" aria-labelledby="<?= $pid ?>-verify-h">
                            <h5 class="wg-h3" id="<?= $pid ?>-verify-h"><i class="fas fa-fw fa-shield-alt" aria-hidden="true"></i>Verify our signature</h5>
                            <?php if ($d->verifySnippets) { ?>
                                <p class="wg-muted">Every request carries <code class="wg-inline">X-Rivet-Signature-V2</code>: an HMAC-SHA256 of <code class="wg-inline">&lt;timestamp&gt;.&lt;raw body&gt;</code> with your signing secret. Reject it when it does not match or is older than 5 minutes. <a href="#signing">How signing works</a>.</p>
                                <?php wgSnippetTabs($d->verifySnippets, $pid); ?>
                            <?php } else { ?>
                                <div class="wg-callout wg-callout-info" role="note"><i class="fas fa-info-circle" aria-hidden="true"></i><div><?= webhookH($d->name) ?> cannot verify our signature (the receiver does not expose the raw request body), so keep the address private. <a href="#signing">How signing works</a>.</div></div>
                            <?php } ?>
                        </section>

                        <section class="wg-block" id="<?= $pid ?>-trouble" aria-labelledby="<?= $pid ?>-trouble-h">
                            <h5 class="wg-h3" id="<?= $pid ?>-trouble-h"><i class="fas fa-fw fa-life-ring" aria-hidden="true"></i>Troubleshooting</h5>
                            <div class="wg-table-wrap" tabindex="0" role="region" aria-label="Troubleshooting table for <?= webhookH($d->name) ?>">
                                <table class="wg-table">
                                    <thead><tr><th scope="col">Symptom</th><th scope="col">Likely cause</th><th scope="col">Fix</th></tr></thead>
                                    <tbody>
                                    <?php foreach (wgTroubleshooting($d) as [$sym, $cause, $fix, $specific]) { ?>
                                        <tr<?= $specific ? ' class="wg-row-specific"' : '' ?>>
                                            <th scope="row"><?= $specific ? '<span class="wg-pill wg-pill-ok">' . webhookH($d->name) . '</span> ' : '' ?><?= webhookH($sym) ?></th>
                                            <?php if ($specific) { ?><td colspan="2"><?= wgInline($cause) ?></td><?php } else { ?><td><?= webhookH($cause) ?></td><td><?= webhookH($fix) ?></td><?php } ?>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <nav class="wg-pager" aria-label="Previous and next guide">
                            <?php if ($prev) { ?><a class="wg-pager-link" rel="prev" href="#<?= webhookH($prev->id) ?>"><span class="wg-pager-dir"><i class="fas fa-arrow-left" aria-hidden="true"></i>Previous</span><span class="wg-pager-name"><?= webhookH($prev->name) ?></span></a><?php } else { ?><span></span><?php } ?>
                            <?php if ($next) { ?><a class="wg-pager-link wg-pager-next" rel="next" href="#<?= webhookH($next->id) ?>"><span class="wg-pager-dir">Next<i class="fas fa-arrow-right" aria-hidden="true"></i></span><span class="wg-pager-name"><?= webhookH($next->name) ?></span></a><?php } ?>
                        </nav>
                    </div>
                </div>
            </article>
            <?php } ?>
        </main>
    </div>
</div>
<script src="/js/webhook_guides.js?v=<?= $ver('js/webhook_guides.js') ?>" nonce="<?= $nonce ?>" defer></script>

<?php require_once "../includes/footer.php"; ?>
