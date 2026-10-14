<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * RivetMSP 26.10.22 (fragment of tests/rmm_ui.php): the "Run script" dialog of the asset page offers the RivetCore script library (grouped by risk class, with the chosen script's
 * parameter form, dry-run default, approval and destructive notices), the older saved scripts, scripts synced from a vendor RMM and free-form text (PowerShell on Windows, shell on
 * Linux); and the single Scripts area (one menu entry, tabs Library and Synced from <provider>, older addresses redirect, the one-time import of the older hand-made scripts).
 * Shares that file's variables ($ok $q $one $db $root $S $D $A $USER $SS $wb $rmm $admin $questions $overhead $fresh $panel). Everything is created through the product's own
 * handlers (agent/post/rmm_automation_scr.php, agent/rmm_scripts.php) or Core's technician API, with CSRF; the settings it changes are put back.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

require_once "$root/includes/rmm_scr_ui.php";
require_once "$root/includes/rmm_scripts_hub.php";

$HANDLER = '/agent/post/rmm_automation_scr.php';
$JSON = ['Accept: application/json'];
$WinDev = $D['WIN1'];
$LnxDev = $D['LNX1'];
$locOf = fn(string $h) => preg_match('/^Location:\s*(\S+)/mi', $h, $m) === 1 ? (string) preg_replace('~^https?://[^/]+~', '', $m[1]) : '';
$apiAs = fn(string $who, string $m, array $seg, array $qs = [], ?array $body = null) => rivetRmmAutoApi($db, $USER[$who], $who, $m, $seg, $qs, $body);
$jpost = function (string $who, array $f, array $headers = []) use ($wb, $SS, $HANDLER, $JSON): array {
    [$c, $b] = web($wb, 'POST', $HANDLER, $SS[$who], $f + ['csrf_token' => 'csrftok1'], array_merge($JSON, $headers));
    return [$c, json_decode($b, true) ?: [], $b];
};
$jobFor = fn(int $scriptId) => $db->query("SELECT j.*, x.script_version FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id=$scriptId ORDER BY j.created_at DESC, j.job_id DESC LIMIT 1")->fetch_assoc();
$jobCount = fn(int $scriptId) => (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE script_id=$scriptId");

// ---- the switch this fragment needs, and what it must put back
$rdFeatures = json_decode((string) $one('SELECT features_json FROM endpoint_agent_settings WHERE id=1'), true) ?: [];
$rdOn = $rdFeatures; $rdOn['scripts'] = true;
$rmm->admin()->enable($admin);
$rmm->admin()->saveSettings($admin, ['features_json' => $rdOn]); $fresh();
$q("DELETE FROM rmm_scripts");
$q("DELETE FROM rmm_integrations");

// ---- library scripts of every risk class and both platforms (through Core's API, as the administrator)
$rdMk = function (string $name, string $language, string $risk, array $params = [], array $extra = []) use ($apiAs): int {
    $r = $apiAs('admin', 'POST', ['scripts'], [], ['name' => $name, 'language' => $language, 'body' => $language === 'powershell' ? "Write-Output 'x'" : "echo x", 'params' => $params,
        'tags' => $risk === '' ? ['mine'] : ['risk:' . $risk, 'maintenance'], 'description' => 'RD ' . $name] + $extra);
    return (int) ($r['data']['script']['script_id'] ?? 0);
};
$pAge = ['name' => 'age_days', 'type' => 'int', 'label' => 'Minimum age in days', 'default' => 7, 'min' => 1, 'max' => 365];
$pDry = ['name' => 'dry_run', 'type' => 'bool', 'default' => true, 'label' => 'Dry run (only report)'];
$pDryOff = ['name' => 'dry_run', 'type' => 'bool', 'default' => false, 'label' => 'Dry run (only report)'];
$sRead = $rdMk('RD win read only', 'powershell', 'read_only', [['name' => 'target_name', 'type' => 'string', 'label' => 'Target', 'required' => true, 'max_length' => 50], $pAge, ['name' => 'token', 'type' => 'secret', 'label' => 'Token'], $pDry, ['name' => 'mode', 'type' => 'choice', 'choices' => ['fast', 'full'], 'default' => 'fast']]);
$sReadOff = $rdMk('RD win read only dry off', 'powershell', 'read_only', [$pDryOff]);
$sChange = $rdMk('RD win changes', 'powershell', 'changes_system', [$pAge, $pDryOff]);
$sDisrupt = $rdMk('RD win disruptive', 'powershell', 'disruptive', [], ['requires_approval' => true, 'destructive' => true]);
$sDestr = $rdMk('RD win destructive no approval', 'powershell', 'changes_system', [], ['destructive' => true]);
$sMine = $rdMk('RD win own unclassified', 'powershell', '');
$sLRead = $rdMk('RD lin read only', 'bash', 'read_only', [$pDry]);
$sLChange = $rdMk('RD lin changes', 'bash', 'changes_system', [$pAge, $pDry]);
$ok(min($sRead, $sReadOff, $sChange, $sDisrupt, $sDestr, $sMine, $sLRead, $sLChange) > 0, 'dialog fixtures: eight library scripts created through Core (' . json_encode([$sRead, $sReadOff, $sChange, $sDisrupt, $sDestr, $sMine, $sLRead, $sLChange]) . ')');

// ============================================================ the library as a source of the dialog
$vmW = $panel('WIN1', 'admin');
$rdLib = $vmW['run']['library'] ?? null;
$expectW = (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE platform='windows' AND retired_at IS NULL");
$ok($rdLib !== null && $rdLib['count'] === $expectW && $expectW >= 6, "WIN1: the library choices are the Windows scripts ($expectW)");
$rdOrder = array_flip(array_keys(RIVET_RMM_SCR_RISKS));
$risks = array_map(fn($i) => $rdOrder[$i['risk']], $rdLib['items']);
$sorted = $risks; sort($sorted);
$ok($risks === $sorted, 'WIN1: grouped by risk class: read only, then changes the system, then disruptive, then not classified');
$byName = array_column($rdLib['items'], null, 'name');
$ok(($byName['RD win read only']['risk'] ?? '') === 'read_only' && ($byName['RD win changes']['risk'] ?? '') === 'changes_system' && ($byName['RD win disruptive']['risk'] ?? '') === 'disruptive' && ($byName['RD win own unclassified']['risk'] ?? '') === 'unclassified', 'WIN1: the risk class comes from the risk: tag; a script nobody classified says so');
$ok(!isset($byName['RD lin read only']) && !isset($byName['RD lin changes']), 'WIN1: no Linux script is offered for a Windows device');
$ok($byName['RD win disruptive']['requires_approval'] === true && $byName['RD win disruptive']['destructive'] === true && $byName['RD win read only']['requires_approval'] === false, 'WIN1: approval and destructive flags travel with the choice');
$vmL = $panel('LNX1', 'admin');
$libL = $vmL['run']['library'];
$ok($libL !== null && $libL['count'] === (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE platform='linux' AND retired_at IS NULL") && !array_filter($libL['items'], fn($i) => str_starts_with($i['name'], 'RD win')), 'LNX1: only scripts for Linux are offered');
$ok(in_array('RD lin read only', array_column($libL['items'], 'name'), true) && in_array('RD lin changes', array_column($libL['items'], 'name'), true), 'LNX1: the Linux library scripts are there');

$dW = rivetRmmUiDialogs($vmW, 'csrftok1');
$ok(str_contains($dW, 'id="rmmRunModal"') && str_contains($dW, 'Script library (' . $expectW . ')') && str_contains($dW, 'PowerShell (type or paste)') && !str_contains($dW, 'Shell text'), 'WIN1 dialog: "What to run" lists Script library (N) and PowerShell (type or paste), no shell text');
$ok(str_contains($dW, 'label="Read only"') && str_contains($dW, 'label="Changes the system"') && str_contains($dW, 'label="Disruptive"') && str_contains($dW, 'label="Not classified"')
    && strpos($dW, 'label="Read only"') < strpos($dW, 'label="Changes the system"') && strpos($dW, 'label="Changes the system"') < strpos($dW, 'label="Disruptive"'), 'WIN1 dialog: the scripts sit in groups by risk class, in that order');
$ok(str_contains($dW, 'data-search="rd win read only risk:read_only maintenance rd rd win read only"') || preg_match('/data-search="[^"]*rd win read only[^"]*maintenance/', $dW) === 1, 'WIN1 dialog: each option carries its searchable text (name, tags, description)');
$ok(str_contains($dW, 'id="rmm-lib-search"') && str_contains($dW, 'id="rmm-lib-script"') && str_contains($dW, 'aria-live="polite"') && str_contains($dW, 'for="rmm-lib-search"') && str_contains($dW, 'for="rmm-run-type"'), 'WIN1 dialog: search box and script list are labelled, the count is a live region');
$ok(preg_match('~<form method="post" action="/agent/post/rmm_automation_scr\.php" id="rmm-lib-form"[^>]*>~', $dW) === 1 && str_contains($dW, 'name="csrf_token" value="csrftok1"') && str_contains($dW, 'name="action" value="run_script"')
    && str_contains($dW, 'name="device_id" value="' . $WinDev . '"') && str_contains($dW, 'name="library_script_id"') && str_contains($dW, 'value="/agent/asset_details.php?asset_id=' . $A['WIN1'] . '#rmm-jobs"'), 'WIN1 dialog: the library form posts to the SAME handler as the library card, with CSRF, run_script, the device and a return to the Jobs tab');
$ok(str_contains($dW, 'id="rmm-lib-approval"') && str_contains($dW, 'approvals queue') && str_contains($dW, 'id="rmm-lib-confirm"') && str_contains($dW, 'I confirm this destructive script may run now'), 'WIN1 dialog: the approval notice and the destructive confirmation are there for the page script to show');
$ok(!preg_match('/<script[ >]/i', $dW) && !preg_match('/\son(click|change|submit)=/i', $dW), 'WIN1 dialog: no inline script or event attribute (CSP-safe)');
$dL = rivetRmmUiDialogs($vmL, 'csrftok1');
$ok(str_contains($dL, 'Script library (' . $libL['count'] . ')') && str_contains($dL, 'Shell (type or paste)') && !str_contains($dL, 'PowerShell (type or paste)') && str_contains($dL, 'RD lin read only') && !str_contains($dL, 'RD win read only'), 'LNX1 dialog: library scripts for Linux and shell text, no PowerShell, no Windows script');
$ok(str_contains($dL, 'data-text-type="shell"') && str_contains($dW, 'data-text-type="powershell"') && str_contains($dL, 'Shell commands'), 'the free-form text is sent as the right job type for the platform');

// a legacy saved script keeps working (Windows only), the legacy list does not appear for Linux
$q("INSERT INTO rmm_scripts SET name='RD legacy saved', category='Repair', script_type='powershell', script_body='Get-Date', enabled=1, created_by=1");
$legacySaved = (int) $db->insert_id;
$vmW2 = $panel('WIN1', 'admin');
$dW2 = rivetRmmUiDialogs($vmW2, 'csrftok1');
$ok(str_contains($dW2, '<option value="saved">A saved script</option>') && str_contains($dW2, 'RD legacy saved') && strpos($dW2, 'value="library"') < strpos($dW2, 'value="saved"'), 'a legacy saved script is still offered ("A saved script"), after the library');
$ok(!str_contains(rivetRmmUiDialogs($panel('LNX1', 'admin'), 'csrftok1'), 'A saved script'), 'LNX1: the legacy saved scripts (PowerShell) are not offered');
$ok(!str_contains($dW, 'A saved script'), 'with no legacy script the "A saved script" choice is not drawn');

// rows of the strip button
$strW = rivetRmmUiStrip($vmW);
$strL = rivetRmmUiStrip($vmL);
$ok(preg_match('/id="rmm-act-script"[^>]*>/', $strW, $m) === 1 && !str_contains($m[0], 'disabled') && preg_match('/id="rmm-act-script"[^>]*>/', $strL, $m) === 1 && !str_contains($m[0], 'disabled'), 'the Run script button is enabled on Windows and on Linux');

// ---- viewers
$vmV = $panel('WIN1', 'viewer');
$ok($vmV['run']['library'] === null && $vmV['run']['free_form'] === null && !str_contains(rivetRmmUiDialogs($vmV, 'csrftok1'), 'rmm-lib-form') && !str_contains(rivetRmmUiDialogs($vmV, 'csrftok1'), 'rmmRunModal'), 'no ability: no library, no free-form text, no dialog');
$ok(preg_match('/id="rmm-act-script"[^>]*disabled/', rivetRmmUiStrip($vmV)) === 1, 'no ability: the Run script button is disabled');
$vmR = $panel('WIN1', 'rebootonly');
$ok($vmR['run']['library'] !== null && $vmR['run']['free_form'] === null, 'a role that may run saved scripts but not free-form text sees the library and no text choice');
$dR = rivetRmmUiDialogs($vmR, 'csrftok1');
$ok(str_contains($dR, 'Script library (') && !str_contains($dR, 'PowerShell (type or paste)'), 'and its dialog has no PowerShell text');

// ---- empty state: a platform with no library script
$q("UPDATE rmm_scripts_v2 SET retired_at=NOW() WHERE platform='linux'");
$vmLe = $panel('LNX1', 'admin');
$dLe = rivetRmmUiDialogs($vmLe, 'csrftok1');
$ok($vmLe['run']['library']['count'] === 0 && str_contains($dLe, 'The script library has no script for Linux devices yet') && str_contains($dLe, 'href="/agent/rmm_starter_pack.php"'), 'empty library for Linux: the dialog explains and (administrator) links the Starter pack');
$dLe2 = rivetRmmUiDialogs($panel('LNX1', 'rebootonly'), 'csrftok1');
$ok(str_contains($dLe2, 'No library script runs on a Linux device yet') && !str_contains($dLe2, 'rmm_starter_pack.php') && str_contains($dLe2, 'href="/agent/rmm_scripts.php?tab=library"'), 'empty library: a non-administrator is pointed to Scripts, not the Starter pack');
$rmm->admin()->saveSettings($admin, ['features_json' => array_merge($rdFeatures, ['scripts' => false])]); $fresh();
$q("UPDATE rmm_scripts_v2 SET retired_at=NULL WHERE platform='linux'");
$rmm->admin()->saveSettings($admin, ['features_json' => $rdOn]); $fresh();

// ============================================================ the chosen script's form (the page script asks for it)
[$c, $j] = $jpost('admin', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sRead]);
$f = $j['fragment'] ?? [];
$ok($c === 200 && ($j['success'] ?? false) === true && ($f['risk'] ?? '') === 'read_only' && str_contains($f['risk_html'] ?? '', 'Read only') && ($f['name'] ?? '') === 'RD win read only' && ($f['version'] ?? 0) === 1, 'form: the read-only script comes back with its risk, name and version (' . $c . ')');
$h = $f['html'] ?? '';
$ok(preg_match('/<input class="form-control" type="number" step="1" name="param\[age_days\]"[^>]*min="1" max="365"/', $h) === 1 && str_contains($h, 'A whole number, 1 to 365.'), 'form: an integer is a number input with its range and a hint');
$ok(preg_match('/<input class="form-control" type="text" name="param\[target_name\]"[^>]* required/', $h) === 1 && str_contains($h, 'Up to 50 characters.'), 'form: a required parameter without a default is required in the form, with its length hint');
$ok(preg_match('/<input class="form-control" type="password" autocomplete="new-password" name="param\[token\]"/', $h) === 1, 'form: a secret is a password field');
$ok(preg_match('/<select class="form-select" name="param\[mode\]"[^>]*><option value="">Default \(fast\)/', $h) === 1, 'form: a choice is a select showing its default');
$ok(preg_match('/<input type="hidden" name="param\[dry_run\]" value="false"><input class="form-check-input" type="checkbox" name="param\[dry_run\]" value="true" id="[^"]+" checked>/', $h) === 1 && str_contains($h, 'Dry run (nothing is changed)'), 'form: the dry-run box is ticked by default when the script defaults to a dry run (a hidden "false" first, so unticking counts)');
[$c, $j] = $jpost('admin', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sChange]);
$ok(preg_match('/type="checkbox" name="param\[dry_run\]" value="true" id="[^"]+" checked>/', $j['fragment']['html'] ?? '') === 1 && ($j['fragment']['risk'] ?? '') === 'changes_system', 'form: a script that changes things starts with Dry run ticked even when its own default is off');
[$c, $j] = $jpost('admin', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sReadOff]);
$ok(preg_match('/type="checkbox" name="param\[dry_run\]" value="true" id="[^"]+">/', $j['fragment']['html'] ?? '') === 1, 'form: a read-only script follows its own default (off)');
[$c, $j] = $jpost('admin', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sDisrupt]);
$ok(($j['fragment']['requires_approval'] ?? null) === true && ($j['fragment']['destructive'] ?? null) === true && str_contains($j['fragment']['html'] ?? '', 'takes no parameters') && str_contains($j['fragment']['risk_html'] ?? '', 'Disruptive'), 'form: a disruptive script says it needs approval and is destructive');
[$c, $j] = $jpost('admin', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sLRead]);
$ok($c === 404 && ($j['success'] ?? true) === false && !isset($j['fragment']), 'form: a Linux script is refused for a Windows device (404)');
[$c, $j] = $jpost('viewer', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sRead]);
$ok($c === 404 && !isset($j['fragment']), 'form: a viewer without the ability gets nothing (404)');
[$c, $j] = $jpost('clientb', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sRead]);
$ok($c === 404 && !isset($j['fragment']), 'form: a user limited to Client B cannot ask about a Client A device (404)');
[$c, $b] = web($wb, 'POST', $HANDLER, $SS['admin'], ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sRead], $JSON);
$ok(!str_contains($b, '"fragment"'), 'form: without the CSRF token nothing is returned');

