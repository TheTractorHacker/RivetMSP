<?php

/*
 * Agent updates > "Build on my build host" (26.10.33): pure rendering. Everything dynamic is escaped where it is printed (host names, notes and log tails come from a remote machine).
 * A new build host token is printed ONCE: the handler puts it in the session, this function prints it and removes it.
 */

/** "3 min 05 s" */
function rivetLocalBuildElapsed(int $s): string
{
    return $s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . sprintf('%02d', $s % 60) . ' s';
}

/** The stage checklist (done / active / pending / failed) as list items; the page script rebuilds the same markup on every poll. @param list<array{key:string,label:string,status:string}> $steps */
function rivetLocalBuildSteps(array $steps, callable $h): string
{
    $icon = ['done' => 'fa-check-circle text-success', 'active' => 'fa-circle-notch fa-spin text-primary', 'failed' => 'fa-times-circle text-danger', 'pending' => 'fa-circle text-muted'];
    $o = '';
    foreach ($steps as $s) {
        $o .= '<li data-stage="' . $h($s['key']) . '" data-status="' . $h($s['status']) . '"><i class="fas ' . $icon[$s['status']] . ' me-1" aria-hidden="true"></i>' . $h($s['label']) . '<span class="visually-hidden"> (' . $h($s['status']) . ')</span></li>';
    }

    return $o;
}

/**
 * @param array{ready:bool,hosts:list<array<string,mixed>>,jobs:list<array<string,mixed>>,suggest:string,server_url:string,active:bool,token:?array{name:string,token:string,rotated:bool}} $lv
 */
