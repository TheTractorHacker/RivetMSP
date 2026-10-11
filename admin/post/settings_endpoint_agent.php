<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Endpoint agent: form handlers. CSRF, the Referer-based dispatch of admin/post.php, $_POST extraction and flash messages are
 * RivetMSP's; every validation, authorization decision, database write and audit entry is RivetCore\Rmm (rivet/rivet-core): RmmAdmin for the
 * settings, binaries, releases, installers and signing key, TechnicianActions for devices and enrollment tokens.
 */

require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Technician\ActionResult;

mysqli_report(MYSQLI_REPORT_OFF);

/** Whole-number field clamped to a range. */
function ea_post_int(string $k, int $min, int $max, int $default): int
{
    $v = $_POST[$k] ?? null;
    return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
}

/** Flash the outcome of an action and return to the page. */
function ea_flash_result(ActionResult $r, ?string $okText = null): void
{
    flash_alert(nullable_htmlentities($r->ok ? ($okText ?? $r->message) : $r->message), $r->ok ? 'success' : 'error');
    redirect();
}

$rmm = rivetRmmModule();
$ea_admin = $rmm->admin();
$ea_tech = $rmm->technician();
$ea_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);

// The module switch (Administration > Endpoint agent > RMM module). The module is OPTIONAL and OFF by default: it is on only when BOTH the edition
// kill switch (settings.config_core_rmm_enabled) and the module's master switch (endpoint_agent_settings.enabled) are on, and this card sets
// them together. Works while the module is off: turning it on is one of its operations.
if (isset($_POST['rmm_module_switch'])) {
    validateCSRFToken($_POST['csrf_token']);
    $ea_on = ($_POST['rmm_module_switch'] ?? '') === 'on';
    if ($ea_on) {
        // Kept although RmmAdmin::enable() now answers a failed result when sealing a NEW signing key fails (RivetCore 1.0.0-rc.5): when a sealed key
        // already exists enable() never calls encrypt(), so without $config_settings_enc_key it would switch on a module that cannot read its own key.
        if (!\RivetMSP\Core\Adapter\Endpoint\EndpointSecretBox::keyConfigured()) {
            flash_alert('The RMM module cannot be switched on yet: the settings encryption key is not set. Add $config_settings_enc_key to config.php (a long random string; keep a copy, like config.php itself), then try again. The module seals its signing key with it and will not store the key unencrypted.', 'error');
            redirect();
        }
        // The edition flag first, so the state file that enable() writes already says "on".
        mysqli_query($mysqli, "UPDATE settings SET config_core_rmm_enabled = 1 WHERE company_id = 1");
        $r = $ea_admin->enable($ea_who);
        if (!$r->ok) {
            mysqli_query($mysqli, "UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1");
            $rmm->syncState();
        }
        if ($r->ok && !empty($_POST['feature_preset'])) {
            $p = $ea_admin->applyFeaturePreset($ea_who, (string) $_POST['feature_preset']);
            if (!$p->ok) {
                ea_flash_result($p);
            }
        }
    } else {
        $r = $ea_admin->disable($ea_who);
        mysqli_query($mysqli, "UPDATE settings SET config_core_rmm_enabled = 0 WHERE company_id = 1");
    }
    // The edition flag is not something Core can observe: make the zero-database state file agree with it.
    if (!$rmm->syncState() && rivetRmmStateDir() !== null) {
        flash_alert('The module state file could not be written (' . nullable_htmlentities((string) rivetRmmStateDir()) . '). Make that directory writable by the web server user, then save the switch again.', 'warning');
    }
    ea_flash_result($r, $ea_on ? 'RMM module switched on.' : 'RMM module switched off. Nothing was deleted; enrolled agents back off and come back by themselves when it is switched on again.');
}