// ============================================================ submitting: the same handler as the library card
$before = $jobCount($sRead);
[$c, $j] = $jpost('admin', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sRead, 'script_version' => 1, 'timeout_s' => '90', 'return' => '/agent/asset_details.php?asset_id=' . $A['WIN1'] . '#rmm-jobs',
    'param' => ['target_name' => 'spool', 'age_days' => '30', 'token' => 'S3cr3t-RD-77', 'dry_run' => 'true', 'mode' => '']]);
$job = $jobFor($sRead);
$jp = json_decode((string) ($job['params_json'] ?? ''), true);
$ok($c === 200 && ($j['success'] ?? false) === true && $jobCount($sRead) === $before + 1 && str_contains($j['link'] ?? '', '#rmm-jobs') && ($j['job_id'] ?? '') === ($job['job_id'] ?? 'x') && str_contains($j['message'] ?? '', 'Job queued'), 'run: a read-only script queues a job; the answer names it and links the Jobs tab');
$ok($job && (int) $job['device_id'] === $WinDev && $job['type'] === 'powershell' && $job['state'] === 'queued' && (int) $job['timeout_s'] === 90 && (int) $job['created_by'] === 1, 'run: the job is a PowerShell job for WIN1, queued, timeout 90, created by the signed-in user');
$ok(($jp['target_name'] ?? null) === 'spool' && ($jp['age_days'] ?? null) === 30 && ($jp['dry_run'] ?? null) === true && !str_contains(json_encode($jp), 'S3cr3t-RD-77'), 'run: parameters are typed in the job (string, whole number, true) and the secret is not stored in the clear (' . json_encode($jp) . ')');
// the unticked box: the hidden "false" comes first and the ticked "true" after it, last one wins; an unticked box sends only the "false"
$rawPost = function (string $body) use ($wb, $SS, $HANDLER) {
    $ch = curl_init($wb . $HANDLER);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $SS['admin'], CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], CURLOPT_POSTFIELDS => $body]);
    $out = curl_exec($ch); curl_close($ch);
    return json_decode((string) $out, true) ?: [];
};
$rdBase = 'csrf_token=csrftok1&action=run_script&device_id=' . $WinDev . '&library_script_id=' . $sChange . '&script_version=1&';
$r1 = $rawPost($rdBase . 'param%5Bage_days%5D=9&param%5Bdry_run%5D=false');
$jobsC1 = $db->query("SELECT j.job_id, j.params_json FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id=$sChange")->fetch_all(MYSQLI_ASSOC);
$jp1 = json_decode((string) ($jobsC1[0]['params_json'] ?? ''), true);
$r2 = $rawPost($rdBase . 'param%5Bage_days%5D=9&param%5Bdry_run%5D=false&param%5Bdry_run%5D=true');
$jobsC2 = $db->query("SELECT j.job_id, j.params_json FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id=$sChange")->fetch_all(MYSQLI_ASSOC);
$jp2 = null;
foreach ($jobsC2 as $jc) { if ($jc['job_id'] !== ($jobsC1[0]['job_id'] ?? '')) { $jp2 = json_decode((string) $jc['params_json'], true); } }
$ok(($r1['success'] ?? false) && count($jobsC1) === 1 && ($jp1['dry_run'] ?? null) === false && ($r2['success'] ?? false) && count($jobsC2) === 2 && ($jp2['dry_run'] ?? null) === true, 'run: an unticked Dry run box runs for real (dry_run false); a ticked one is true (the hidden false before it does not win) ' . json_encode([$r1, $r2, $jobsC1, $jobsC2]));
// approval
$beforeJobs = $jobCount($sDisrupt);
[$c, $j] = $jpost('admin', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sDisrupt, 'script_version' => 1, 'confirm' => '1']);
$ok($c === 200 && ($j['approval'] ?? false) === true && str_contains($j['message'] ?? '', 'second person') && str_contains($j['link'] ?? '', 'rmm_approvals.php') && $jobCount($sDisrupt) === $beforeJobs
    && (int) $one("SELECT COUNT(*) FROM rmm_approvals WHERE state='pending_approval' AND script_id=$sDisrupt") === 1, 'run: a script that needs approval goes to the approvals queue (pending), creates no job, and the answer links Approvals');
