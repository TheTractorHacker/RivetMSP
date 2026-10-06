<?php
/*
 * Administration > Webhooks > Guides: every platform preset with its full setup guide, rendered from the RivetCore catalog
 * (the same renderer as the side panel of the add/edit form), plus a "Receiving in n8n" walk-through.
 */
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_form_lib.php";
require_once "includes/webhook_guide.php";

use RivetCore\Webhooks\Destinations;

$n8n = Destinations::get('n8n');
$app = defined('APP_NAME') ? APP_NAME : 'RivetMSP';
?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-book me-2"></i>Webhook guides</h3>
        <a href="webhook_form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add webhook</a>
        <a href="settings_webhooks.php" class="btn btn-light btn-sm">Back to webhooks</a>
    </div>
    <div class="card-body">
        <p class="text-muted">How to connect <?= webhookH($app) ?> to each supported platform: what to create on the other side, what to paste here, what to watch out for, a sample request and how to verify our signature. The same guide appears beside the form when you add a webhook.</p>
        <nav aria-label="Platforms">
            <?php foreach (Destinations::byCategory() as $cat => $items) { ?>
                <div class="mb-2"><span class="text-uppercase text-muted small me-2"><?= webhookH(Destinations::categoryLabels()[$cat] ?? $cat) ?></span>
                    <?php foreach ($items as $d) { ?><a class="badge text-bg-light border me-1" href="#<?= webhookH($d->id) ?>"><?= webhookH($d->name) ?></a><?php } ?></div>
            <?php } ?>
            <div><a class="badge text-bg-info me-1" href="#receiving-in-n8n">Receiving in n8n (walk-through)</a></div>
        </nav>
    </div>
</div>

<?php if ($n8n) { ?>
<section class="card mb-3 wh-guide-section" id="receiving-in-n8n">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-project-diagram me-2"></i>Receiving in n8n</h4></div>
    <div class="card-body">
        <p class="text-muted">n8n is the most common place to send these events: a workflow can open a ticket in another tool, post to chat, write a row to a sheet, or page someone. This walk-through takes about five minutes.</p>
        <ol class="wh-steps">
            <li><strong>Create the trigger.</strong> In n8n add a <em>Webhook</em> node as the first node. Set <em>HTTP Method</em> to <code>POST</code> (the default is GET) and give it a <em>Path</em>, for example <code>rivet-events</code>.</li>
            <li><strong>Keep the raw body.</strong> In the node's <em>Options</em> add <em>Raw Body</em> and turn it on. n8n parses JSON for you, but signatures are computed over the exact bytes, so this is what makes verification possible.</li>
            <li><strong>Copy the Production URL.</strong> It contains <code>/webhook/</code>. The <code>/webhook-test/</code> URL only accepts one call after you press <em>Listen for test event</em>; it is for building the workflow, not for production.</li>
            <li><strong>Add the webhook here.</strong> <em>Administration &gt; Webhooks &gt; Add webhook &gt; n8n</em>. Paste the URL, press <em>Generate</em> for a signing secret and copy it somewhere you can reach from n8n, and pick the events (for example <code>ticket.*</code>).</li>
            <li><strong>Optional extra lock.</strong> If you set <em>Authentication &gt; Header Auth</em> on the Webhook node, choose <em>Custom header</em> here with the same header name and value. n8n will then reject anything without it, even before signatures.</li>
            <li><strong>Verify our signature (recommended).</strong> Add a <em>Code</em> node right after the Webhook node and paste the snippet below. It needs <code>NODE_FUNCTION_ALLOW_BUILTIN=crypto</code> on the n8n server and your signing secret (an environment variable named <code>RIVET_WEBHOOK_SECRET</code>, or pasted into the code).</li>
            <li><strong>Read the data.</strong> The event is under <code>$json.body</code>: <code>{{ $json.body.event }}</code> (such as <code>ticket.created</code>), <code>{{ $json.body.timestamp }}</code> and <code>{{ $json.body.data.ticket_subject }}</code>. Headers are under <code>$json.headers</code>.</li>
            <li><strong>Activate and test.</strong> Switch the workflow to <em>Active</em>, then use <em>Send test</em> in the form or on the webhook list. The run appears in n8n under <em>Executions</em>, and one <code>test</code> entry is added to the delivery log here.</li>
            <li><strong>Behind a firewall?</strong> If n8n is on your own network, add that network under <a href="settings_webhooks.php#internal-networks">Internal network access</a> first, otherwise private addresses are refused.</li>
        </ol>
        <?php if (isset($n8n->verifySnippets['n8n-code'])) { ?>
            <h6 class="wh-h">n8n Code node: verify the signature</h6>
            <?php webhookCodeBlock('n8n-walkthrough-code', $n8n->verifySnippets['n8n-code'], 'Copy the n8n Code node snippet'); ?>
        <?php } ?>
        <h6 class="wh-h mt-3">Good to know</h6>
        <ul class="wh-notes">
            <?php foreach ($n8n->notes as $n) { ?><li><?= webhookH($n) ?></li><?php } ?>
        </ul>
    </div>
</section>
<?php } ?>

<?php foreach (Destinations::byCategory() as $cat => $items) {
    foreach ($items as $d) { ?>
<section class="card mb-3 wh-guide-section" id="<?= webhookH($d->id) ?>">
    <div class="card-header py-3 d-flex flex-wrap align-items-center gap-2">
        <span class="wh-card-icon" aria-hidden="true"><i class="fas <?= webhookH(rivetWebhookIcon($d->id, $d->category)) ?>"></i></span>
        <h4 class="card-title me-auto mb-0"><?= webhookH($d->name) ?> <small class="text-muted fw-normal ms-1"><?= webhookH(Destinations::categoryLabels()[$cat] ?? $cat) ?></small></h4>
        <a href="webhook_form.php?destination=<?= webhookH(urlencode($d->id)) ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-plus me-1"></i>Add <?= webhookH($d->name) ?> webhook</a>
    </div>
    <div class="card-body">
        <?php webhookGuideRender($d, true, 'guides'); ?>
    </div>
</section>
<?php } } ?>

<?php require_once "../includes/footer.php"; ?>
