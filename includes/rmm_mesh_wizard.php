<?php

/*
 * 26.10.30: Administration > Remote access (MeshCentral) setup. A guided page over the settings that already exist (the MeshCentral card of Administration > Endpoint agent and
 * its install settings): the same RivetCore calls (RmmAdmin::saveMesh, RemoteActions::updateMeshSettings for the certificate read), no wire change, no Core change.
 * The page walks five steps and shows a LIVE status for each (green tick or amber) computed from the saved settings and the device counts, never from what was typed.
 *
 * Plain functions: rivetRmmMeshWizardSteps() decides the statuses (tested without a browser), rivetRmmMeshWizardPage() renders. No query here: the page reads the data.
 * Every text that came from a person or a server is escaped at the point of output. The login key and the device group id are never printed back as text (masked inputs).
 */

require_once __DIR__ . '/rmm_mesh_admin.php';

/**
 * The five steps and whether each is done.
 *
 * @param array<string,mixed> $cfg RmmReadModel::settingsSummary()
 * @param array<string,mixed>|null $view rivetRmmMeshInstallView(), null when the Mesh install tables are not there yet
 * @param array{devices:int,mapped:int,installed:int} $counts
 * @param array<string,mixed>|null $probe the certificate read (pin_probe), shown once
 * @param array{ok:bool,text:string}|null $test the last connection test of this session
 * @return list<array{n:int,ok:bool,title:string,status:string}>
 */
function rivetRmmMeshWizardSteps(array $cfg, ?array $view, array $counts, ?array $probe, ?array $test): array
{
    $url = trim((string) ($cfg['mesh_url'] ?? ''));
    $keySet = !empty($cfg['mesh_login_key_set']);
    $on = (int) ($cfg['mesh_enabled'] ?? 0) === 1;
    $missing = [];
    if ($url === '') {
        $missing[] = 'the MeshCentral address is not saved';
    }
    if (!$keySet) {
        $missing[] = 'the login token key is not saved';
    }
    if (!$on) {
        $missing[] = 'remote access is switched off';
    }
    $s1 = $missing === [] ? 'Connected settings are saved' . ($test !== null ? '. Last test: ' . ($test['ok'] ? 'MeshCentral answered.' : 'it did not answer (' . $test['text'] . ').') : '. Press "Save and test connection" to check that the server answers.')
        : 'Not finished: ' . implode(', ', $missing) . '.';
    $group = $view !== null ? trim((string) ($view['mesh_group_id'] ?? '')) : '';
    $s2 = $view === null ? 'The Mesh install tables are not there yet. Run the database update (Administration, Updates), then reload this page.'
        : ($group !== '' ? 'The device group is saved.' : 'No device group is saved yet.');
    $pin = $view !== null ? trim((string) ($view['mesh_server_cert_pin'] ?? '')) : '';
    $trusted = is_array($probe) && !empty($probe['ok']) && !empty($probe['publicly_trusted']);
    if ($pin !== '') {
        $s3 = 'The server certificate is pinned (for a private CA or a self-signed certificate).';
    } elseif ($trusted) {
        $s3 = 'The server certificate comes from a public authority: no pin is needed.';
    } elseif (is_array($probe) && empty($probe['ok'])) {
        $s3 = 'The certificate could not be read: ' . (string) ($probe['error'] ?? 'unknown reason') . '.';
    } else {
        $s3 = 'Not checked yet. Press "Read it from the server" to see whether you need a pin.';
    }
    $ready = $view !== null && !empty($view['install_ready']);
    $s4 = $ready ? 'Installers can include the MeshCentral agent.' : 'Not ready: ' . ($view === null ? 'the Mesh install tables are missing.' : (string) ($view['install_refusal'] ?? 'finish steps 1 to 3.'));
    $mapped = (int) $counts['mapped'];
    $s5 = $mapped > 0 ? $mapped . ' of ' . (int) $counts['devices'] . ' enrolled device(s) are mapped to MeshCentral, so Remote can open them.'
        : 'No enrolled device is mapped yet. Install the agent with the box ticked on one test PC, then check back here.';

    return [
        ['n' => 1, 'ok' => $missing === [] && ($test === null || $test['ok']), 'title' => 'Connect your MeshCentral server', 'status' => $s1],
        ['n' => 2, 'ok' => $group !== '', 'title' => 'Create the device group and paste its address', 'status' => $s2],
        ['n' => 3, 'ok' => $pin !== '' || $trusted, 'title' => 'Set how installers trust your server', 'status' => $s3],
        ['n' => 4, 'ok' => $ready, 'title' => 'Tick "Also install the MeshCentral agent" in the installer', 'status' => $s4],
        ['n' => 5, 'ok' => $mapped > 0, 'title' => 'Test Remote on an enrolled device', 'status' => $s5],
    ];
}