// destructive confirmation
$beforeJobs = $jobCount($sDestr);
[$c, $j] = $jpost('admin', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sDestr, 'script_version' => 1]);
$ok($c >= 400 && ($j['success'] ?? true) === false && str_contains(strtolower($j['error'] ?? ''), 'confirm') && $jobCount($sDestr) === $beforeJobs, 'run: a destructive script without the confirmation is refused by Core and queues nothing');
[$c, $j] = $jpost('admin', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sDestr, 'script_version' => 1, 'confirm' => '1']);
$ok($c === 200 && ($j['success'] ?? false) === true && $jobCount($sDestr) === $beforeJobs + 1, 'run: with the confirmation it is queued');
// linux
[$c, $j] = $jpost('admin', ['action' => 'run_script', 'device_id' => $LnxDev, 'library_script_id' => $sLChange, 'script_version' => 1, 'param' => ['age_days' => '3', 'dry_run' => 'true']]);
$jl = $jobFor($sLChange);
$ok($c === 200 && ($j['success'] ?? false) && $jl && $jl['type'] === 'shell' && (int) $jl['device_id'] === $LnxDev, 'run: a Linux library script is queued as a shell job for the Linux device');
// platform mismatch and scoping and ability
$beforeJobs = $jobCount($sLRead);
[$c, $j] = $jpost('admin', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sLRead, 'script_version' => 1]);
$ok($c >= 400 && ($j['success'] ?? true) === false && $jobCount($sLRead) === $beforeJobs, 'run: a forged post of a Linux script to a Windows device is refused (' . $c . ') and queues nothing');
$beforeJobs = $jobCount($sRead);
[$c, $j] = $jpost('clientb', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sRead, 'script_version' => 1, 'param' => ['target_name' => 'x']]);
$ok($c === 404 && $jobCount($sRead) === $beforeJobs, 'run: a user limited to Client B cannot run on a Client A device (404), no job');
[$c, $j] = $jpost('viewer', ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sRead, 'script_version' => 1, 'param' => ['target_name' => 'x']]);
$ok($c >= 400 && $jobCount($sRead) === $beforeJobs, 'run: a viewer cannot run it (' . $c . '), no job');
[$c, $b, $hd] = web($wb, 'POST', $HANDLER, $SS['admin'], ['action' => 'run_script', 'csrf_token' => 'csrftok1', 'device_id' => $WinDev, 'library_script_id' => $sRead, 'script_version' => 1, 'param' => ['target_name' => 'plain'],
    'return' => '/agent/asset_details.php?asset_id=' . $A['WIN1'] . '#rmm-jobs']);
