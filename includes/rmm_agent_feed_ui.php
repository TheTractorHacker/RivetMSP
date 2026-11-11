<?php

/*
 * Administration > Endpoint agent > Agent updates: the card (pure rendering; the data is RivetMsp\AgentFeed\Feed::view(), see rmm_agent_feed.php).
 * Every value that came from GitHub is escaped where it is printed.
 */

require_once __DIR__ . '/rmm_agent_feed.php';
require_once __DIR__ . '/rmm_agent_build_ui.php';

use RivetMsp\AgentFeed\Feed;

/** @param array<string,mixed> $vm Feed::view() */
function rivetAgentFeedBadge(array $vm): string
{
    if (!$vm['badge'] || $vm['newer'] === null) {
        return '';
    }

    return '<span class="badge bg-warning text-dark text-wrap ms-2" data-feed-badge title="Newer than the newest agent hosted here"><i class="fas fa-arrow-up me-1" aria-hidden="true"></i>New version available: '
        . nullable_htmlentities((string) $vm['newer']) . ($vm['newer_pre'] ? ' (pre-release)' : '') . '</span>';
}

/**
 * @param array<string,mixed> $vm Feed::view()
 * @param array{type:string,text:string}|null $msg the card's message
 * @param list<array{label:string,state:string,detail:string}> $steps what the last publish did, step by step
 * @param array{service_ready:bool,build?:array<string,mixed>} $ctx
 */
