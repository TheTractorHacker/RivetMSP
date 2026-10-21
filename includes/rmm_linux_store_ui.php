<?php

/*
 * The "Linux files hosted on this server" card (Administration > Endpoint agent > Agent updates): what is stored, a manual upload, make current, delete.
 * The rules (what is accepted, where it lives) are in includes/rmm_linux_store.php. Administrator pages only; every form carries the CSRF token.
 */

require_once __DIR__ . '/rmm_linux_store.php';

use RivetCore\Rmm\Binaries\BinaryStore;
use RivetMsp\AgentFeed\LinuxStore;

function rivetAgentLinuxFilesCard(\mysqli $mysqli, string $csrf): string
{
    $h = static fn ($v): string => nullable_htmlentities((string) $v);
    $o = '<div class="card mb-3" id="linux-files"><div class="card-header"><h4 class="card-title mb-0">Linux files hosted on this server</h4></div><div class="card-body">';
    if (!LinuxStore::ready($mysqli)) {
        return $o . '<p class="small text-muted mb-0">Run the database update first (Administration &rarr; Updates, DB 2.6.104), then reload this page.</p></div></div>';
    }
    $rows = LinuxStore::listAll($mysqli);
    $core = LinuxStore::coreState($mysqli, $rows);   // RivetCore rc.30: which hosted agent programs are also Core agent binaries (os linux) for self-update
    $o .= '<p class="small text-muted">The Linux install command downloads <code>install-linux.sh</code> and the agent <strong>from this server</strong> (nothing to upload or unpack on the machine). They come from a local build (Agent updates &gt; Build on my build host), from <strong>Agent updates &gt; Publish</strong> above, or you can add them here by hand: <code>install-linux.sh</code>, <code>rivetit-agent-linux-amd64</code>, <code>rivetit-agent-linux-arm64</code> and optionally the matching <code>.tar.gz</code>. '
        . 'Each file is checked (a 64-bit Linux program for the CPU its name says; a small shell script that starts with <code>#!</code>; a gzip file for a tarball) and stored outside the web root. The file name decides what it is, so keep the release names exactly.</p>'
        . '<p class="small text-muted">Enrolled Linux devices <strong>update themselves</strong> from the Agent binaries above, not from this list: every agent program stored here is also published there as a <strong>Linux</strong> binary (the server checks that the program contains the version you enter), and <em>Offer as update</em> offers it to Linux devices of that CPU only. A Windows PC is never offered a Linux file and a Linux device never a Windows one.</p>'
        . '<form action="post.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3" autocomplete="off" id="lx_form"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<div class="col-lg-4"><label class="form-label small" for="lx_files">Linux files</label><input type="file" class="form-control form-control-sm" id="lx_files" name="linux_files[]" multiple required></div>'
        . '<div class="col-lg-2"><label class="form-label small" for="lx_version">Version</label><input class="form-control form-control-sm" id="lx_version" name="version" placeholder="1.2.0" maxlength="40" required></div>'
        . '<div class="col-lg-2"><label class="form-label small" for="lx_ring">Offer as update</label><select class="form-select form-select-sm" id="lx_ring" name="release_ring"><option value="">No</option><option value="pilot">Pilot ring</option><option value="stable">Stable ring</option></select></div>'
        . '<div class="col-lg-1"><label class="form-label small" for="lx_pct">Rollout %</label><input class="form-control form-control-sm" id="lx_pct" name="rollout_pct" type="number" min="0" max="100" value="10"></div>'
        . '<div class="col-lg-2"><div class="form-check"><input type="checkbox" class="form-check-input" id="lx_cur" name="activate" value="1" checked><label class="form-check-label small" for="lx_cur">Make current</label></div></div>'
        . '<div class="col-lg-1"><button class="btn btn-sm btn-primary w-100" name="upload_linux_files" id="lx_go"><i class="fas fa-upload me-1" aria-hidden="true"></i>Upload</button></div></form>'
        . '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Linux files hosted on this server</caption>'
        . '<thead><tr><th scope="col">File</th><th scope="col">Version</th><th scope="col">Size</th><th scope="col">SHA-256</th><th scope="col">From</th><th scope="col">Self-update</th><th scope="col">State</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $state = !(int) $r['active'] ? '<span class="badge bg-secondary">Inactive</span>' : ((int) $r['is_current'] ? '<span class="badge bg-success">Current</span>' : '<span class="badge bg-info text-dark">Available</span>');
        $act = '<form action="post.php" method="post" class="d-inline-flex gap-1"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="file_id" value="' . (int) $r['file_id'] . '">';
        $cs = $core[(int) $r['file_id']] ?? null;
        if ($r['kind'] !== 'binary') {
            $upd = '<span class="text-muted">install only</span>';
        } elseif ($cs === null) {
            $upd = '<span class="badge bg-warning text-dark">Not an agent update</span>';
            $act .= '<button class="btn btn-xs btn-outline-secondary" name="linux_file_action" value="add_to_core" title="Publish this program under Agent binaries as a Linux binary, so it can be offered to enrolled Linux devices">Add to agent binaries</button>';
        } elseif (!$cs['active']) {
            $upd = '<span class="badge bg-secondary">Binary inactive</span>';
        } elseif ($cs['rings'] === []) {
            $upd = '<span class="text-muted">stored, not offered</span>';
        } else {
            $upd = implode(' ', array_map(static fn (array $g): string => '<span class="badge bg-info text-dark">' . $h($g['ring']) . ' ' . (int) $g['pct'] . '%</span>', $cs['rings']));
        }
        if (!(int) $r['is_current']) {
            $act .= '<button class="btn btn-xs btn-outline-primary" name="linux_file_action" value="make_current">Make current</button>'
                . '<button class="btn btn-xs btn-outline-danger" name="linux_file_action" value="delete" data-ea-confirm="Delete this file from the server? A command that pins this version stops working.">Delete</button>';
        }
        $o .= '<tr><td class="small font-monospace">' . $h($r['name']) . '</td><td>' . $h($r['version']) . '</td><td>' . $h(BinaryStore::human((int) $r['size_bytes'])) . '</td><td class="small font-monospace text-break">' . $h($r['sha256']) . '</td>'
            . '<td class="small">' . $h(['build_host' => 'Local build', 'github' => 'GitHub release', 'upload' => 'Upload'][$r['source']] ?? $r['source']) . ($r['user_name'] ? ' (' . $h($r['user_name']) . ')' : '') . '</td>'
            . '<td class="small">' . $upd . '</td><td>' . $state . '</td><td class="text-nowrap">' . $act . '</form></td></tr>';
    }
    if ($rows === []) {
        $o .= '<tr><td colspan="8" class="text-muted">No Linux file is hosted yet. The Linux install command cannot be created until <code>install-linux.sh</code> and the agent for the CPU are here. Publish a release above, build one on a build host, or upload them by hand.</td></tr>';
    }

    return $o . '</tbody></table></div></div></div>';
}