$ok($c === 302 && str_contains($locOf($hd), '/agent/asset_details.php?asset_id=' . $A['WIN1']) && $jobCount($sRead) === $before + 2, 'run: without JavaScript the form still works: a flash and a redirect to the Jobs tab');
[$c, $b] = web($wb, 'POST', $HANDLER, $SS['admin'], ['action' => 'run_script', 'device_id' => $WinDev, 'library_script_id' => $sRead, 'script_version' => 1, 'param' => ['target_name' => 'nocsrf']]);
$ok($jobCount($sRead) === $before + 2, 'run: a post without the CSRF token queues nothing');

// the real asset pages
[$c, $page] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['admin']);
$ok($c === 200 && str_contains($page, 'id="rmmRunModal"') && str_contains($page, 'Script library (') && str_contains($page, 'id="rmm-lib-form"') && str_contains($page, '/js/rmm_panel.js') && preg_match('/id="rmm-act-script"[^>]*disabled/', $page) !== 1, 'asset page (WIN1, admin): the Run script button and the dialog with the library');
[$c, $page] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['LNX1'], $SS['admin']);
$ok($c === 200 && str_contains($page, 'Shell (type or paste)') && str_contains($page, 'RD lin read only') && !str_contains($page, 'RD win read only') && preg_match('/id="rmm-act-script"[^>]*disabled/', $page) !== 1, 'asset page (LNX1, admin): Linux gets the Run script button, shell text and only Linux scripts');
[$c, $page] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['viewer']);
$ok($c === 200 && !str_contains($page, 'rmm-lib-form') && !str_contains($page, 'id="rmmRunModal"') && preg_match('/id="rmm-act-script"[^>]*disabled/', $page) === 1, 'asset page (viewer): no dialog, the button is disabled with its reason');