function rivetLocalBuildBox(array $lv, string $csrf): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $docs = 'https://github.com/TheTractorHacker/rivet-core/blob/main/docs/rmm/BUILD_HOST.md';
    $o = '<div class="mt-3" id="agent-localbuild" data-lb-box data-csrf="' . $h($csrf) . '" data-lb-poll-url="/admin/post/agent_localbuild_poll.php" data-lb-active="' . ($lv['active'] ? '1' : '0') . '">'
        . '<h6 class="mb-1"><i class="fas fa-server me-1" aria-hidden="true"></i>Local build</h6>'
        . '<p class="small text-muted mb-2">A computer you own (Linux with Docker and git) builds the agents and the MSI from the RivetCore source and hands them to this server. '
        . 'It only makes <strong>outbound</strong> requests to this site, so nothing is opened on your network, and this server needs no compiler. Every file is checked here (SHA-256, executable type, architecture) before anything is stored.</p>';
    if (!$lv['ready']) {
        return $o . '<div class="alert alert-warning py-2 mb-0">Run the database update first (Administration &rarr; Updates, DB 2.6.100), then reload this page.</div></div>';
    }
    $t = $lv['token'];
    $cfgSnippet = static function (string $url, string $tok) use ($h): string {
        return '<pre class="small border rounded p-2 mb-1 bg-body-tertiary" data-lb-config style="white-space:pre-wrap">'
            . $h("SERVER_URL=\"$url\"\nTOKEN=\"$tok\"\nCORE_REPO=\"/opt/rivet-core\"\nALLOWED_REFS=\"main v* agent-v*\"\nWORKDIR=\"/var/lib/rivet-build\"") . '</pre>';
    };
    if ($t !== null) {
        $o .= '<div class="alert alert-warning py-2" role="alert" data-lb-token-box><strong>' . $h($t['rotated'] ? 'New token for' : 'Build host added:') . ' ' . $h($t['name']) . '.</strong> '
            . 'Copy the token now. It is shown <strong>once</strong>; this server keeps only a salted hash and cannot show it again (you can create a new one with Rotate).'
            . '<div class="input-group input-group-sm my-2"><input type="text" readonly class="form-control font-monospace" id="lb_new_token" value="' . $h($t['token']) . '" aria-label="Build host token" data-lb-token>'
            . '<button type="button" class="btn btn-outline-secondary" data-lb-copy="lb_new_token"><i class="far fa-copy me-1" aria-hidden="true"></i>Copy token</button></div>'
            . '<div class="small mb-1">Put this in the build host\'s config file (<code>/etc/rivetit/build-host.conf</code>, mode 0600):</div>'
            . $cfgSnippet($lv['server_url'], $t['token'])
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-lb-copy-pre="1"><i class="far fa-copy me-1" aria-hidden="true"></i>Copy config</button></div>';
    }

    // ---- hosts
    $o .= '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1"><strong class="small">Build hosts</strong>'
        . '<form action="post.php" method="post" class="d-flex gap-1" autocomplete="off" data-lb-add-form><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<input class="form-control form-control-sm" style="width:12rem" name="bh_name" maxlength="80" required placeholder="Name, e.g. build-vm-1" aria-label="Build host name">'
        . '<button class="btn btn-sm btn-outline-primary" type="submit" name="agent_bh_add" value="1"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add build host</button></form></div>';
    if ($lv['hosts'] === []) {
        $o .= '<div class="small text-muted mb-2" data-lb-nohost>No build host yet. Press <strong>Add build host</strong>; the token appears once, with the exact settings for the host.</div>';
    } else {
        $o .= '<div class="table-responsive"><table class="table table-sm align-middle mb-2" data-lb-hosts><thead><tr><th>Name</th><th>Status</th><th>Last seen</th><th>Address</th><th></th></tr></thead><tbody>';
        $dots = ['ok' => ['success', 'Online'], 'late' => ['warning', 'Quiet'], 'down' => ['danger', 'Not seen for over an hour'], 'never' => ['secondary', 'Never connected'], 'off' => ['secondary', 'Disabled']];
        foreach ($lv['hosts'] as $r) {
            [$cls, $label] = $dots[$r['dot']] ?? ['secondary', ''];
            $id = (int) $r['host_id'];
            $o .= '<tr data-lb-host="' . $id . '"><td>' . $h($r['host_name']) . ($r['last_version'] ? ' <span class="small text-muted">script ' . $h($r['last_version']) . '</span>' : '') . '</td>'
                . '<td><span class="badge bg-' . $cls . '" data-lb-dot="' . $h($r['dot']) . '"><span class="visually-hidden">' . $h($label) . '</span><i class="fas fa-circle" aria-hidden="true"></i></span> <span class="small">' . $h($label) . '</span></td>'
                . '<td class="small">' . ($r['seen_ago_s'] === null ? 'never' : $h(\RivetMsp\AgentFeed\Feed::ago((int) $r['seen_ago_s']))) . '</td><td class="small font-monospace">' . $h($r['last_ip'] ?? '') . '</td>'
                . '<td class="text-end text-nowrap"><form action="post.php" method="post" class="d-inline-flex gap-1"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="bh_id" value="' . $id . '">'
                . ((int) $r['enabled'] === 1
                    ? '<button class="btn btn-xs btn-outline-secondary" name="agent_bh_disable" value="1" data-ea-confirm="Disable this build host? Its token is refused until you enable it again.">Disable</button>'
                    : '<button class="btn btn-xs btn-outline-primary" name="agent_bh_enable" value="1">Enable</button>')
                . '<button class="btn btn-xs btn-outline-secondary" name="agent_bh_rotate" value="1" data-ea-confirm="Create a new token for this build host? The old token stops working at once; the host needs the new one in its config.">Rotate token</button>'
                . '<button class="btn btn-xs btn-outline-danger" name="agent_bh_delete" value="1" data-ea-confirm="Delete this build host? Its token stops working and any job it holds fails.">Delete</button></form></td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    $setupCmd = 'sudo ' . dirname(__DIR__) . '/deploy/setup-build-host.sh';
    $o .= '<div class="alert alert-info py-2 small mb-2" data-lb-onecmd><strong>Easiest: use this server.</strong> Run this one command on the server that hosts this site (as root). It installs Docker, a locked-down <code>rivetit-build</code> account, the pinned and checksum-verified build script and a timer, creates the build host and its token for you, and checks the connection. Safe to run again; add <code>--uninstall</code> to remove it.'
        . '<div class="input-group input-group-sm mt-1"><input class="form-control font-monospace" id="lb_onecmd" readonly value="' . $h($setupCmd) . '" aria-label="One-line setup command" data-lb-onecmd-input><button type="button" class="btn btn-outline-secondary" data-lb-copy="lb_onecmd">Copy</button></div>'
        . '<div class="mt-1 text-muted">Docker group membership is root-equivalent for that one account; it is never given to the web server. The token goes straight into <code>/etc/rivetit/build-host.conf</code> (mode 600) and is never shown. Add <code>--check</code> to preview. Prefer a separate computer? Use the steps below.</div></div>';
    $o .= '<details class="small mb-3" id="agent-localbuild-setup"><summary>How to set up a build host (about 10 minutes)</summary><ol class="mt-2">'
        . '<li>On a Linux computer that can reach this site over https, install <strong>Docker</strong> and <strong>git</strong>, and clone RivetCore: <code>git clone https://github.com/TheTractorHacker/rivet-core /opt/rivet-core</code>.</li>'
        . '<li>Press <strong>Add build host</strong> above and copy the token (shown once).</li>'
        . '<li>Create <code>/etc/rivetit/build-host.conf</code> (mode 0600, readable by the service user only) with these lines; the address is this site:' . $cfgSnippet($lv['server_url'], 'paste the token here') . '</li>'
        . '<li>Install the timer from <code>endpoint-agent/scripts/</code> (<code>build-host.service</code> and <code>build-host.timer</code>) so the script runs every minute, then run <code>build-host.sh --register</code>. The status dot above turns green.</li>'
        . '<li>Fill in <strong>Request a build</strong> below. Within a minute the host takes the job, builds, uploads, and this page shows the progress.</li></ol>'
        . '<p class="mb-0">Full guide: <a href="' . $h($docs) . '" rel="noopener noreferrer" target="_blank">docs/rmm/BUILD_HOST.md</a> in RivetCore.</p></details>';

    // ---- request
    if ($lv['hosts'] !== []) {
        $o .= '<form action="post.php" method="post" class="row g-2 align-items-end mb-3" autocomplete="off" data-lb-request-form><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small" for="lb_ver">New version</label><input class="form-control form-control-sm font-monospace" id="lb_ver" name="bh_version" maxlength="30" required pattern="\d+\.\d+\.\d+(-[0-9A-Za-z][0-9A-Za-z.]*)?" value="' . $h($lv['suggest']) . '" spellcheck="false"></div>'
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small" for="lb_ref">Source</label><select class="form-select form-select-sm" id="lb_ref" name="bh_ref_kind"><option value="main" selected>main branch</option><option value="tag">a tag (type it)</option></select></div>'
            . '<div class="col-sm-4 col-lg-3"><label class="form-label small" for="lb_tag">Tag</label><input class="form-control form-control-sm font-monospace" id="lb_tag" name="bh_ref_tag" maxlength="40" placeholder="v1.2.3 or agent-v1.2.3" spellcheck="false"></div>'
            . '<div class="col-12"><div class="form-check"><input type="checkbox" class="form-check-input" id="lb_auto" name="bh_auto" value="1"><label class="form-check-label small" for="lb_auto">Publish automatically when built and verified</label></div>'
            . '<div class="d-flex flex-wrap gap-2 align-items-center mt-1 small"><select name="bh_ring" class="form-select form-select-sm w-auto" aria-label="Offer to ring"><option value="">No update offer</option><option value="pilot" selected>Pilot ring</option><option value="stable">Stable ring</option></select>'
            . '<input type="number" name="bh_pct" min="0" max="100" value="10" class="form-control form-control-sm" style="width:4.5rem" aria-label="Rollout percent">'
            . '<div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="lb_act" name="bh_activate" value="1" checked><label class="form-check-label" for="lb_act">Make current</label></div>'
            . '<span class="text-muted">Used for the publish, now or when you press Publish on the finished build.</span></div></div>'
            . '<div class="col-12"><button type="submit" class="btn btn-sm btn-primary" name="agent_bh_request" value="1" data-ea-confirm="Queue a build on your build host? It takes about 10 minutes."><i class="fas fa-hammer me-1" aria-hidden="true"></i>Build on my build host</button></div></form>';
    }

    // ---- jobs
    $o .= '<div class="d-flex justify-content-between align-items-center"><strong class="small">Builds on build hosts</strong></div>';
    if ($lv['jobs'] === []) {
        $o .= '<div class="small text-muted" data-lb-nojobs>No build has been requested yet.</div>';
    } else {
        $o .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0" data-lb-jobs><thead><tr><th>Version</th><th>Progress</th><th>Host</th><th>Requested</th><th></th></tr></thead><tbody>';
        $cls = ['queued' => 'secondary', 'claimed' => 'info', 'building' => 'primary', 'uploading' => 'primary', 'verifying' => 'primary', 'built' => 'success', 'published' => 'success', 'failed' => 'danger', 'expired' => 'warning', 'cancelled' => 'secondary'];
        foreach ($lv['jobs'] as $j) {
            $id = (int) $j['job_id'];
            $st = (string) $j['state'];
            $o .= '<tr data-lb-job="' . $id . '" data-lb-state="' . $h($st) . '"><td class="font-monospace">' . $h($j['version']) . '<div class="small text-muted">' . $h($j['source_ref']) . '</div></td>'
                . '<td style="min-width:14rem"><span class="badge bg-' . ($cls[$st] ?? 'secondary') . '" data-lb-label>' . $h(ucfirst($st)) . '</span> <span class="small" data-lb-text>' . $h($j['progress']['text']) . '</span>'
                . ($j['active'] || $st === 'built' ? '<div class="progress mt-1" style="height:.5rem" role="progressbar" aria-label="Build progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $j['progress']['pct'] . '"><div class="progress-bar" data-lb-bar style="width:' . (int) $j['progress']['pct'] . '%"></div></div>'
                    . '<div class="small text-muted"><span data-lb-pct>' . (int) $j['progress']['pct'] . '</span>% &middot; <span data-lb-elapsed>' . $h(rivetLocalBuildElapsed((int) $j['elapsed_s'])) . '</span> since requested</div>'
                    . '<div class="small text-break text-muted" data-lb-detail>' . $h($j['progress']['detail']) . '</div>' : '')
                . '<ul class="list-unstyled small mb-0 mt-1" data-lb-steps>' . rivetLocalBuildSteps($j['progress']['steps'], $h) . '</ul>'
                . ($j['note'] !== '' ? '<div class="small text-break" data-lb-note>' . $h($j['note']) . '</div>' : '<div class="small text-break" data-lb-note></div>')
                . ($j['result'] !== '' ? '<div class="small text-break" data-lb-result>' . $h($j['result']) . '</div>' : '')
                . ($j['log_tail'] !== '' ? '<details class="small"><summary>Log tail</summary><pre class="small border rounded p-2 mb-0 bg-body-tertiary" data-lb-log style="white-space:pre-wrap;max-height:14rem;overflow:auto">' . $h($j['log_tail']) . '</pre></details>' : '')
                . '</td><td class="small">' . $h($j['host_name'] ?? '') . '</td><td class="small">' . $h($j['requested_by_name']) . '<div class="text-muted">' . $h(rivetRmmTime($j['created_at'])) . '</div></td>'
                . '<td class="text-end text-nowrap"><form action="post.php" method="post" class="d-inline-flex gap-1"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="bh_job" value="' . $id . '">'
                . ($st === 'built' ? '<button class="btn btn-xs btn-primary" name="agent_bh_publish" value="1" data-ea-confirm="Publish this verified build now (ring ' . $h($j['ring'] !== '' ? $j['ring'] : 'none') . ', ' . (int) $j['rollout_pct'] . '%)?">Publish</button>' : '')
                . (in_array($st, ['queued', 'claimed', 'building', 'uploading', 'built'], true) ? '<button class="btn btn-xs btn-outline-danger" name="agent_bh_cancel" value="1" data-ea-confirm="Cancel this build? The host stops at its next call and anything uploaded is deleted.">Cancel</button>' : '')
                . '</form></td></tr>';
        }
        $o .= '</tbody></table></div>';
    }

    return $o . '</div>';
}
