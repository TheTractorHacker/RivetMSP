<?php

/*
 * Administration > Endpoint agent > "What the agent checks": the plain-option form (pure rendering; the logic is RivetChecksSimple, the script is
 * js/rmm_checks_simple.js). Every printed value is escaped. Works without JavaScript (presets, the live summary and "add another script" need it).
 */

require_once __DIR__ . '/rmm_checks_simple.php';

/**
 * @param array<string,mixed> $st RivetChecksSimple::decompose() shape (or the options as typed after a refused save)
 * @param list<string> $errors plain-word problems from the last refused save
 */
function rivetChecksSimpleCard(array $st, bool $isDefault, string $savedJson, array $errors): string
{
    $e = static fn ($v): string => nullable_htmlentities((string) $v);
    $o = '<div class="col-12" id="ea_checks_simple" data-cs-root data-cs-presets="' . $e(json_encode(RivetChecksSimple::PRESETS, JSON_UNESCAPED_SLASHES)) . '">'
        . '<input type="hidden" name="cs[present]" value="1"><input type="hidden" name="cs[reset]" value="0" data-cs-reset>'
        . '<div class="border rounded p-3"><h5 class="h5 mb-1"><i class="fas fa-fw fa-heartbeat me-1" aria-hidden="true"></i>What the agent checks</h5>'
        . '<p class="text-muted small mb-2">Pick the checks every enrolled computer runs. The agents report the result; you get an alert when something is wrong. Nothing changes until you press Save settings.</p>';
    if ($errors !== []) {
        $o .= '<div class="alert alert-danger py-2" role="alert" data-cs-errors><strong>Not saved.</strong><ul class="mb-0">';
        foreach ($errors as $m) {
            $o .= '<li>' . $e($m) . '</li>';
        }
        $o .= '</ul></div>';
    }
    if ($isDefault) {
        $o .= '<div class="alert alert-info py-2 small" role="note" data-cs-default-note>You are using the built-in checks. The options below show the recommended set; saving stores your choice.</div>';
    }
    // presets
    $o .= '<div class="mb-3"><div class="small fw-semibold mb-1">Start from a ready-made set</div><div class="d-flex flex-wrap gap-2" role="group" aria-label="Ready-made sets">';
    foreach (RivetChecksSimple::PRESETS as $id => $p) {
        $o .= '<button type="button" class="btn btn-sm btn-outline-primary" data-cs-preset="' . $e($id) . '" title="' . $e($p['note']) . '">' . $e($p['label']) . '</button>';
    }
    $o .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-cs-restore title="Go back to the checks that come with the product"><i class="fas fa-undo me-1" aria-hidden="true"></i>Restore defaults</button></div>'
        . '<div class="form-text" data-cs-preset-note>Recommended: ' . $e(RivetChecksSimple::PRESETS['recommended']['note']) . ' Light: ' . $e(RivetChecksSimple::PRESETS['light']['note']) . ' Strict: ' . $e(RivetChecksSimple::PRESETS['strict']['note']) . '</div></div>';

    // how often
    $every = (int) $st['every'];
    $o .= '<div class="row g-3 mb-3"><div class="col-md-5"><label class="form-label" for="cs_every">How often to check</label><select class="form-select" id="cs_every" name="cs[every]" data-cs-every>';
    $opts = RivetChecksSimple::EVERY_MAIN;
    if (!in_array($every, $opts, true)) {
        $opts[] = $every;
        sort($opts);
    }
    foreach ($opts as $s) {
        $label = RivetChecksSimple::EVERY[$s] ?? ucfirst(RivetChecksSimple::everyWords($s)) . ' (current setting)';
        $o .= '<option value="' . (int) $s . '"' . ($s === $every ? ' selected' : '') . ' data-cs-words="' . $e(RivetChecksSimple::everyWords($s)) . '">' . $e($label) . '</option>';
    }
    $o .= '</select><div class="form-text">How often each computer runs the checks below and reports back. More often finds problems sooner and sends more traffic.</div></div></div>';

    // disk + reboot
    $o .= '<div class="row g-3 mb-3"><div class="col-lg-6"><div class="card h-100"><div class="card-body">'
        . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="cs_disk_on" name="cs[disk_on]" value="1" data-cs-count="1"' . ($st['disk']['on'] ? ' checked' : '') . '><label class="form-check-label fw-semibold" for="cs_disk_on">Low disk space</label></div>'
        . '<div class="form-text mb-2">Warns when a disk is nearly full. It turns critical at half that amount.</div>'
        . '<label class="form-label small mb-1" for="cs_disk_free">Warn me when free space falls below</label><select class="form-select form-select-sm" id="cs_disk_free" name="cs[disk_free]">';
    foreach (RivetChecksSimple::DISK_FREE as $v => $l) {
        $o .= '<option value="' . (int) $v . '"' . ((int) $st['disk']['free'] === $v ? ' selected' : '') . '>' . $e($l) . ' free</option>';
    }
    $o .= '</select></div></div></div>'
        . '<div class="col-lg-6"><div class="card h-100"><div class="card-body">'
        . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="cs_reboot_on" name="cs[reboot_on]" value="1" data-cs-count="1"' . ($st['reboot']['on'] ? ' checked' : '') . '><label class="form-check-label fw-semibold" for="cs_reboot_on">Waiting for a restart</label></div>'
        . '<div class="form-text">Warns while Windows has updates or changes that only finish after a restart. It clears by itself once the computer restarts.</div>'
        . '</div></div></div></div>';

    // services
    $chosen = [];
    $extra = [];
    foreach ($st['services'] as $s) {
        if (isset(RivetChecksSimple::SERVICES[$s['name']])) {
            $chosen[$s['name']] = true;
        } else {
            $extra[] = $s['name'];
        }
    }
    $o .= '<div class="card mb-3"><div class="card-body"><div class="fw-semibold">Important services stopped</div>'
        . '<div class="form-text mb-2">Tick the services that must always be running. You get an alert if one stops or is missing. A service that does not exist on a computer is reported for that computer only.</div>'
        . '<div class="row">';
    foreach (RivetChecksSimple::SERVICES as $name => [$label, $plat]) {
        $id = 'cs_svc_' . preg_replace('/[^A-Za-z0-9]/', '', $name);
        $o .= '<div class="col-md-6 col-xl-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="cs[svc][]" value="' . $e($name) . '" data-cs-count="1"' . (isset($chosen[$name]) ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . $e($label) . ' <span class="badge bg-secondary-lt text-muted">' . $e($plat) . '</span></label></div></div>';
    }
    $extraText = isset($st['extra_raw']) ? (string) $st['extra_raw'] : implode("\n", $extra);
    $o .= '</div><label class="form-label small mt-2 mb-1" for="cs_svc_extra">Add another service (one name per line)</label>'
        . '<textarea class="form-control form-control-sm" rows="2" id="cs_svc_extra" name="cs[svc_extra]" maxlength="4000" placeholder="For example: MSSQLSERVER" data-cs-extra>' . $e($extraText) . '</textarea>'
        . '<div class="form-text">Use the service name (not the display name): on Windows, open Services and read the properties; on Linux, the unit name such as <code>nginx</code>.</div></div></div>';

    // other checks kept
    if ($st['others'] !== []) {
        $o .= '<div class="card mb-3" data-cs-others><div class="card-body"><div class="fw-semibold">Other checks already in your schedule</div>'
            . '<div class="form-text mb-2">These were set up in a more detailed way than this page offers. They keep running exactly as they are. Untick one to remove it.</div>';
        foreach ($st['others'] as $i => $c) {
            $keep = !isset($c['keep']) || $c['keep'];
            $o .= '<div class="form-check"><input class="form-check-input" type="checkbox" id="cs_keep' . (int) $i . '" name="cs[keep][]" value="' . $e($c['key']) . '" data-cs-count="1"' . ($keep ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="cs_keep' . (int) $i . '">' . $e($c['text']) . ' <span class="text-muted small">(' . $e($c['key']) . ')</span></label></div>';
        }
        $o .= '</div></div>';
    }

    // scripts
    $o .= '<details class="mb-3" data-cs-scripts' . ($st['scripts'] !== [] ? ' open' : '') . '><summary class="fw-semibold">Advanced: run your own script</summary>'
        . '<div class="alert alert-warning py-2 mt-2" role="note"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><strong>A script runs as code on every enrolled computer.</strong> '
        . 'Only add one you wrote or fully trust. It is signed by this server before it is sent. It may be at most 8 KiB (about 8,000 characters) and is stopped after the time you choose (at most 1 minute). '
        . 'It reports OK when it exits with 0, a warning with 1 and a failure with anything else. This is off unless you add a script.</div>'
        . '<div data-cs-script-list>';
    $i = 0;
    foreach ($st['scripts'] as $s) {
        $o .= rivetChecksSimpleScriptRow((string) $i++, $s);
    }
    $o .= '</div><button type="button" class="btn btn-sm btn-outline-secondary" data-cs-add-script><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a script</button>'
        . '<template data-cs-script-template>' . rivetChecksSimpleScriptRow('__i__', ['name' => '', 'body' => '', 'timeout' => 30]) . '</template></details>';

    // summary + technical view
    $o .= '<div class="alert alert-secondary py-2 mb-2" role="status" aria-live="polite"><i class="fas fa-list-check me-1" aria-hidden="true"></i><strong data-cs-summary>' . $e(RivetChecksSimple::summary($st)) . '</strong></div>'
        . '<details><summary class="small text-muted">Advanced: technical view of the saved schedule (read only)</summary>'
        . '<p class="small text-muted mt-2 mb-1">This is the list currently saved and sent to agents. The server signs every entry before delivery. You cannot edit it here; change the options above and save.</p>'
        . '<pre class="small bg-body-tertiary border rounded p-2 mb-0" style="max-height:18rem;overflow:auto" data-cs-saved>' . $e($savedJson) . '</pre></details>'
        . '</div></div>';

    return $o;
}

/** @param array<string,mixed> $s */
function rivetChecksSimpleScriptRow(string $i, array $s): string
{
    $e = static fn ($v): string => nullable_htmlentities((string) $v);
    $nm = 'cs[scripts][' . $i . ']';
    $id = 'cs_sc' . $i;
    $o = '<div class="border rounded p-2 mb-2" data-cs-script-row><div class="row g-2">'
        . '<div class="col-md-6"><label class="form-label small mb-1" for="' . $id . '_n">Name</label><input type="text" class="form-control form-control-sm" id="' . $id . '_n" name="' . $nm . '[name]" maxlength="100" value="' . $e($s['name'] ?? '') . '" placeholder="Backup finished today"></div>'
        . '<div class="col-md-4"><label class="form-label small mb-1" for="' . $id . '_t">Stop it after</label><select class="form-select form-select-sm" id="' . $id . '_t" name="' . $nm . '[timeout]">';
    $to = (int) ($s['timeout'] ?? 30);
    $opts = RivetChecksSimple::SCRIPT_TIMEOUTS;
    if (!isset($opts[$to])) {
        $opts[$to] = $to . ' seconds (current setting)';
        ksort($opts);
    }
    foreach ($opts as $v => $l) {
        $o .= '<option value="' . (int) $v . '"' . ($v === $to ? ' selected' : '') . '>' . $e($l) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2 text-md-end align-self-end"><button type="button" class="btn btn-sm btn-outline-danger" data-cs-remove-script aria-label="Remove this script"><i class="fas fa-times" aria-hidden="true"></i> Remove</button></div>'
        . '<div class="col-12"><label class="form-label small mb-1" for="' . $id . '_b">Script</label><textarea class="form-control form-control-sm font-monospace" rows="5" id="' . $id . '_b" name="' . $nm . '[body]" spellcheck="false" data-cs-body>' . $e($s['body'] ?? '') . '</textarea>'
        . '<div class="form-text"><span data-cs-bytes>0</span> of 8,192 bytes used.</div></div></div></div>';

    return $o;
}