/** @param array<string,mixed> $s */
function rivetRmmMeshWizardBadge(array $s): string
{
    return $s['ok'] ? '<span class="badge text-bg-success" data-wiz-badge="ok"><i class="fas fa-check me-1" aria-hidden="true"></i>Done</span>'
        : '<span class="badge text-bg-warning" data-wiz-badge="todo"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>To do</span>';
}

/** One step card. @param array<string,mixed> $s */
function rivetRmmMeshWizardStepCard(array $s, string $bodyHtml): string
{
    $e = static fn ($v): string => nullable_htmlentities((string) $v);

    return '<section class="card mb-3" id="step' . (int) $s['n'] . '" data-wiz-step="' . (int) $s['n'] . '" data-wiz-state="' . ($s['ok'] ? 'ok' : 'todo') . '" aria-labelledby="step' . (int) $s['n'] . 'h" style="border-left:4px solid var(--tblr-' . ($s['ok'] ? 'success' : 'warning') . ')">'
        . '<div class="card-header d-flex flex-wrap align-items-center gap-2"><h4 class="card-title mb-0 me-auto" id="step' . (int) $s['n'] . 'h"><span class="me-1">' . (int) $s['n'] . '.</span>' . $e($s['title']) . '</h4>' . rivetRmmMeshWizardBadge($s) . '</div>'
        . '<div class="card-body"><p class="mb-3 fw-semibold" data-wiz-status>' . $e($s['status']) . '</p>' . $bodyHtml . '</div></section>';
}

/**
 * The whole page body.
 *
 * @param array<string,mixed> $cfg settingsSummary()
 * @param array<string,mixed>|null $view rivetRmmMeshInstallView()
 * @param list<array{n:int,ok:bool,title:string,status:string}> $steps
 * @param array<string,string> $old what the person typed in a refused form
 * @param array<string,mixed>|null $probe
 */