if (isset($_POST['save_feature_preset'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->applyFeaturePreset($ea_who, (string) ($_POST['feature_preset'] ?? '')));
}

if (isset($_POST['save_agent_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    // The on/off switch is the RMM module card (rmm_module_switch below); this form never changes it.
    $in = [];
    foreach (['service_url', 'check_in_interval_s', 'collect_interval_s', 'offline_after_s', 'stale_after_s', 'failure_debounce', 'recovery_debounce', 'retention_days',
        'job_retention_days', 'job_output_max_bytes', 'job_default_timeout_s', 'job_max_timeout_s', 'job_expiry_s', 'job_ack_timeout_s', 'job_max_attempts',
        'enroll_max_ttl_h', 'unmatched_policy', 'checks_json', 'coexistence_policy', 'ca_pem'] as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = is_string($_POST[$k]) ? $_POST[$k] : '';
        }
    }
    ea_flash_result($ea_admin->saveSettings($ea_who, $in));
}

// Software inventory switch and the two history limits (RivetCore 1.0.0-rc.9). RmmAdmin validates and audits; only values that changed are written, so an install on
// the legacy feature defaults (features_json NULL) or the default limits stays that way until an administrator actually changes something here.
if (isset($_POST['save_inventory_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $cur = $rmm->readModel()->settingsSummary();
    $in = [];
    $want = !empty($_POST['inventory_software']);
    if ($want !== !empty($cur['features']['inventory_software'])) {
        $features = $cur['features'];
        $features['inventory_software'] = $want;
        $in['features_json'] = $features;
    }
    $stored = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? json_decode($cur['limits_json'], true) : [];
    $stored = is_array($stored) ? $stored : [];
    $changed = false;
    foreach (['check_history_days' => [0, 365], 'software_history_days' => [1, 3650]] as $k => [$lo, $hi]) {
        if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) {
            continue;
        }
        $v = max($lo, min($hi, (int) $_POST[$k]));
        if ($v !== (int) ($cur['limits'][$k] ?? -1)) {
            $stored[$k] = $v;
            $changed = true;
        }
    }
    if ($changed) {
        $in['limits_json'] = $stored;
    }
    if ($in === []) {
        flash_alert('No change to save.', 'info');
        redirect();
    }
    ea_flash_result($ea_admin->saveSettings($ea_who, $in), 'Software inventory settings saved.');
}

// RMM Phase 2 and 3 (RivetCore 1.0.0-rc.10): the three sub-switches (policies, scripts, alerting), the approval and alerting settings, the escalation contact and
// the script/approval limits. RmmAdmin validates and audits the switches and limits; only values that changed are written, so an install on the legacy feature
// defaults (features_json NULL) stays that way until an administrator actually changes something here.
if (isset($_POST['save_automation_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $cur = $rmm->readModel()->settingsSummary();
    $in = [];
    $features = $cur['features'];
    $changedFeature = false;
    foreach (['policies', 'scripts', 'alerting'] as $f) {
        $want = !empty($_POST['feature_' . $f]);
        if ($want !== !empty($features[$f])) {
            $features[$f] = $want;
            $changedFeature = true;
        }
    }
    if ($changedFeature) {
        $in['features_json'] = $features;
    }
    $stored = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? json_decode($cur['limits_json'], true) : [];
    $stored = is_array($stored) ? $stored : [];
    $changedLimit = false;
    foreach (['approval_bulk_threshold' => [0, 100000], 'approval_expiry_h' => [1, 720], 'schedule_batch' => [10, 5000], 'bulk_run_max' => [1, 100000]] as $k => [$lo, $hi]) {
        if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) {
            continue;
        }
        $v = max($lo, min($hi, (int) $_POST[$k]));
        if ($v !== (int) ($cur['limits'][$k] ?? -1)) {
            $stored[$k] = $v;
            $changedLimit = true;
        }
    }
    if ($changedLimit) {
        $in['limits_json'] = $stored;
    }
    $messages = [];
    if ($in !== []) {
        $r = $ea_admin->saveSettings($ea_who, $in);
        if (!$r->ok) {
            ea_flash_result($r);
        }
        $messages[] = 'Switches and limits saved.';
    }
    // The two settings RivetMSP keeps itself: who besides an administrator may approve, and who is told when an alert has nobody assigned.
    $lvl3 = !empty($_POST['approve_scripts_lvl3']) ? 1 : 0;
    $contactRaw = trim((string) ($_POST['escalation_contact'] ?? ''));
    $contacts = [];
    foreach (preg_split('/[\s,;]+/', $contactRaw) ?: [] as $c) {
        if ($c === '') {
            continue;
        }
        if (ctype_digit($c)) {
            $u = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_id = ' . (int) $c));
            if (!$u) {
                flash_alert('The escalation contact "' . nullable_htmlentities($c) . '" is not an active user id. Use an email address or the id of an active user.', 'error');
                redirect();
            }
        } elseif (!filter_var($c, FILTER_VALIDATE_EMAIL)) {
            flash_alert('The escalation contact "' . nullable_htmlentities($c) . '" is neither an email address nor a user id.', 'error');
            redirect();
        }
        $contacts[$c] = $c;
    }
    if (count($contacts) > 10) {
        flash_alert('At most 10 escalation contacts.', 'error');
        redirect();
    }
    $contactSql = mysqli_real_escape_string($mysqli, implode(', ', $contacts));
    $before = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_rmm_approve_scripts_lvl3 AS a, config_rmm_escalation_contact AS c FROM settings WHERE company_id = 1')) ?: ['a' => 0, 'c' => ''];
    if ((int) $before['a'] !== $lvl3 || (string) $before['c'] !== implode(', ', $contacts)) {
        mysqli_query($mysqli, "UPDATE settings SET config_rmm_approve_scripts_lvl3 = $lvl3, config_rmm_escalation_contact = " . ($contacts === [] ? 'NULL' : "'$contactSql'") . ' WHERE company_id = 1');
        logAction('RMM', 'Settings Changed', "$session_name changed the RMM approval and escalation settings (level 3 script users may approve: " . ($lvl3 ? 'yes' : 'no') . '; escalation contact: ' . ($contacts === [] ? 'none' : implode(', ', $contacts)) . ')');
        $messages[] = 'Approval and escalation settings saved.';
    }
    // Storm control (rmm.admin; Core validates and audits).
    $storm = [];
    foreach (['storm_global_max', 'storm_global_window_s', 'storm_client_max', 'storm_client_window_s'] as $k) {
        if (isset($_POST[$k]) && is_numeric($_POST[$k])) {
            $storm[$k] = (int) $_POST[$k];
        }
    }
    if ($storm !== []) {
        $now = $rmm->alertingActions()->settings($ea_who);
        $curStorm = (array) ($now->data['storm'] ?? []);
        $diff = array_filter($storm, static fn ($v, $k) => ($curStorm[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($diff !== []) {
            $r = $rmm->alertingActions()->updateSettings($ea_who, $diff);
            if (!$r->ok) {
                ea_flash_result($r);
            }
            $messages[] = 'Storm control saved.';
        }
    }
    if ($messages === []) {
        flash_alert('No change to save.', 'info');
        redirect();
    }
    ea_flash_result(ActionResult::ok('Saved.'), implode(' ', $messages));
}

// ---------------------------------------------------------------- agent binaries and per-client installers

/**
 * The uploaded agent files as a list of [name, tmp_name, error], whether the form sent one file (agent_binary) or several (agent_binary[]).
 *
 * @return list<array{name:string,tmp_name:string,error:int}>
 */
function ea_uploaded_binaries(): array
{
    $f = $_FILES['agent_binary'] ?? null;
    if (!is_array($f) || !isset($f['error'])) {
        return [];
    }
    $out = [];
    foreach (is_array($f['error']) ? array_keys($f['error']) : [null] as $i) {
        $pick = static fn (string $k) => $i === null ? ($f[$k] ?? null) : ($f[$k][$i] ?? null);
        $err = (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE && $i !== null) {
            continue;   // an empty slot of a multi-file input
        }
        $out[] = ['name' => (string) ($pick('name') ?? ''), 'tmp_name' => (string) ($pick('tmp_name') ?? ''), 'error' => $err];
    }

    return $out;
}

/** The version in an agent file name (rivetit-agent-1.4.2-windows-amd64.exe, v1.4.2, 1.4.2-rc.1), or ''. */
function ea_detect_version(string $fileName): string
{
    return preg_match('/(?:^|[^0-9])v?(\d+\.\d+\.\d+(?:-(?:rc|alpha|beta|pre|dev)[.0-9]*)?)/i', preg_replace('/\.exe$/i', '', $fileName), $m) === 1 ? rtrim($m[1], '.') : '';
}

/** The architecture in an agent file name (amd64 / x64 / x86_64, arm64 / aarch64), or ''. */
function ea_detect_arch(string $fileName): string
{
    $n = strtolower($fileName);
    if (preg_match('/arm64|aarch64/', $n)) {
        return 'arm64';
    }

    return preg_match('/amd64|x86[-_]?64|x64/', $n) ? 'amd64' : '';
}

if (isset($_POST['upload_agent_binary'])) {
    validateCSRFToken($_POST['csrf_token']);
    $files = ea_uploaded_binaries();
    $limit = $rmm->binaryStore()->effectiveUploadLimit();
    if ($files === []) {
        flash_alert('Choose the agent .exe to upload.', 'error');
        redirect();
    }
    $ring = (string) ($_POST['release_ring'] ?? '');
    $opts = ['activate' => isset($_POST['activate']), 'release_ring' => in_array($ring, ['pilot', 'stable'], true) ? $ring : null, 'rollout_pct' => ea_post_int('rollout_pct', 0, 100, 10)];
    // version[] / arch[] are positional with the files (the form's rows); a single version / arch field (an older form, a script) applies to its one file.
    $versions = isset($_POST['version']) ? array_values((array) $_POST['version']) : [];
    $arches = isset($_POST['arch']) ? array_values((array) $_POST['arch']) : [];
    $ok = 0;
    $messages = [];
    foreach (array_values($files) as $n => $f) {
        $label = count($files) > 1 ? ($f['name'] !== '' ? $f['name'] : 'File ' . ($n + 1)) . ': ' : '';
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $messages[] = $label . (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The file is larger than the limit of ' . \RivetCore\Rmm\Binaries\BinaryStore::human($limit) . ' (PHP upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '). Raise them in php.ini or upload with scripts/endpoint_agent_publish.php.'
                : 'The upload failed (PHP error ' . $f['error'] . ').');
            continue;
        }
        $version = trim((string) ($versions[$n] ?? ''));
        $version = $version !== '' ? $version : ea_detect_version($f['name']);
        $arch = (string) ($arches[$n] ?? '');
        $arch = in_array($arch, ['amd64', 'arm64'], true) ? $arch : ea_detect_arch($f['name']);
        if ($version === '' || $arch === '') {
            $messages[] = $label . 'Could not tell the ' . ($version === '' ? 'version' : 'architecture') . ' from the file name. Enter it and upload again.';
            continue;
        }
        $r = $ea_admin->uploadBinary($ea_who, $f['tmp_name'], $version, $arch, $opts);
        $messages[] = $label . $r->message;
        $ok += $r->ok ? 1 : 0;
    }
    flash_alert(nullable_htmlentities(implode(' ', $messages)), $ok === count($files) ? 'success' : ($ok > 0 ? 'warning' : 'error'));
    redirect();
}

if (isset($_POST['binary_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->binaryAction($ea_who, intval($_POST['binary_id'] ?? 0), (string) $_POST['binary_action'], (string) ($_POST['release_ring'] ?? 'pilot'), ea_post_int('rollout_pct', 0, 100, 10)));
}

if (isset($_POST['download_installer']) || isset($_POST['show_deploy_commands'])) {
    validateCSRFToken($_POST['csrf_token']);
    $arch = (string) ($_POST['arch'] ?? 'amd64');
    $args = [intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), (string) ($_POST['ring'] ?? 'stable'), ea_post_int('ttl_hours', 1, 720, 72),
        ea_post_int('max_uses', 1, 5000, 25), trim((string) ($_POST['label'] ?? '')), $arch];
    if (isset($_POST['show_deploy_commands'])) {
        $r = $ea_admin->deploymentCommands($ea_who, ...$args);
        if (!$r->ok) {
            ea_flash_result($r);
        }
        // Shown once, on the next page load, like a plain enrollment token.
        $_SESSION['ea_new_token'] = ['token' => $r->data['token_plain'], 'id' => (int) $r->data['token']['token_id'], 'deploy' => ['arch' => $arch, 'department' => $r->data['department'],
            'expires_at' => $r->data['token']['expires_at'], 'max_uses' => (int) $r->data['token']['max_uses'], 'commands' => $r->data['commands']]];
        flash_alert('Enrollment token created. Copy the commands now: the token is shown only once.');
        redirect();
    }
    $r = $ea_admin->downloadInstaller($ea_who, ...$args);
    if (!$r->ok) {
        ea_flash_result($r);
    }
    (new \RivetCore\Rmm\Http\SapiEmitter())->emit($r->data['download']);   // streamed with exact length after the size and SHA-256 check
    exit;
}

if (isset($_POST['rotate_signing_key'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = $ea_admin->rotateSigningKey($ea_who);
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'warning' : 'error');
    redirect();
}

if (isset($_POST['create_enroll_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = $ea_tech->createToken($ea_who, intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), (string) ($_POST['ring'] ?? 'stable'),
        ea_post_int('ttl_hours', 1, 720, 24), ea_post_int('max_uses', 1, 5000, 1), trim((string) ($_POST['label'] ?? '')));
    if ($r->ok) {
        $_SESSION['ea_new_token'] = ['token' => $r->data['token'], 'id' => $r->data['token_id']];   // shown once, on the next page load
    }
    ea_flash_result($r);
}

if (isset($_POST['revoke_enroll_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = $ea_tech->revokeToken($ea_who, intval($_POST['token_id'] ?? 0));
    if ($r->ok) {
        flash_alert('Enrollment token revoked.');
    }
    redirect();
}

if (isset($_POST['device_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    $id = intval($_POST['device_id'] ?? 0);
    $r = null;
    switch ((string) $_POST['device_action']) {
        case 'approve_link':
            $r = $ea_tech->resolvePending($ea_who, $id, 'link', intval($_POST['asset_id'] ?? 0)); break;
        case 'approve_create':
            $r = $ea_tech->resolvePending($ea_who, $id, 'create_asset'); break;
        case 'reject':
            $r = $ea_tech->resolvePending($ea_who, $id, 'reject'); break;
        case 'revoke':
            $r = $ea_tech->revoke($ea_who, $id, 'revoked by ' . $session_name); break;
        case 'rotate':
            $r = $ea_tech->rotateCredential($ea_who, $id); break;
        case 'retire':
            $r = $ea_tech->retire($ea_who, $id); break;
        case 'allow_reenroll':
            $r = $ea_tech->allowReenroll($ea_who, $id); break;
        case 'set_ring':
            $r = $ea_tech->setRing($ea_who, $id, (string) ($_POST['ring'] ?? '')); break;
        case 'clear_update_failures':
            $r = $ea_tech->clearUpdateFailures($ea_who, $id); break;
        case 'transfer':
            $r = $ea_tech->transfer($ea_who, $id, intval($_POST['client_id'] ?? 0), 0); break;
    }
    if ($r === null) {
        flash_alert('Nothing changed.', 'error');
        redirect();
    }
    ea_flash_result($r);
}

if (isset($_POST['add_agent_release'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->addExternalRelease($ea_who, trim((string) ($_POST['version'] ?? '')), trim((string) ($_POST['url'] ?? '')), trim((string) ($_POST['sha256'] ?? '')),
        trim((string) ($_POST['min_version'] ?? '0.0.0')) ?: '0.0.0', (string) ($_POST['ring'] ?? 'stable'), ea_post_int('rollout_pct', 0, 100, 0), trim((string) ($_POST['notes'] ?? ''))));
}

if (isset($_POST['update_agent_release'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->updateRelease($ea_who, intval($_POST['release_id'] ?? 0), ea_post_int('rollout_pct', 0, 100, 0), isset($_POST['active'])));
}

if (isset($_POST['save_mesh_settings']) || isset($_POST['test_mesh'])) {
    validateCSRFToken($_POST['csrf_token']);
    $in = [];
    foreach (['mesh_url', 'mesh_domain', 'mesh_account_template', 'mesh_policy', 'mesh_token_ttl_s', 'mesh_login_key'] as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = is_string($_POST[$k]) ? $_POST[$k] : '';
        }
    }
    $in['mesh_enabled'] = isset($_POST['mesh_enabled']) ? 1 : 0;
    $r = $ea_admin->saveMesh($ea_who, $in, isset($_POST['test_mesh']));
    $rmm->syncState();   // the remote sub-switch follows mesh_enabled
    ea_flash_result($r);
}