function rivetAgentFeedCard(array $vm, ?array $msg, array $steps, string $csrf, array $ctx): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $o = '<div class="card mb-3" id="agent-updates"><div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2"><h4 class="card-title mb-0">Agent updates' . rivetAgentFeedBadge($vm) . '</h4>'
        . '<div class="d-flex flex-wrap gap-2"><a href="/agent/rmm_add_device.php" class="btn btn-sm btn-primary" data-feed-build-installer><i class="fas fa-hammer me-1" aria-hidden="true"></i>Add device / Build installer</a>'
        . '<form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<button type="submit" class="btn btn-sm btn-outline-primary" name="agent_feed_refresh" value="1"><i class="fas fa-sync me-1" aria-hidden="true"></i>Check for updates</button></form></div></div><div class="card-body">';
    $o .= '<p class="small text-muted">Releases of the public repository <a href="https://github.com/' . $h($vm['repo']) . '/releases" rel="noopener noreferrer" target="_blank">' . $h($vm['repo']) . '</a> whose tag starts with <code>agent-v</code>. '
        . '<strong>Publish</strong> downloads the Windows agent (x64 and ARM64), the MSI template, <code>install-linux.sh</code> and the Linux agent (x64 and ARM64), checking each file against the release\'s own <code>SHA256SUMS</code> BEFORE storing it, and stores it exactly as an upload would '
        . '(same checks, same audit): the Linux files are hosted on this server too, so the Add device page can hand out a one-command Linux install with nothing to unpack. A <code>.deb</code> or <code>.rpm</code> in the release is not hosted; every Linux file, hosted or not, is also recorded as a link with its SHA-256 for the <a href="/agent/rmm_add_device.php">Build installer</a> page\'s fallback commands. '
        . 'Only <code>github.com</code> and GitHub\'s release download hosts are ever contacted.</p>';
    if (isset($ctx['build'])) {
        $o .= rivetAgentBuildBox($ctx['build'], $csrf, $ctx['local'] ?? null);
    }
    if ($msg !== null) {
        $o .= '<div class="alert alert-' . ($msg['type'] === 'error' ? 'danger' : ($msg['type'] === 'warning' ? 'warning' : 'success')) . ' py-2" role="alert" data-feed-msg>' . $h($msg['text']) . '</div>';
    }
    if ($steps !== []) {
        $o .= '<ul class="small mb-3" data-feed-steps>';
        foreach ($steps as $s) {
            $o .= '<li><span class="badge bg-' . ($s['state'] === 'ok' ? 'success' : ($s['state'] === 'failed' ? 'danger' : 'secondary')) . ' me-1">' . $h($s['state']) . '</span><code>' . $h($s['label']) . '</code> ' . $h($s['detail']) . '</li>';
        }
        $o .= '</ul>';
    }
    if ($vm['error'] !== '' && $msg === null) {
        $o .= '<div class="alert alert-warning py-2" role="status" data-feed-offline><i class="fas fa-plug me-1" aria-hidden="true"></i>The last check failed: ' . $h($vm['error'])
            . ($vm['has_cache'] ? ' The list below is from ' . $h(Feed::ago(time() - (int) $vm['fetched_at'])) . '.' : '') . '</div>';
    }
    if (!$ctx['service_ready']) {
        $o .= '<div class="alert alert-info py-2 small" role="note">The service URL is not an https address yet, so a release can be stored but not offered to enrolled agents as an update. Set it under Service and defaults.</div>';
    }
    if (!$vm['has_cache']) {
        $o .= '<p class="text-muted mb-3" data-feed-empty>' . ($vm['error'] !== '' ? '' : 'No list yet. ') . 'Press <strong>Check for updates</strong> to read the releases from GitHub.</p>';
    } else {
        $o .= '<p class="small text-muted mb-2">List fetched ' . $h(Feed::ago(time() - (int) $vm['fetched_at'])) . '. Newest hosted agent: ' . ($vm['highest'] !== null ? '<strong>' . $h($vm['highest']) . '</strong>' : 'none yet') . '.'
            . ($vm['devices'] !== [] ? ' Devices by agent version: ' . implode(', ', array_map(static fn ($v, $n): string => $h($v === '' ? 'unknown' : $v) . ' (' . (int) $n . ')', array_keys($vm['devices']), $vm['devices'])) . '.' : '') . '</p>';
        $o .= '<div class="table-responsive"><table class="table table-sm align-middle mb-3"><caption class="visually-hidden">Agent releases</caption><thead><tr><th>Version</th><th>Released</th><th>Files</th><th>Here</th><th class="text-end">Devices</th><th>Publish</th></tr></thead><tbody>';
        foreach ($vm['rows'] as $i => $r) {
            $assets = [];
            foreach ($r['assets'] as $a) {
                if ($a['kind'] !== 'sums') {
                    $assets[] = '<span class="d-inline-block me-2 small">' . $h($a['name']) . '</span>';
                }
            }
            $here = '';
            foreach ($r['have']['exe'] as $arch) {
                $here .= '<span class="badge bg-success me-1">exe ' . $h($arch) . '</span>';
            }
            foreach ($r['have']['msi'] as $arch) {
                $here .= '<span class="badge bg-success me-1">msi ' . $h($arch) . '</span>';
            }
            if ($r['have']['linux']) {
                $here .= '<span class="badge bg-success me-1">Linux links</span>';
            }
            foreach ($r['rings'] as $rg) {
                $here .= '<span class="badge bg-info text-dark me-1" title="Offered to enrolled agents">' . $h($rg['ring']) . ' ' . (int) $rg['pct'] . '% ' . $h($rg['arch']) . (($rg['os'] ?? 'windows') !== 'windows' ? ' ' . $h($rg['os']) : '') . '</span>';
            }
            if ($here === '') {
                $here = '<span class="text-muted small">not published here</span>';
            }
            $o .= '<tr data-feed-row="' . $h($r['version']) . '"><td><strong>' . $h($r['version']) . '</strong>' . ($r['prerelease'] ? ' <span class="badge bg-secondary">Pre-release</span>' : '') . ($i === 0 ? ' <span class="badge bg-primary">Newest</span>' : '')
                . (str_starts_with((string) $r['url'], 'https://github.com/') ? '<div class="small"><a href="' . $h($r['url']) . '" rel="noopener noreferrer" target="_blank">Release notes</a></div>' : '') . '</td>'
                . '<td class="text-nowrap">' . $h(rivetRmmTime($r['published_at'])) . '</td>'
                . '<td>' . implode('', $assets) . ($r['has_sums'] ? '' : '<div class="small text-warning-emphasis">No SHA256SUMS in this release: it cannot be verified, so it cannot be published.</div>') . '</td>'
                . '<td>' . $here . '</td><td class="text-end">' . (int) $r['devices'] . '</td><td style="min-width:17rem">';
            if (!$r['publishable'] || !$r['has_sums']) {
                $o .= '<span class="text-muted small">Nothing to publish</span>';
            } else {
                $fid = 'af_' . $i;
                $o .= '<form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-center"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="tag" value="' . $h($r['tag']) . '">'
                    . '<select name="release_ring" class="form-select form-select-sm w-auto" aria-label="Offer to ring"><option value="">No update offer</option><option value="pilot" selected>Pilot ring</option><option value="stable">Stable ring</option></select>'
                    . '<div class="input-group input-group-sm" style="width:6rem"><input type="number" name="rollout_pct" min="0" max="100" value="10" class="form-control form-control-sm" aria-label="Rollout percent"><span class="input-group-text">%</span></div>'
                    . '<div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="' . $fid . '" name="activate" value="1" checked><label class="form-check-label small" for="' . $fid . '">Make current</label></div>'
                    . '<button type="submit" class="btn btn-sm btn-primary" name="publish_agent_release" value="1" data-ea-confirm="Download and publish agent ' . $h($r['version']) . ($r['prerelease'] ? ' (a pre-release)' : '')
                    . '? Each file is checked against the release checksums before it is stored.">' . ($r['complete'] ? 'Publish again' : 'Publish') . '</button></form>';
            }
            $o .= '</td></tr>';
        }
        if ($vm['rows'] === []) {
            $o .= '<tr><td colspan="6" class="text-muted">This repository has no release whose tag starts with agent-v.</td></tr>';
        }
        $o .= '</tbody></table></div>';
    }
    $o .= '<form action="post.php" method="post" class="row g-2 align-items-end border-top pt-3" autocomplete="off"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<div class="col-lg-5"><label class="form-label small" for="af_repo">Repository (owner/name)</label><input class="form-control form-control-sm" id="af_repo" name="feed_repo" maxlength="140" value="' . $h($vm['repo']) . '" spellcheck="false"></div>'
        . '<div class="col-lg-5"><div class="form-check"><input type="checkbox" class="form-check-input" id="af_badge" name="feed_badge" value="1"' . ($vm['badge'] ? ' checked' : '') . '><label class="form-check-label small" for="af_badge">Show a "new version available" badge when a release is newer than the newest agent hosted here</label></div></div>'
        . '<div class="col-lg-2"><button class="btn btn-sm btn-outline-secondary w-100" type="submit" name="agent_feed_settings" value="1">Save</button></div></form>';

    return $o . '</div></div>';
}