function rivetRmmMeshWizardPage(array $cfg, ?array $view, array $steps, array $old, ?array $msg, ?array $probe, string $csrf, bool $moduleOn): string
{
    $e = static fn ($v): string => nullable_htmlentities((string) $v);
    $done = count(array_filter($steps, static fn (array $s): bool => $s['ok']));
    $urlSaved = trim((string) ($cfg['mesh_url'] ?? '')) !== '';
    $post = static fn (string $extra = ''): string => '<input type="hidden" name="csrf_token" value="' . nullable_htmlentities($csrf) . '">' . $extra;

    $o = '<div class="d-flex flex-wrap align-items-center gap-2 mb-3"><h3 class="mb-0 me-auto"><i class="fas fa-desktop me-2" aria-hidden="true"></i>Remote access (MeshCentral) setup</h3>'
        . '<span class="badge text-bg-' . ($done === 5 ? 'success' : 'secondary') . ' fs-6" data-wiz-progress>' . $done . ' of 5 steps done</span>'
        . '<a href="/admin/settings_endpoint_agent.php#mesh" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cog me-1" aria-hidden="true"></i>All MeshCentral settings</a></div>'
        . '<p class="text-muted">Remote access lets a technician open a device\'s screen, terminal or files from RivetMSP with one click. It uses <strong>your own</strong> MeshCentral server. Work through the steps in order; each shows a green tick when it is finished. Nothing here is sent to anyone but your own MeshCentral server.</p>';
    if ($msg !== null) {
        $o .= rivetRmmScaleMsgHtml($msg);
    }
    if (!$moduleOn) {
        $o .= '<div class="alert alert-warning py-2" role="status" data-wiz-module-off>The RMM module is switched off, so Remote cannot work yet. Switch it on under <a href="/admin/settings_endpoint_agent.php">Administration &gt; Endpoint agent</a> (the RMM module card).</div>';
    }

    // ---- 1
    $enabled = array_key_exists('enabled', $old) ? $old['enabled'] === '1' : ((int) ($cfg['mesh_enabled'] ?? 0) === 1 || !$urlSaved);
    $b1 = '<ol class="small mb-3"><li>Sign in to your MeshCentral server as its administrator and open a command line in the MeshCentral folder.</li>'
        . '<li>Run <code class="user-select-all">node node_modules/meshcentral --loginTokenKey</code>. It prints one long key of letters and digits (a hexadecimal string).</li>'
        . '<li>Copy the key and paste it below. It is stored encrypted and is never shown again.</li></ol>'
        . '<form action="post.php" method="post" class="row g-3" autocomplete="off" id="wiz-form-1">' . $post()
        . '<div class="col-12"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="wz_on" name="mesh_enabled" value="1"' . ($enabled ? ' checked' : '') . ' aria-describedby="wz_on_h"><label class="form-check-label" for="wz_on">Turn on remote access</label></div>'
        . '<div class="form-text" id="wz_on_h">When off, the Remote buttons stay disabled even if everything else is filled in.</div></div>'
        . '<div class="col-lg-6"><label class="form-label" for="wz_url">MeshCentral address</label><input type="url" class="form-control" id="wz_url" name="mesh_url" maxlength="500" value="' . $e($old['url'] ?? ($cfg['mesh_url'] ?? '')) . '" placeholder="https://mesh.example.com" aria-describedby="wz_url_h">'
        . '<div class="form-text" id="wz_url_h">The web address you use to sign in to MeshCentral. It must start with https:// and have no user name or extra path.</div></div>'
        . '<div class="col-lg-6"><label class="form-label" for="wz_dom">Domain <span class="text-muted">(usually empty)</span></label><input class="form-control" id="wz_dom" name="mesh_domain" maxlength="100" value="' . $e($old['domain'] ?? ($cfg['mesh_domain'] ?? '')) . '" placeholder="(default)" aria-describedby="wz_dom_h">'
        . '<div class="form-text" id="wz_dom_h">Only fill this in if your MeshCentral serves your account under its own domain name (an extra word in its address); otherwise leave it empty.</div></div>'
        . '<div class="col-lg-8"><label class="form-label" for="wz_key">Login token key ' . (!empty($cfg['mesh_login_key_set']) ? '<span class="badge bg-success">stored encrypted</span>' : '<span class="badge bg-warning text-dark">not saved</span>') . '</label>'
        . '<input type="password" class="form-control font-monospace" id="wz_key" name="mesh_login_key" autocomplete="new-password" spellcheck="false" placeholder="' . (!empty($cfg['mesh_login_key_set']) ? 'Leave empty to keep the stored key' : 'Paste the key here') . '" aria-describedby="wz_key_h">'
        . '<div class="form-text" id="wz_key_h">The key lets RivetMSP sign you in to MeshCentral for one click; it is write-only, so this box is always empty.</div></div>'
        . '<div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" name="wiz_save1" value="1">Save</button><button class="btn btn-outline-primary" name="wiz_test" value="1"><i class="fas fa-plug me-1" aria-hidden="true"></i>Save and test connection</button></div></form>';
    $o .= rivetRmmMeshWizardStepCard($steps[0], $b1);

    // ---- 2
    $group = array_key_exists('group', $old) ? $old['group'] : (string) (($view['mesh_group_id_url'] ?? '') !== '' ? $view['mesh_group_id_url'] : ($view['mesh_group_id'] ?? ''));
    $b2 = '<ol class="small mb-3"><li>In MeshCentral open <strong>My Devices</strong>, press <strong>Add Device Group</strong>, give it a name (for example "Acme laptops") and choose <strong>Manage using a software agent</strong>.</li>'
        . '<li>Open the new group and press <strong>Add Agent</strong>. Choose <strong>Windows 64-bit</strong> (the installer).</li>'
        . '<li>Copy the download address MeshCentral shows (it looks like <code>https://mesh.example.com/meshagents?id=4&amp;meshid=...</code>) and paste the <strong>whole address</strong> below. RivetMSP picks out the group by itself.</li></ol>'
        . ($view === null ? '<div class="alert alert-secondary py-2 mb-0">Not available yet: run the database update (Administration, Updates), then reload this page.</div>'
        : '<form action="post.php" method="post" class="row g-3" autocomplete="off" id="wiz-form-2">' . $post()
        . '<div class="col-lg-9"><label class="form-label" for="mc_group">Device group address or id <span class="badge bg-warning text-dark">secret</span></label>'
        . '<input type="password" class="form-control font-monospace" id="mc_group" name="mesh_group_id" maxlength="2000" autocomplete="off" spellcheck="false" value="' . $e($group) . '" aria-describedby="mc_group_h">'
        . '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="mc_group_show" data-rmm-mesh-show="mc_group"><label class="form-check-label small" for="mc_group_show">Show it</label></div>'
        . '<div class="form-text" id="mc_group_h">The download address, or just its 64-character group id. Treat it like an invitation key: anyone who has it and can reach your server can add a device to the group.</div></div>'
        . '<div class="col-12"><button class="btn btn-primary" name="wiz_save2" value="1">Save the device group</button></div></form>');
    $o .= rivetRmmMeshWizardStepCard($steps[1], $b2);

    // ---- 3
    $pin = array_key_exists('pin', $old) ? $old['pin'] : (string) ($view['mesh_server_cert_pin'] ?? '');
    $b3 = '<p class="small mb-2"><strong>Public certificate (for example Let\'s Encrypt):</strong> leave the pin <strong>empty</strong>. It renews every few weeks, and a pin would break at each renewal.<br>'
        . '<strong>Private authority or self-signed certificate:</strong> press <em>Read it from the server</em>, compare the fingerprint with the one MeshCentral shows, then save it as the pin.</p>'
        . ($view === null ? '<div class="alert alert-secondary py-2 mb-0">Not available yet: run the database update, then reload.</div>'
        : '<form action="post.php" method="post" class="row g-3" autocomplete="off" id="wiz-form-3">' . $post()
        . '<div class="col-lg-9"><label class="form-label" for="mc_pin">Server certificate pin <span class="text-muted">(optional)</span></label>'
        . '<div class="input-group"><input type="text" class="form-control font-monospace" id="mc_pin" name="mesh_server_cert_pin" maxlength="300" autocomplete="off" spellcheck="false" value="' . $e($pin) . '" placeholder="empty = trust public authorities" aria-describedby="mc_pin_h">'
        . '<button type="submit" form="wiz-probe" class="btn btn-outline-secondary" formnovalidate' . ($urlSaved ? '' : ' disabled aria-disabled="true"') . '><i class="fas fa-search me-1" aria-hidden="true"></i>Read it from the server</button></div>'
        . '<div class="form-text" id="mc_pin_h">A fingerprint (96 or 64 hexadecimal characters) of your server\'s certificate; the button only proposes one and never saves it.' . ($urlSaved ? '' : ' Save step 1 first.') . '</div></div>'
        . '<div class="col-12" id="mesh-pin-proposal" aria-live="polite">' . rivetRmmMeshPinProposalHtml($probe) . '</div>'
        . '<div class="col-12"><button class="btn btn-primary" name="wiz_save3" value="1">Save trust setting</button></div></form>'
        . '<form id="wiz-probe" action="post.php" method="post" class="d-none">' . $post('<input type="hidden" name="wiz_probe" value="1">') . '</form>');
    $o .= rivetRmmMeshWizardStepCard($steps[2], $b3);

    // ---- 4
    $b4 = '<p class="small mb-2">Open <a href="/agent/rmm_add_device.php" data-wiz-link="installer"><strong>Create installer</strong></a> (or the <em>Add device</em> dialog on the Agent fleet page), choose the client, and tick <strong>Also install the MeshCentral agent</strong>. The box stays grey, with the reason beside it, until steps 1 to 3 are done.</p>'
        . '<p class="small text-muted mb-3">The installer never contains a Mesh program. After the RivetMSP agent is in place it downloads the agent for <em>your</em> server and group. If that fails, the RivetMSP agent is still installed.</p>'
        . '<a href="/agent/rmm_add_device.php" class="btn btn-primary"><i class="fas fa-hammer me-1" aria-hidden="true"></i>Create installer</a>';
    $o .= rivetRmmMeshWizardStepCard($steps[3], $b4);

    // ---- 5
    $b5 = '<ol class="small mb-3"><li>Run the installer on one test PC and wait a minute or two for it to check in.</li>'
        . '<li>Open the device from <a href="/agent/rmm_fleet.php">Agent fleet</a>. Its <em>Remote access</em> card should say <strong>MeshCentral node mapped</strong>.</li>'
        . '<li>Press <strong>Remote access</strong>. A new tab opens at MeshCentral on that device. Press MeshCentral\'s own <strong>Connect</strong> button once: MeshCentral never connects by itself.</li></ol>'
        . '<a href="/agent/rmm_fleet.php" class="btn btn-outline-primary"><i class="fas fa-satellite me-1" aria-hidden="true"></i>Open Agent fleet</a>';
    $o .= rivetRmmMeshWizardStepCard($steps[4], $b5);

    return $o . rivetRmmMeshWizardTroubleshooting();
}