// switch off / module off: nothing and zero RMM queries
$rmm->admin()->saveSettings($admin, ['features_json' => array_merge($rdFeatures, ['scripts' => false])]); $fresh();
$a = $questions(); $none = rivetRmmScrDeviceChoices($db, 1, 'admin', $vmW['perm'], 'windows'); $b = $questions();
$ok($none === null && $b - $a === $overhead, 'scripts switch off: the library source answers nothing and costs zero statements (' . ($b - $a - $overhead) . ' extra)');
$vmOff = $panel('WIN1', 'admin');
$dOff = rivetRmmUiDialogs($vmOff, 'csrftok1');
$ok($vmOff['run']['library'] === null && !str_contains($dOff, 'rmm-lib-form') && !str_contains($dOff, 'Script library (') && str_contains($dOff, 'PowerShell (type or paste)'), 'scripts switch off: the dialog has no library, and the other sources still work');
[$c, $j] = $jpost('admin', ['action' => 'device_script_form', 'device_id' => $WinDev, 'library_script_id' => $sRead]);
$ok($c === 404 && !isset($j['fragment']) && str_contains($j['error'] ?? '', 'switched off'), 'scripts switch off: the form endpoint answers "switched off"');
$rmm->admin()->saveSettings($admin, ['features_json' => $rdOn]); $fresh();
$rmm->admin()->disable($admin); $fresh();
$a = $questions(); $none = rivetRmmScrDeviceChoices($db, 1, 'admin', $vmW['perm'], 'windows'); $b = $questions();
$ok($none === null && $b - $a === $overhead && $panel('WIN1', 'admin') === null, 'module OFF: the library source answers nothing at zero statements and there is no panel');
$rmm->admin()->enable($admin); $fresh();

