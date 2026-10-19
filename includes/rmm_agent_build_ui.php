<?php

/*
 * Agent updates > "Build a new version" (26.10.31): pure rendering of RivetMsp\AgentFeed\Build::view(). Everything dynamic is escaped where it is printed. The token field is
 * write-only: the page never contains a stored token, not even a part of it.
 */

require_once __DIR__ . '/rmm_agent_localbuild_ui.php';

/**
 * @param array<string,mixed> $bv Build::view()
 * @param array<string,mixed>|null $local 26.10.33: LocalBuild view; when given, the box offers the provider choice (GitHub | your own build host)
 */
function rivetAgentBuildBox(array $bv, string $csrf, ?array $local = null): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $repo = (string) $bv['repo'];
    $wf = (string) $bv['workflow_url'];
    $b = $bv['build'];
    $o = '<div class="border rounded p-3 mb-3" id="agent-build" data-build-box data-csrf="' . $h($csrf) . '" data-poll-url="/admin/post/agent_build_poll.php" data-build-active="' . ($b !== null && $b['watch'] ? '1' : '0') . '">'
        . '<h5 class="mb-1"><i class="fas fa-hammer me-1" aria-hidden="true"></i>Build a new version</h5>'
        . '<p class="small text-muted mb-2">Builds the Windows and Linux agents and the MSI from the <code>main</code> branch of <strong>' . $h($repo) . '</strong> on GitHub and publishes them there as a release. '
        . 'Latest known: <strong>' . ($bv['latest'] !== null ? $h($bv['latest']) : 'none') . '</strong>. A version with a suffix (for example <code>0.9.2-rc1</code>) becomes a pre-release.</p>';

    $hasLocal = $local !== null;
    if ($hasLocal) {
        $pick = $local['default'] ?? 'github';
        $o .= '<fieldset class="mb-3" data-bp-choice><legend class="form-label small mb-1">Where should the build run?</legend><div class="btn-group" role="group" aria-label="Build provider">'
            . '<input type="radio" class="btn-check" name="build_provider" id="bp_gh" value="github" autocomplete="off"' . ($pick === 'github' ? ' checked' : '') . '><label class="btn btn-sm btn-outline-primary" for="bp_gh"><i class="fab fa-github me-1" aria-hidden="true"></i>Build on GitHub</label>'
            . '<input type="radio" class="btn-check" name="build_provider" id="bp_lo" value="local" autocomplete="off"' . ($pick === 'local' ? ' checked' : '') . '><label class="btn btn-sm btn-outline-primary" for="bp_lo"><i class="fas fa-server me-1" aria-hidden="true"></i>Build on my build host</label></div></fieldset>'
            . '<div data-bp-panel="github">';
    }
    if ($b !== null) {
        $cls = ['queued' => 'secondary', 'building' => 'primary', 'finished' => 'success', 'failed' => 'danger', 'lost' => 'warning'][$b['state']] ?? 'secondary';
        $o .= '<div class="alert alert-' . ($cls === 'secondary' || $cls === 'primary' ? 'info' : $cls) . ' py-2" role="status" data-build-status data-build-state="' . $h($b['state']) . '">'
            . 'Build of <strong>' . $h($b['version']) . '</strong>: <span class="badge bg-' . $cls . '" data-build-label>' . $h($b['label']) . '</span> <span data-build-detail>' . $h($b['detail']) . '</span> '
            . '<a href="' . $h($b['run_url'] !== '' ? $b['run_url'] : $b['workflow_url']) . '" rel="noopener noreferrer" target="_blank" data-build-run>' . ($b['run_url'] !== '' ? 'Open the run on GitHub' : 'Open the workflow on GitHub') . '</a>'
            . ($b['auto'] ? '<div class="small mt-1" data-build-auto>Publish automatically when built (' . ($b['ring'] !== '' ? $h($b['ring']) . ' ring, ' . (int) $b['pct'] . '%' : 'no update offer') . ')'
                . ($b['published'] ? ': ' . $h($b['publish_message'] !== '' ? $b['publish_message'] : 'done') : '') . '.</div>' : '')
            . '</div>';
    }

    if (!$bv['has_token']) {
        $o .= '<div class="alert alert-secondary py-2 small mb-2" role="note" data-build-notoken>No GitHub token is saved, so this server cannot start the build for you. You can still start it on GitHub in three steps:</div>'
            . '<ol class="small" data-build-fallback><li>Press <strong>Start the build on GitHub</strong>, then <strong>Run workflow</strong> (the grey button on the right, branch <code>main</code>).</li>'
            . '<li>Type the version in the box (suggested: <code>' . $h($bv['suggest']) . '</code>) and press the green <strong>Run workflow</strong>.</li>'
            . '<li>Come back here when the run is green (about 10 minutes) and press <strong>Check for updates</strong>. The new release appears in the list; press Publish.</li></ol>'
            . '<a class="btn btn-sm btn-primary mb-2" href="' . $h($wf) . '" rel="noopener noreferrer" target="_blank" data-build-github><i class="fab fa-github me-1" aria-hidden="true"></i>Start the build on GitHub</a>';
    } else {
        $o .= '<form action="post.php" method="post" class="row g-2 align-items-end mb-2" autocomplete="off" data-build-form><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small" for="ab_ver">New version</label><input class="form-control form-control-sm font-monospace" id="ab_ver" name="build_version" maxlength="30" required pattern="\d+\.\d+\.\d+(-[0-9A-Za-z][0-9A-Za-z.]*)?" value="' . $h($bv['suggest']) . '" spellcheck="false"></div>'
            . '<div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="ab_auto" name="build_auto" value="1"><label class="form-check-label small" for="ab_auto">Publish automatically when built</label></div>'
            . '<div class="d-flex flex-wrap gap-2 align-items-center mt-1 small"><select name="build_ring" class="form-select form-select-sm w-auto" aria-label="Offer to ring"><option value="">No update offer</option><option value="pilot" selected>Pilot ring</option><option value="stable">Stable ring</option></select>'
            . '<input type="number" name="build_pct" min="0" max="100" value="10" class="form-control form-control-sm" style="width:4.5rem" aria-label="Rollout percent">'
            . '<div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="ab_act" name="build_activate" value="1" checked><label class="form-check-label" for="ab_act">Make current</label></div>'
            . '<span class="text-muted">Used only when the box above is ticked. This is done by this page while it is open, and otherwise by the cron job within five minutes.</span></div></div>'
            . '<div class="col-12"><button type="submit" class="btn btn-sm btn-primary" name="agent_build_start" value="1" data-ea-confirm="Start a build on GitHub for this version? It takes about 10 minutes and creates a public release on ' . $h($repo) . '."><i class="fas fa-hammer me-1" aria-hidden="true"></i>Build</button></div></form>';
    }

    $t = $bv['test'];
    $o .= '<details class="small" id="agent-build-token"' . '' . '><summary class="mb-2">GitHub token for building' . ($bv['has_token'] ? ' <span class="badge bg-success" data-build-token-state>saved</span>' : ' <span class="badge bg-secondary" data-build-token-state>not saved</span>') . '</summary>'
        . '<p class="mb-1"><strong>How to create the token</strong> (once, about two minutes; you need to own or administer <code>' . $h($repo) . '</code> on GitHub):</p>'
        . '<ol><li>On GitHub open <strong>Settings</strong> (your profile menu) &gt; <strong>Developer settings</strong> &gt; <strong>Personal access tokens</strong> &gt; <strong>Fine-grained tokens</strong> &gt; <strong>Generate new token</strong>.</li>'
        . '<li>Name it <em>RivetMSP agent build</em>, choose an expiry (90 days is fine), and under <strong>Repository access</strong> pick <strong>Only select repositories</strong> and choose <code>' . $h($repo) . '</code>.</li>'
        . '<li>Under <strong>Repository permissions</strong> set <strong>Actions</strong> to <strong>Read and write</strong>. Leave everything else at No access.</li>'
        . '<li>Press <strong>Generate token</strong>, copy it (it starts with <code>github_pat_</code>), paste it below and press <strong>Save token</strong>. GitHub shows it only once.</li></ol>'
        . '<form action="post.php" method="post" class="row g-2 align-items-end" autocomplete="off" data-build-token-form><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<div class="col-lg-6"><label class="form-label" for="ab_tok">' . ($bv['has_token'] ? 'Replace the saved token' : 'GitHub token') . '</label><input class="form-control form-control-sm font-monospace" type="password" id="ab_tok" name="gh_token" maxlength="300" autocomplete="new-password" spellcheck="false" placeholder="github_pat_..."></div>'
        . '<div class="col-lg-6 d-flex flex-wrap gap-2"><button class="btn btn-sm btn-outline-primary" type="submit" name="agent_build_token_save" value="1">Save token</button>'
        . ($bv['has_token'] ? '<button class="btn btn-sm btn-outline-secondary" type="submit" name="agent_build_token_test" value="1" formnovalidate>Test token</button>'
            . '<button class="btn btn-sm btn-outline-danger" type="submit" name="agent_build_token_remove" value="1" formnovalidate data-ea-confirm="Remove the saved GitHub token? Builds can then only be started on GitHub.">Remove token</button>' : '') . '</div></form>'
        . '<div class="form-text">The token is stored encrypted, sent only to api.github.com, and never shown again.</div>'
        . ($t !== null ? '<div class="mt-1" data-build-test><span class="badge bg-' . ($t['ok'] ? 'success' : 'danger') . '">' . ($t['ok'] ? 'Test passed' : 'Test failed') . '</span> ' . $h($t['message']) . ' <span class="text-muted">(' . $h(gmdate('Y-m-d H:i', (int) $t['at'])) . ' UTC)</span></div>' : '')
        . '</details>';
    if ($hasLocal) {
        $o .= '</div><div data-bp-panel="local">' . rivetLocalBuildBox($local, $csrf) . '</div>';
    }

    return $o . '</div>';
}