/** Plain-language troubleshooting (from docs/RMM_MODULE.md and RivetCore docs/rmm/REMOTE_AND_LOGS.md). */
function rivetRmmMeshWizardTroubleshooting(): string
{
    $items = [
        'Remote opens the MeshCentral home page instead of the device' => 'RivetMSP does not know which MeshCentral device this computer is (the "node id" is not mapped), or MeshCentral did not recognise it. Open the device in RivetMSP: the Remote access card must say <strong>MeshCentral node mapped</strong>. If not, install the MeshCentral agent (step 4), or ask an administrator to paste the device\'s address from MeshCentral into the node box on the device page. If it says mapped and you still land on the home page, copy the address bar of the device in MeshCentral and paste it into the node box again.',
        'The node id is not mapped' => 'The device has no MeshCentral agent yet, or the agent could not tell RivetMSP its identity. Use <em>Install MeshCentral agent</em> on the device page, wait a few minutes, and reload. A device whose id is already used by another live device is never taken over: check which computer really owns it (a cloned PC image is the usual cause).',
        'The MeshCentral agent is not installed' => 'The installer was run without the box ticked, or the download failed (wrong group address, server unreachable, certificate mismatch). The RivetMSP agent is installed anyway. Fix steps 2 and 3 here, then use <em>Install MeshCentral agent</em> on the device page. The reason is shown on the device\'s Remote access card; on the PC the file <code>%ProgramData%\\RivetIT\\Agent\\install.log</code> has details.',
        'Certificate errors' => '"Certificate does not match the pinned hash": the certificate was renewed or the pin is wrong. Read it again in step 3 and save the new pin, or empty the pin if your server uses a public authority. "Certificate signed by unknown authority": your server uses a private or self-signed certificate and there is no pin, so pin it in step 3.',
        'The server answered HTTP 404, or sent a web page instead of an installer' => 'The device group address is wrong. Open the group in MeshCentral, press Add Agent, Windows 64-bit, copy the address again and paste it in step 2.',
        'Test connection says the server did not answer' => 'Check that the address starts with https://, that RivetMSP\'s server can reach it (a firewall or a private address blocked by the network policy), and that the address has no extra path.',
    ];
    $o = '<section class="card mb-3" id="troubleshooting" aria-labelledby="trh"><div class="card-header"><h4 class="card-title mb-0" id="trh"><i class="fas fa-life-ring me-2" aria-hidden="true"></i>Troubleshooting</h4></div><div class="list-group list-group-flush">';
    foreach ($items as $q => $a) {
        $o .= '<details class="list-group-item" data-wiz-trouble><summary class="fw-semibold">' . nullable_htmlentities($q) . '</summary><p class="small mt-2 mb-0">' . $a . '</p></details>';
    }

    return $o . '</div></section>';
}