// ============================================================ synced scripts in the dialog
$q("INSERT INTO rmm_integrations SET id=7, name='Acme RMM', type='tactical_rmm', api_url='https://rmm.invalid', api_key_enc='', enabled=1");
$q("INSERT INTO asset_rmm_links SET asset_id=" . $A['WIN1'] . ", integration_id=7, tactical_agent_id='tac-agent-1', hostname='WIN1'");
$linkId = (int) $db->insert_id;
$q("INSERT INTO rmm_scripts SET name='RD synced cleanup', category='Maintenance', script_type='powershell', script_body='', tactical_script_id=501, rmm_integration_id=7, enabled=1, created_by=1");
$q("INSERT INTO rmm_scripts SET name='RD synced other provider', category='Maintenance', script_type='powershell', script_body='', tactical_script_id=502, rmm_integration_id=99, enabled=1, created_by=1");
$vmS = $panel('WIN1', 'admin');
$syn = $vmS['run']['synced'];
$dS = rivetRmmUiDialogs($vmS, 'csrftok1');
$ok($syn !== null && $syn['provider'] === 'Acme RMM' && $syn['link_id'] === $linkId && count($syn['items']) === 1 && $syn['items'][0]['name'] === 'RD synced cleanup', 'synced: only scripts that can run through the asset\'s own provider are offered (not another provider\'s)');
$ok(str_contains($dS, '<option value="synced">Synced from Acme RMM (1)</option>') && str_contains($dS, 'Script synced from Acme RMM') && strpos($dS, 'value="saved"') < strpos($dS, 'value="synced"') && strpos($dS, 'value="synced"') < strpos($dS, 'value="powershell"'), 'synced: the dialog lists "Synced from Acme RMM (1)" after the library and the saved scripts');
$ok($panel('LNX1', 'admin')['run']['synced'] === null && $panel('WIN1', 'viewer')['run']['synced'] === null, 'synced: not for an asset without a link to that provider, nor for a role without the older scripts grant');
$q("DELETE FROM asset_rmm_links WHERE id=$linkId");
$ok($panel('WIN1', 'admin')['run']['synced'] === null, 'synced: with no link to the provider the choice is gone');

// ============================================================ the single Scripts area
[$c, $fleet] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok($c === 200 && substr_count($fleet, 'href="/agent/rmm_scripts.php"') === 1 && !str_contains($fleet, 'href="/agent/rmm_script_library.php"') && !str_contains($fleet, '>Script library<') && preg_match('~<span class="text-truncate">Scripts</span>~', $fleet) === 1, 'menu: exactly one Scripts entry, no Script library entry');
// the entry also shows with the older vendor-RMM module off (RivetCore RMM only), and not at all when nothing is on
$q("UPDATE settings SET config_module_enable_rmm=0 WHERE company_id=1");
[$c, $fleet2] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(substr_count($fleet2, 'href="/agent/rmm_scripts.php"') === 1, 'menu: with only the RivetCore RMM on, the Scripts entry is still there (once)');
$rmm->admin()->saveSettings($admin, ['features_json' => array_merge($rdFeatures, ['scripts' => false])]); $fresh();
[$c, $fleet3] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(!str_contains($fleet3, 'href="/agent/rmm_scripts.php"'), 'menu: with the scripts switch off and the older module off there is no Scripts entry');
$rmm->admin()->saveSettings($admin, ['features_json' => $rdOn]); $fresh();
$q("UPDATE settings SET config_module_enable_rmm=1 WHERE company_id=1");

// tabs: the provider's real name labels the synced tab; the default tab is the Library
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php', $SS['admin']);
$ok($c === 200 && str_contains($p, 'id="scripts-tabs"') && str_contains($p, 'id="scripts-tab-library"') && str_contains($p, '>Synced from Acme RMM<') && preg_match('~id="scripts-tab-library"~', $p) === 1
    && preg_match('~class="nav-link active" href="/agent/rmm_scripts\.php\?tab=library" aria-current="page"~', $p) === 1 && str_contains($p, 'Starter pack') , 'Scripts page: tabs Library (default, active) and "Synced from Acme RMM"');