/**
 * The Add device / Build installer page (agent/rmm_add_device.php). One form for the client and the options; one block per format. js/rmm_add_device.js posts to
 * agent/post/rmm_installer.php (the existing handler: CSRF, RivetCore RmmAdmin, audit) and shows the result with copy buttons. Without JavaScript the two Windows buttons still
 * post the form and download the file.
 *
 * @param array<string,mixed> $ins rivetRmmUiInstaller()
 * @param array<string,mixed>|null $rec Feed::linuxRecord()
 */
function rivetAgentAddDevicePage(array $ins, string $csrf, ?array $rec, bool $isAdmin): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $clientOpts = '<option value=""' . ($ins['selected'] === 0 ? ' selected' : '') . '>Choose a client...</option>';
    foreach ($ins['clients'] as $c) {
        $clientOpts .= '<option value="' . (int) $c['id'] . '"' . ($ins['selected'] === $c['id'] ? ' selected' : '') . '>' . $h($c['name']) . '</option>';
    }
    $locOpts = '<option value="0" selected>No specific location</option>';
    foreach ($ins['locations'] as $cid => $list) {
        foreach ($list as $l) {
            $locOpts .= '<option value="' . (int) $l['id'] . '" data-client="' . (int) $cid . '" hidden disabled>' . $h($l['name']) . '</option>';
        }
    }
    $defaultArch = $ins['windows']['amd64'] === null && $ins['windows']['arm64'] !== null ? 'arm64' : 'amd64';
    $blocked = $ins['service_problem'] !== null;
    $noWin = $ins['windows']['amd64'] === null && $ins['windows']['arm64'] === null;
    $admin = static fn (string $anchor, string $text): string => $isAdmin ? ' <a href="/admin/settings_endpoint_agent.php#' . $anchor . '">' . $text . '</a>' : ' An administrator can do this under Administration &gt; Endpoint agent.';
    $feedLink = $admin('agent-updates', 'Publish a release under Agent updates.');
    $plat = static fn (string $v, string $id, string $icon, string $label, bool $checked): string => '<input type="radio" class="btn-check" name="platform" id="' . $id . '" value="' . $v . '"' . ($checked ? ' checked' : '') . ' autocomplete="off">'
        . '<label class="btn btn-outline-primary" for="' . $id . '"><i class="' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '</label>';
    $chip = static fn (bool $on, string $label, string $detail): string => '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' . $label . '</span>'
        . ($on ? '<span class="badge text-bg-success">' . $h($detail) . '</span>' : '<span class="badge text-bg-secondary">not published</span>') . '</li>';

    $clientOpts = '<option value=""' . ($ins['selected'] === 0 ? ' selected' : '') . ' disabled>Choose a client...</option>';
    foreach ($ins['clients'] as $c) {
        $clientOpts .= '<option value="' . (int) $c['id'] . '"' . ($ins['selected'] === $c['id'] ? ' selected' : '') . '>' . $h($c['name']) . '</option>';
    }
    $locOpts = '<option value="0" selected>No specific location</option>';
    $anyLoc = false;
    foreach ($ins['locations'] as $cid => $list) {
        foreach ($list as $l) {
            $anyLoc = true;
            $locOpts .= '<option value="' . (int) $l['id'] . '" data-client="' . (int) $cid . '" hidden disabled>' . $h($l['name']) . '</option>';
        }
    }
    $verAttr = '';
    foreach (['amd64', 'arm64'] as $a) {
        $verAttr .= ' data-win-' . $a . '="' . $h((string) ($ins['windows'][$a] ?? '')) . '" data-msi-' . $a . '="' . $h((string) ($ins['msi'][$a] ?? '')) . '" data-msi-reason-' . $a . '="' . $h((string) rivetRmmUiMsiReason($ins['msi'], $a)) . '"';
    }
    $linuxRows = [];
    foreach ((array) ($rec['assets'] ?? []) as $a) {
        if (is_array($a) && isset($a['name'], $a['url'])) {
            $linuxRows[] = $a;
        }
    }

    $o = '<div class="d-flex justify-content-end mb-2">' . rivetRmmUiInstallerButton($ins, 'Quick download', 'btn btn-outline-primary btn-sm', null, 'fa-download') . '</div>'
        . '<div class="card" id="rmm-add-root"><div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-download me-2" aria-hidden="true"></i>Add device / Build installer</h3></div><div class="card-body">'
        . '<p class="text-muted">Pick the client and the kind of machine. Everything you copy or download already knows this server and the client, so nothing is typed on the machine. '
        . 'Every button below creates its own enrollment token (audited, shown once); the Advanced section sets how long it works and for how many machines.</p>'
        . ($blocked ? '<div class="alert alert-warning py-2" role="status" data-add-notice="service"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . $h($ins['service_problem']) . $admin('settings', 'Fix it in Administration &gt; Endpoint agent.') . '</div>' : '')
        . ($noWin && !$blocked ? '<div class="alert alert-warning py-2" role="status" data-add-notice="binary"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>No Windows agent is hosted yet, so a Windows installer cannot be built.' . $feedLink . '</div>' : '');

    $o .= '<form id="rmm-add-form" method="post" action="' . $h($ins['post_url']) . '" autocomplete="off" novalidate data-max-ttl="' . (int) $ins['max_ttl_h'] . '" data-win-ready="' . ($noWin || $blocked ? '0' : '1') . '" data-service-ready="' . ($blocked ? '0' : '1') . '"' . $verAttr
        . ' data-have-linux="' . ($rec !== null ? '1' : '0') . '">'
        . '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="os" id="rmm-add-os" value="windows"><input type="hidden" name="arch" id="rmm-add-arch" value="' . $defaultArch . '">'
        . '<div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label" for="rmm-add-client">Client <span class="text-danger" aria-hidden="true">*</span></label><select class="form-select" id="rmm-add-client" name="client_id" required>' . $clientOpts . '</select></div>'
        . '<div class="col-md-3' . ($anyLoc ? '' : ' d-none') . '" id="rmm-add-loc-col"><label class="form-label" for="rmm-add-loc">Location <span class="text-muted">(optional)</span></label><select class="form-select" id="rmm-add-loc" name="location_id">' . $locOpts . '</select></div>'
        . '<div class="col-md-2"><label class="form-label" for="rmm-add-ring">Update ring</label><select class="form-select" id="rmm-add-ring" name="ring"><option value="stable" selected>Stable</option><option value="pilot">Pilot</option></select></div>'
        . '<div class="col-md-3"><label class="form-label" for="rmm-add-dtype">Device type</label><select class="form-select" id="rmm-add-dtype" name="device_type">'
        . '<option value="auto" selected>Detect automatically</option><option value="Laptop">Laptop</option><option value="Desktop">Desktop</option><option value="Server">Server</option>'
        . '<option value="Virtual Machine">Virtual machine</option><option value="Hypervisor">Hypervisor</option></select></div>'
        . '<div class="col-12"><span class="form-label d-block" id="rmm-add-plat-label">Platform</span><div class="btn-group flex-wrap" role="group" aria-labelledby="rmm-add-plat-label">'
        . $plat('windows-amd64', 'rmm-add-p-wx64', 'fab fa-windows', 'Windows x64', $defaultArch === 'amd64') . $plat('windows-arm64', 'rmm-add-p-warm', 'fab fa-windows', 'Windows ARM64', $defaultArch === 'arm64')
        . $plat('linux-amd64', 'rmm-add-p-lx64', 'fab fa-linux', 'Linux x64', false) . $plat('linux-arm64', 'rmm-add-p-larm', 'fab fa-linux', 'Linux ARM64', false) . '</div></div>'
        . str_replace('rmm-inst-mesh', 'rmm-add-mesh', rivetRmmUiInstallerMeshBox($ins['mesh']))
        . '<div class="col-12"><details id="rmm-add-adv"><summary class="small fw-bold">Advanced: how long the token works, how many machines</summary><div class="row g-3 pt-2">'
        . '<div class="col-md-4"><label class="form-label" for="rmm-add-ttl">Token lifetime (hours)</label><input class="form-control" type="number" id="rmm-add-ttl" name="ttl_hours" min="1" max="' . (int) $ins['max_ttl_h'] . '" value="' . min(24, (int) $ins['max_ttl_h']) . '"></div>'
        . '<div class="col-md-4"><label class="form-label" for="rmm-add-label">Label <span class="text-muted">(optional)</span></label><input class="form-control" type="text" id="rmm-add-label" name="label" maxlength="100" placeholder="e.g. Front office rollout"></div>'
        . '<div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-add-multi" name="multiple" value="1"><label class="form-check-label" for="rmm-add-multi">Works for up to 25 machines</label></div></div>'
        . '</div></details></div></div>'
        . '<div id="rmm-add-msg" class="alert d-none mt-3 mb-0" role="alert"></div>';

    // Windows
    $msiReason = (string) rivetRmmUiMsiReason($ins['msi'], $defaultArch);
    $msiOk = 'For Intune, Group Policy or any tool that installs .msi packages. A copy of the MSI template with this client\'s enrollment embedded. Unsigned and not yet verified on Windows: try it on one PC first. Template version: ';
    $o .= '<section class="mt-4" id="add-windows" aria-labelledby="add-windows-h"><h4 class="h5" id="add-windows-h"><i class="fab fa-windows me-2" aria-hidden="true"></i>Windows</h4>'
        . '<div class="row g-3"><div class="col-lg-6"><div class="border rounded p-3 h-100"><h5 class="h6">Installer (.exe)</h5>'
        . '<p class="small text-muted">The proven path. Double-click it on the PC and accept the administrator prompt; it installs and enrolls itself. For deployment tools run it with <code>setup --silent</code>. Hosted version: <span data-add-ver-exe>' . $h((string) ($ins['windows'][$defaultArch] ?? 'none')) . '</span>.</p>'
        . '<button type="submit" class="btn btn-primary" name="format" value="exe" data-add="download" data-add-format="exe"' . ($noWin || $blocked ? ' disabled' : '') . '><i class="fas fa-download me-1" aria-hidden="true"></i>Download .exe installer</button></div></div>'
        . '<div class="col-lg-6"><div class="border rounded p-3 h-100"><h5 class="h6">MSI (.msi)</h5>'
        . '<p class="small text-muted" data-add-msi-help data-add-msi-ok="' . $h($msiOk) . '">' . ($msiReason !== '' ? $h($msiReason) : $h($msiOk . (string) ($ins['msi'][$defaultArch] ?? '') . '.')) . '</p>'
        . '<button type="submit" class="btn btn-outline-primary" name="format" value="msi" data-add="download" data-add-format="msi"' . ($msiReason !== '' || $blocked ? ' disabled' : '') . '><i class="fas fa-download me-1" aria-hidden="true"></i>Download .msi installer</button></div></div>'
        . '<div class="col-12"><div class="border rounded p-3"><h5 class="h6">Silent install commands (Intune, GPO, your RMM)</h5><p class="small text-muted">Creates a token and prints ready-to-paste commands. The commands download the stamped installer from this server with the token, so run them as SYSTEM or an administrator.</p>'
        . '<button type="button" class="btn btn-outline-primary" data-add="commands-windows"' . ($noWin || $blocked ? ' disabled' : '') . '><i class="fas fa-terminal me-1" aria-hidden="true"></i>Show silent-install commands</button>'
        . '<div id="add-win-out" class="mt-3" hidden></div></div></div>'
        . '<div class="col-12" id="rmm-add-apps-windows-wrap" hidden><div class="border rounded p-3"><h5 class="h6">Apps to install with the agent</h5><div id="rmm-add-apps-windows" data-rmm-apps-list aria-live="polite"></div></div></div>'
        . '</div></section>';

    // Linux
    $o .= '<section class="mt-4 d-none" id="add-linux" aria-labelledby="add-linux-h"><h4 class="h5" id="add-linux-h"><i class="fab fa-linux me-2" aria-hidden="true"></i>Linux (systemd)</h4>';
    if ($rec === null) {
        $o .= '<div class="alert alert-info py-2" role="note" data-add-notice="linux">No Linux release is recorded on this server yet, so the command expects the agent tarball next to it.' . $feedLink . ' You then get commands that download and verify the agent themselves, and package links.</div>';
    }
    $o .= '<div class="border rounded p-3 mb-3"><h5 class="h6">One command, run as root</h5><p class="small text-muted">Creates a token and prints a command that downloads <code>install-linux.sh</code> and the agent from this server, checks both against the SHA-256 values printed in the command, and installs. Nothing to upload or unpack; the machine only needs to reach this server (curl or wget).</p>'
        . '<button type="button" class="btn btn-primary" data-add="commands-linux"' . ($blocked ? ' disabled' : '') . '><i class="fas fa-terminal me-1" aria-hidden="true"></i>Create install command</button>'
        . '<div id="add-linux-out" class="mt-3" hidden></div></div>'
        . '<div class="border rounded p-3 mb-3" id="rmm-add-apps-linux-wrap" hidden><h5 class="h6">Apps to install with the agent</h5><div id="rmm-add-apps-linux" data-rmm-apps-list aria-live="polite"></div></div>';
    if ($linuxRows !== []) {
        $o .= '<div class="border rounded p-3"><h5 class="h6">Packages and files <span class="text-muted fw-normal">(' . $h($rec['version'] ?? '') . ')</span></h5><div class="table-responsive"><table class="table table-sm align-middle mb-0" id="add-linux-files"><caption class="visually-hidden">Linux files of the release</caption><thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">SHA-256</th><th scope="col"></th></tr></thead><tbody>';
        foreach ($linuxRows as $a) {
            $o .= '<tr><td><a href="' . $h($a['url']) . '" rel="noopener noreferrer">' . $h($a['name']) . '</a></td><td class="text-nowrap">' . $h(\RivetMsp\AgentFeed\FeedHttp::human((int) ($a['size'] ?? 0))) . '</td>'
                . '<td class="small font-monospace text-break">' . (($a['sha256'] ?? '') !== '' ? $h($a['sha256']) . '<div class="text-muted">' . $h($a['sha_source'] ?? '') . '</div>' : '<span class="text-warning-emphasis">none published</span>') . '</td>'
                . '<td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-secondary" data-add-copy-text="' . $h($a['url']) . '">Copy link</button></td></tr>';
        }
        $o .= '</tbody></table></div></div>';
    }
    $o .= '</section>';

    // what the server has
    $o .= '<section class="mt-4" aria-labelledby="add-have-h"><h4 class="h6" id="add-have-h">What this server can build right now</h4><ul class="list-group list-group-flush border rounded small">'
        . $chip($ins['windows']['amd64'] !== null, 'Windows x64 agent (.exe installer)', (string) $ins['windows']['amd64']) . $chip($ins['windows']['arm64'] !== null, 'Windows ARM64 agent (.exe installer)', (string) $ins['windows']['arm64'])
        . $chip(($ins['msi']['amd64'] ?? null) !== null, 'Windows x64 MSI template', (string) ($ins['msi']['amd64'] ?? '')) . $chip($rec !== null, 'Linux install script, agent and packages', (string) ($rec['version'] ?? ''))
        . '</ul>' . ($isAdmin ? '<p class="small mt-2 mb-0">Need a newer version? <a href="/admin/settings_endpoint_agent.php#agent-updates">Agent updates</a> lists the releases on GitHub and publishes one with a click.</p>' : '')
        . '</section></form></div></div>';

    return $o;
}