/**
 * The step forms, read back from $_POST. Returns [settings for saveMesh (merged on top of the saved ones), errors, old values]. Text is trimmed and cut to a safe length,
 * nothing else is changed: Core validates and refuses.
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed> $cfg
 * @return array{0:array<string,mixed>,1:list<string>,2:array<string,string>}
 */
function rivetRmmMeshWizardRead(array $post, array $cfg): array
{
    $str = static fn (string $k, int $max): ?string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : null;
    $in = [
        'mesh_url' => (string) ($cfg['mesh_url'] ?? ''), 'mesh_domain' => (string) ($cfg['mesh_domain'] ?? ''), 'mesh_account_template' => (string) ($cfg['mesh_account_template'] ?? ''),
        'mesh_policy' => (string) ($cfg['mesh_policy'] ?? 'unattended'), 'mesh_token_ttl_s' => (int) ($cfg['mesh_token_ttl_s'] ?? 0), 'mesh_enabled' => (int) ($cfg['mesh_enabled'] ?? 0),
    ];
    $old = [];
    if (isset($post['wiz_save1']) || isset($post['wiz_test'])) {
        $in['mesh_url'] = $old['url'] = $str('mesh_url', 500) ?? '';
        $in['mesh_domain'] = $old['domain'] = $str('mesh_domain', 100) ?? '';
        $in['mesh_enabled'] = !empty($post['mesh_enabled']) ? 1 : 0;
        $old['enabled'] = (string) $in['mesh_enabled'];
        $key = $str('mesh_login_key', 300);
        if ($key !== null && $key !== '') {
            $in['mesh_login_key'] = $key;
        }
    }
    if (isset($post['wiz_save2'])) {
        $in['mesh_group_id'] = $old['group'] = $str('mesh_group_id', 2001) ?? '';
    }
    if (isset($post['wiz_save3'])) {
        $in['mesh_server_cert_pin'] = $old['pin'] = $str('mesh_server_cert_pin', 300) ?? '';
    }

    return [$in, [], $old];
}