$ok(preg_match('~<a href="/agent/rmm_scripts\.php" class="dropdown-item active">~', $p) === 1, 'menu: the single Scripts entry is the active one on the page');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['admin']);
$ok($c === 200 && preg_match('~class="nav-link active" href="/agent/rmm_scripts\.php\?tab=synced" aria-current="page"~', $p) === 1 && str_contains($p, 'RD legacy saved') && str_contains($p, 'Sync from Acme RMM') && str_contains($p, 'id="scripts-import"'), 'synced tab: the older list, the sync button named after the provider, the import card');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?category=Repair', $SS['admin']);
$ok($c === 200 && preg_match('~class="nav-link active" href="/agent/rmm_scripts\.php\?tab=synced"~', $p) === 1 && str_contains($p, 'RD legacy saved'), 'an older bookmark of the list (?category=) opens the synced tab');
// older addresses
[$c, , $hd] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok($c === 302 && $locOf($hd) === '/agent/rmm_scripts.php?tab=library', 'old address rmm_script_library.php redirects to the Library tab');
[$c, , $hd] = web($wb, 'GET', "/agent/rmm_script_library.php?script_id=$sRead&view=1&q=a%20b", $SS['admin']);
parse_str((string) parse_url($locOf($hd), PHP_URL_QUERY), $lq);
$ok($c === 302 && ($lq['tab'] ?? '') === 'library' && ($lq['script_id'] ?? '') === (string) $sRead && ($lq['view'] ?? '') === '1' && ($lq['q'] ?? '') === 'a b', 'the redirect keeps the query string (script_id, view, q)');
[$c, $p] = web($wb, 'GET', "/agent/rmm_scripts.php?tab=library&script_id=$sRead", $SS['admin']);
$ok($c === 200 && str_contains($p, 'RD win read only') && str_contains($p, 'id="scr-run-form"') && str_contains($p, 'id="scripts-tab-library"'), 'a ?script_id= deep link lands on that script inside the Library tab, tabs still shown');
[$c, $rdBodyRun, $hd] = web($wb, 'GET', '/agent/rmm_script_run.php?run_id=999999', $SS['admin']);
$ok(str_contains($locOf($hd) . ($c === 200 ? $rdBodyRun : ''), 'rmm_scripts.php?tab=synced'), 'old address rmm_script_run.php (unknown run) goes back to the synced tab (' . $c . ')');
$q("INSERT INTO rmm_script_runs SET script_id=$legacySaved, asset_id=" . $A['WIN1'] . ", user_id=1, status='completed', output='hello run'");
$runId = (int) $db->insert_id;
[$c, $p] = web($wb, 'GET', "/agent/rmm_script_run.php?run_id=$runId", $SS['admin']);
$ok($c === 200 && str_contains($p, 'hello run') && str_contains($p, 'href="/agent/rmm_scripts.php?tab=synced"') && preg_match('~<a href="/agent/rmm_scripts\.php" class="dropdown-item active">~', $p) === 1, 'rmm_script_run.php still shows a run; its back link says Scripts and the menu entry stays active');
$q("DELETE FROM rmm_script_runs WHERE id=$runId");
// only one tab: no strip
$q("DELETE FROM rmm_integrations"); $q("DELETE FROM rmm_scripts");
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php', $SS['admin']);
$ok($c === 200 && !str_contains($p, 'id="scripts-tabs"') && str_contains($p, 'RD win read only'), 'no older scripts and no provider: just the Library, no tab strip');
[$c, , $hd] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['admin']);
$ok($c === 200, 'a request for a tab that does not exist falls back to the default tab (' . $c . ')');
$q("INSERT INTO rmm_integrations SET id=7, name='Acme RMM', type='tactical_rmm', api_url='https://rmm.invalid', api_key_enc='', enabled=1");
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php', $SS['admin']);
$ok(str_contains($p, '>Synced from Acme RMM<'), 'a vendor RMM integration alone (no older rows) shows the synced tab, labelled with its name');
$q("DELETE FROM rmm_integrations");
// the Library tab explains when the switch is off
$rmm->admin()->saveSettings($admin, ['features_json' => array_merge($rdFeatures, ['scripts' => false])]); $fresh();
$q("INSERT INTO rmm_scripts SET name='RD legacy off', category='Repair', script_type='powershell', script_body='Get-Date', enabled=1, created_by=1");
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=library', $SS['admin']);
$ok($c === 403 && str_contains($p, 'switched off'), 'Library tab with the scripts switch off: 403 and the switched-off notice');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['admin']);
$ok($c === 200 && str_contains($p, 'RD legacy off') && preg_match('~<div class="alert[^"]*">Import~', $p) !== 1, 'the synced tab still works with the scripts switch off');
$rmm->admin()->saveSettings($admin, ['features_json' => $rdOn]); $fresh();
$q("DELETE FROM rmm_scripts");

// ============================================================ the one-time import
$rdIns = fn(string $name, string $cat, string $type, string $body, ?int $tac = null) => $q("INSERT INTO rmm_scripts SET name='" . $esc($name) . "', category='$cat', description='desc of $name', script_type='$type', script_body='" . $esc($body) . "', tactical_script_id=" . ($tac ?? 'NULL') . ", enabled=1, created_by=1");
$rdIns('RD imp ps', 'Repair', 'powershell', "Get-Date\r\nWrite-Output 'two'");
$rdIns('RD imp bash', 'Maintenance', 'bash', "echo hi");
$rdIns('RD imp synced', 'Repair', 'powershell', 'Get-Process', 777);
$rdIns('RD imp cmd', 'Repair', 'cmd', 'dir');
$rdIns('RD imp empty', 'Repair', 'powershell', '');
$rdIns('RD imp clash', 'Security', 'powershell', 'Get-Clash-Legacy');
$rdMk('RD imp clash', 'powershell', 'read_only');   // a different library script already has the name
$rdIns('RD imp same', 'Security', 'powershell', "Write-Output 'x'");
$rdMk('RD imp same', 'powershell', 'read_only');    // same name AND the same text as the library script: already imported
$rowsBefore = (int) $one('SELECT COUNT(*) FROM rmm_scripts');
$bodiesBefore = $one("SELECT MD5(GROUP_CONCAT(CONCAT(id, script_body, name) ORDER BY id)) FROM rmm_scripts");
$rdRows = rivetScriptsLegacyImportables($db);
$rdSt = array_column($rdRows, 'state', 'name');
$ok($rdSt['RD imp ps'] === 'new' && $rdSt['RD imp bash'] === 'new' && $rdSt['RD imp cmd'] === 'skipped' && $rdSt['RD imp empty'] === 'skipped' && $rdSt['RD imp same'] === 'imported' && $rdSt['RD imp clash'] === 'new' && !isset($rdSt['RD imp synced']), 'import: the hand-made rows only (a row synced from a vendor RMM is not one); CMD and empty are skipped; same name and text = already imported');
$ok(array_column($rdRows, 'target', 'name')['RD imp clash'] === 'RD imp clash (legacy)', 'import: a different script with the same name sends the copy to "<name> (legacy)"');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['admin']);
$ok($c === 200 && str_contains($p, 'name="import_legacy"') && str_contains($p, 'name="import_risk"') && preg_match('/Import \d+ into the Library/', $p) === 1 && str_contains($p, '>Imported<'), 'synced tab (administrator): the import button, the risk class choice, and the "Imported" mark on the row already in the Library');
$libBefore = (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2');
$rdImp = fn(string $who, array $f = []) => web($wb, 'POST', '/agent/rmm_scripts.php?tab=synced', $SS[$who], ['csrf_token' => 'csrftok1', 'import_legacy' => '1'] + $f);
[$c, , $hd] = $rdImp('viewer');
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libBefore, 'import: a user without write access to the older scripts imports nothing (' . $c . ')');
[$c, $b] = web($wb, 'POST', '/agent/rmm_scripts.php?tab=synced', $SS['admin'], ['import_legacy' => '1']);
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libBefore, 'import: without the CSRF token nothing is imported');
[$c, , $hd] = $rdImp('admin');
$rdNew = (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') - $libBefore;
$ok($c === 302 && $rdNew === 3, "import: three scripts created (ps, bash, the clashing name as (legacy)) - got $rdNew");
$ps = $db->query("SELECT * FROM rmm_scripts_v2 WHERE name='RD imp ps'")->fetch_assoc();
$psv = $db->query("SELECT * FROM rmm_script_versions WHERE script_id=" . (int) $ps['script_id'])->fetch_assoc();
$tags = json_decode((string) $ps['tags_json'], true);
$ok($ps && $ps['language'] === 'powershell' && $ps['platform'] === 'windows' && in_array('risk:changes_system', $tags, true) && in_array('imported', $tags, true) && in_array('Repair', $tags, true) && (int) $ps['requires_approval'] === 0 && (int) $ps['destructive'] === 0, 'import: language/platform as stored, tags from the old category plus risk:changes_system, no approval by default');
$ok($psv && $psv['note'] === 'imported from legacy Scripts' && $psv['body_sha256'] === hash('sha256', "Get-Date\nWrite-Output 'two'") && (int) $psv['version'] === 1, 'import: version 1 signed with the note "imported from legacy Scripts" and the text with \\n line ends');
$ok((int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE name='RD imp clash (legacy)'") === 1 && (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE name='RD imp same'") === 1 && (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE name IN ('RD imp cmd','RD imp empty','RD imp synced')") === 0, 'import: skipped rows were not created; the already-imported name was not duplicated');
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts') === $rowsBefore && $one("SELECT MD5(GROUP_CONCAT(CONCAT(id, script_body, name) ORDER BY id)) FROM rmm_scripts") === $bodiesBefore, 'import: the older rows are untouched');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='RMM' AND log_action='Scripts Import'") === 1, 'import: audited (RMM / Scripts Import)');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['admin']);
$ok(substr_count($p, '>Imported<') === 4, 'after the import the four rows that are in the Library show "Imported" (' . substr_count($p, '>Imported<') . ')');
$libAfter = (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2');
[$c, , $hd] = $rdImp('admin');
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libAfter, 'import: a second run creates nothing (idempotent by name and text)');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['admin']);
$ok(str_contains($p, 'Nothing is left to import') && !str_contains($p, 'name="import_legacy"'), 'after a complete import the card says nothing is left');
// the administrator picks the class
$rdIns('RD imp disruptive', 'Repair', 'powershell', 'Restart-Computer -WhatIf');
[$c, , $hd] = $rdImp('admin', ['import_risk' => 'disruptive']);
$dis = $db->query("SELECT * FROM rmm_scripts_v2 WHERE name='RD imp disruptive'")->fetch_assoc();
$ok($dis && in_array('risk:disruptive', json_decode((string) $dis['tags_json'], true), true) && (int) $dis['requires_approval'] === 1 && (int) $dis['destructive'] === 1, 'import: an administrator can choose disruptive: marked destructive and needing approval');
// read-only class
$rdIns('RD imp ro', 'Inventory', 'powershell', 'Get-Service');
[$c, , $hd] = $rdImp('admin', ['import_risk' => 'read_only']);
$ro = $db->query("SELECT * FROM rmm_scripts_v2 WHERE name='RD imp ro'")->fetch_assoc();
$ok($ro && in_array('risk:read_only', json_decode((string) $ro['tags_json'], true), true) && (int) $ro['requires_approval'] === 0, 'import: read only class tagged, no approval');
// permissions on the page: a viewer sees the explanation, not the button
$rdIns('RD imp later', 'Repair', 'powershell', 'Get-Later');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['viewer']);
$ok($c === 200 && str_contains($p, 'id="scripts-import"') && !str_contains($p, 'name="import_legacy"') && str_contains($p, 'Only an administrator'), 'import card for a user without write access: the explanation, no button');
[$c, $p] = web($wb, 'GET', '/agent/rmm_scripts.php?tab=synced', $SS['tech']);
$ok($c === 200 && str_contains($p, 'name="import_legacy"'), 'import card for a technician with write access to scripts: the button (Core checks them again per script)');
// the scripts switch off: refused, nothing created
$rmm->admin()->saveSettings($admin, ['features_json' => array_merge($rdFeatures, ['scripts' => false])]); $fresh();
$libNow = (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2');
[$c, , $hd] = $rdImp('admin');
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libNow, 'import: with the scripts switch off nothing is created');
$rmm->admin()->saveSettings($admin, ['features_json' => $rdOn]); $fresh();

// ---- put everything back (the scripts, their versions, jobs and approvals this fragment made, so later fragments start from what they expect)
$rdScripts = $db->query("SELECT script_id FROM rmm_scripts_v2 WHERE name LIKE 'RD %'");
$rdList = [0];
while ($rdScripts && ($rdRow = $rdScripts->fetch_row())) { $rdList[] = (int) $rdRow[0]; }
$rdIn = implode(',', $rdList);
$q("DELETE j FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id IN ($rdIn)");
$q("DELETE FROM rmm_job_extra WHERE script_id IN ($rdIn)");
$q("DELETE FROM rmm_approvals WHERE script_id IN ($rdIn)");
$q("DELETE FROM rmm_script_versions WHERE script_id IN ($rdIn)");
$q("DELETE FROM rmm_scripts_v2 WHERE script_id IN ($rdIn)");
$q("DELETE FROM rmm_scripts"); $q("DELETE FROM rmm_integrations"); $q("DELETE FROM asset_rmm_links WHERE tactical_agent_id='tac-agent-1'");
$rmm->admin()->saveSettings($admin, ['features_json' => $rdFeatures]); $fresh();
$rmm->admin()->enable($admin); $fresh();
