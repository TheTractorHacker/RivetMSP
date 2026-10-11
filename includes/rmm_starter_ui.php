<?php

/*
 * RMM starter pack page (RivetCore 1.0.0-rc.13, sub-switch `scripts`): browse the ready-made scripts, check template sets and policy drafts, preview what an install would
 * do, install the ones you pick, and uninstall untouched ones (agent/rmm_starter_pack.php, handler agent/post/rmm_starter.php). Administrators only (rmm.admin).
 *
 * ONE AUTHORIZATION PATH. Every read and write is a call into RivetCore\Rmm\Technician\StarterActions as the signed-in user ($rmm->starterActions() with
 * rivetRmmPrincipal()): Core decides who may (rmm.admin), plans, installs through the script library (signed with the instance key) and the policy store, and writes the
 * activity-log lines and the rmm.starter.installed event. Hiding a button is cosmetic. Nothing here edits a script or a policy, and an empty selection is NEVER sent to Core
 * (Core reads an empty selection as the whole pack).
 *
 * The view-model functions return arrays and are what the tests call; the renderers return HTML strings. Server text (script names, descriptions, Core's messages) is escaped
 * where it is printed; no script text is ever shown here.
 */

require_once __DIR__ . '/rmm_automation.php';
require_once __DIR__ . '/rmm_scr_ui.php';   // rivetRmmScrSecondsWords(), the script library helpers

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Technician\ActionResult;

const RIVET_RMM_STARTER_HANDLER = 'rmm_starter';
const RIVET_RMM_STARTER_PAGE = '/agent/rmm_starter_pack.php';
const RIVET_RMM_STARTER_MAX_IDS = 500;
/** What the page remembers of the last preview/install/uninstall until the next page load (the result is shown once). */
const RIVET_RMM_STARTER_SESSION = 'rmm_starter_result';

// ------------------------------------------------------------------ the gate

/**
 * The first thing the page does: module on, `scripts` switch on, the user may view devices AND administer the module. Returns the page context, or null after rendering the
 * denial (the caller then stops). With the module or the switch off nothing asks the database anything.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmStarterPage(\mysqli $mysqli, int $userId, string $userName): ?array
{
    $ctx = rivetRmmAutoPage($mysqli, 'scripts', $userId, $userName);
    if ($ctx === null) {
        return null;
    }
    if (!$ctx['perm']['admin']) {
        rivetRmmAutoDenied('The starter pack puts signed scripts and policy drafts into the library, so only administrators can use it.', 'No access');

        return null;
    }
    $GLOBALS['rmm_starter_scripts'] = true;   // js/rmm_starter.js

    return $ctx;
}

/** A technician-API style result array from an ActionResult (what rivetRmmAutoDone() takes). @return array{ok:bool,status:int,error:string,code:string,data:array<string,mixed>} */
function rivetRmmStarterResult(ActionResult $r): array
{
    return ['ok' => $r->ok, 'status' => $r->http, 'error' => $r->ok ? '' : $r->message, 'code' => $r->code, 'data' => $r->data];
}

// ------------------------------------------------------------------ words

/** @return array<string,array{0:string,1:string}> state => [pill kind, words] */
function rivetRmmStarterStateWords(): array
{
    return [
        'not_installed' => ['off', 'Not installed'], 'current' => ['ok', 'Installed'], 'update_available' => ['info', 'Update available'], 'modified' => ['warn', 'Edited by you'],
        'update_staged' => ['warn', 'Update waiting for review'], 'retired' => ['off', 'Retired'],
    ];
}

function rivetRmmStarterStatePill(string $state): string
{
    [$kind, $words] = rivetRmmStarterStateWords()[$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];

    return rivetRmmUiPill($kind, $words);
}

/** The risk of a starter script as a chip: icon and words, never colour alone. */
function rivetRmmStarterRiskChip(string $risk): string
{
    return rivetRmmScrRiskChip($risk);
}

/** @return array<string,array{0:string,1:string}> plan action or install outcome => [pill kind, words] */
function rivetRmmStarterActionWords(): array
{
    return [
        'add' => ['info', 'Will be added'], 'update' => ['info', 'Will be updated'], 'stage' => ['warn', 'New version waits for your review'], 'unchanged' => ['ok', 'Already up to date'],
        'skip' => ['off', 'Left alone'], 'blocked' => ['crit', 'Blocked'],
        'added' => ['ok', 'Added'], 'updated' => ['ok', 'Updated'], 'staged' => ['warn', 'Parked for review'], 'skipped' => ['off', 'Left alone'], 'failed' => ['crit', 'Failed'],
        'would_retire' => ['info', 'Would be retired'], 'would_delete' => ['info', 'Would be deleted'], 'retired' => ['ok', 'Retired'], 'deleted' => ['ok', 'Deleted'],
    ];
}

function rivetRmmStarterPlatformWords(string $platform): string
{
    return $platform === 'windows' ? 'Windows' : ($platform === 'linux' ? 'Linux' : ucfirst($platform));
}

// ------------------------------------------------------------------ the selection (form -> Core)

/**
 * The selection a form posted: the ticked ids (a-z, 0-9 and dashes only, at most 500) and the policy parameter values typed for the ticked policies. Empty values are left out so
 * Core's defaults apply. NEVER returns an empty id list as "everything": the caller must refuse an empty selection before calling Core.
 *
 * @param array<string,mixed> $post
 * @return array{ids:list<string>,policy_params:array<string,array<string,string>>}
 */
function rivetRmmStarterReadSelection(array $post): array
{
    $ids = [];
    foreach ((array) ($post['ids'] ?? []) as $id) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{1,60}$/', $id) === 1 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        if (count($ids) >= RIVET_RMM_STARTER_MAX_IDS) {
            break;
        }
    }
    $pp = [];
    foreach ((array) ($post['pp'] ?? []) as $policy => $params) {
        if (!is_string($policy) || !in_array($policy, $ids, true) || !is_array($params)) {
            continue;   // only the parameters of a ticked policy are used
        }
        foreach ($params as $name => $value) {
            if (is_string($name) && preg_match('/^[a-z0-9_]{1,40}$/', $name) === 1 && is_string($value) && trim($value) !== '') {
                $pp[$policy][$name] = mb_substr(str_replace("\r", '', trim($value)), 0, 2000);
            }
        }
    }

    return ['ids' => $ids, 'policy_params' => $pp];
}

// ------------------------------------------------------------------ the page: view-model

/**
 * @param array<string,mixed> $get
 * @return array{filters:array<string,string>,ok:bool,error:string,ready:bool,blocker:?string,pack:array<string,mixed>,counts:array<string,int>,scripts:list<array<string,mixed>>,
 *     policies:list<array<string,mixed>>,check_sets:list<array<string,mixed>>,totals:array<string,int>,result:?array<string,mixed>,tags:list<string>,shown:array<string,int>}
 */
function rivetRmmStarterView(array $ctx, array $get): array
{
    $str = static fn (string $k, int $max): string => is_string($get[$k] ?? null) ? mb_substr(trim($get[$k]), 0, $max) : '';
    $platform = $str('platform', 10);
    $risk = $str('risk', 20);
    $state = $str('state', 20);
    $f = ['q' => $str('q', 100), 'platform' => in_array($platform, ['windows', 'linux'], true) ? $platform : '',
        'risk' => in_array($risk, ['read_only', 'changes_system', 'disruptive'], true) ? $risk : '', 'tag' => preg_match('/^[a-z0-9_.:-]{1,30}$/i', $str('tag', 30)) === 1 ? strtolower($str('tag', 30)) : '',
        'state' => array_key_exists($state, rivetRmmStarterStateWords()) ? $state : ''];
    $vm = ['filters' => $f, 'ok' => false, 'error' => '', 'ready' => false, 'blocker' => null, 'pack' => [], 'counts' => [], 'scripts' => [], 'policies' => [], 'check_sets' => [], 'totals' => ['scripts' => 0, 'installed' => 0, 'policies' => 0],
        'result' => null, 'tags' => [], 'shown' => ['scripts' => 0, 'policies' => 0]];
    $r = $_SESSION[RIVET_RMM_STARTER_SESSION] ?? null;
    unset($_SESSION[RIVET_RMM_STARTER_SESSION]);
    $vm['result'] = is_array($r) ? $r : null;

    $filter = [];
    foreach (['q' => 'q', 'platform' => 'platform'] as $k => $to) {
        if ($f[$k] !== '') {
            $filter[$to] = $f[$k];
        }
    }
    if ($f['risk'] !== '') {
        $filter['risk'] = [$f['risk']];
    }
    if ($f['tag'] !== '') {
        $filter['tags'] = [$f['tag']];
    }
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    try {
        $res = rivetRmmModule($ctx['mysqli'])->starterActions()->catalog($who, $filter);
        $all = $f === ['q' => '', 'platform' => '', 'risk' => '', 'tag' => '', 'state' => ''] ? $res : rivetRmmModule($ctx['mysqli'])->starterActions()->catalog($who, []);
    } catch (\Throwable $e) {
        $vm['error'] = 'The starter pack could not be read.';

        return $vm;
    }
    if (!$res->ok || !$all->ok) {
        $vm['error'] = $res->ok ? $all->message : $res->message;

        return $vm;
    }
    $vm['ok'] = true;
    $vm['ready'] = (bool) ($res->data['ready'] ?? false);
    $vm['blocker'] = isset($res->data['blocker']) ? (string) $res->data['blocker'] : null;
    $pack = (array) (($res->data['packs'][0] ?? null) ?: ($all->data['packs'][0] ?? []));
    $vm['pack'] = (array) ($pack['pack'] ?? []);
    $vm['counts'] = array_map('intval', (array) ($pack['counts'] ?? []));
    $vm['check_sets'] = (array) ($pack['check_sets'] ?? []);
    // totals are always of the WHOLE pack, whatever the filter shows
    $allPack = (array) ($all->data['packs'][0] ?? []);
    $vm['totals'] = ['scripts' => count((array) ($allPack['scripts'] ?? [])), 'installed' => count(array_filter((array) ($allPack['scripts'] ?? []), static fn (array $s): bool => $s['state'] !== 'not_installed')),
        'policies' => count((array) ($allPack['policies'] ?? [])), 'policies_installed' => count(array_filter((array) ($allPack['policies'] ?? []), static fn (array $p): bool => $p['state'] !== 'not_installed'))];
    $tags = [];
    foreach ((array) ($allPack['scripts'] ?? []) as $s) {
        foreach ((array) $s['tags'] as $t) {
            $tags[(string) $t] = 1;
        }
    }
    ksort($tags);
    $vm['tags'] = array_map('strval', array_keys($tags));
    $keep = static fn (array $i): bool => $f['state'] === '' || $i['state'] === $f['state'];
    $vm['scripts'] = array_values(array_filter((array) ($pack['scripts'] ?? []), $keep));
    // a risk or tag filter is about scripts: a policy draft has neither, so it is not listed under one (it comes with a search, a platform, a state, or the whole pack)
    $vm['policies'] = $f['risk'] !== '' || $f['tag'] !== '' ? [] : array_values(array_filter((array) ($pack['policies'] ?? []), $keep));
    $vm['shown'] = ['scripts' => count($vm['scripts']), 'policies' => count($vm['policies'])];

    return $vm;
}

/**
 * What to remember of a Core plan/install/uninstall answer until the next page load: names and words only, never script text.
 *
 * @param array<string,mixed> $data an ActionResult's data
 * @param array{ids:list<string>,policy_params:array<string,array<string,string>>} $sel
 * @return array<string,mixed>
 */
function rivetRmmStarterStash(string $kind, array $data, array $sel): array
{
    $items = [];
    foreach ((array) ($data['items'] ?? []) as $i) {
        if (!is_array($i)) {
            continue;
        }
        $items[] = ['kind' => (string) ($i['kind'] ?? ''), 'id' => (string) ($i['id'] ?? ''), 'name' => (string) ($i['name'] ?? ''), 'platform' => (string) ($i['platform'] ?? ''), 'risk' => $i['risk'] ?? null,
            'what' => (string) ($i['outcome'] ?? $i['action'] ?? ''), 'text' => (string) ($i['message'] ?? $i['reason'] ?? ''), 'omitted' => array_values(array_map('strval', (array) ($i['omitted'] ?? [])))];
    }

    return ['kind' => $kind, 'ready' => $data['ready'] ?? null, 'blocker' => $data['blocker'] ?? null, 'items' => $items, 'counts' => array_map('intval', (array) ($data['counts'] ?? [])),
        'ids' => $sel['ids'], 'policy_params' => $sel['policy_params']];
}

// ------------------------------------------------------------------ renderers

/** One line: "3 added, 1 left alone". */
function rivetRmmStarterCountsText(array $counts): string
{
    $words = rivetRmmStarterActionWords();
    $p = [];
    foreach ($counts as $k => $n) {
        $p[] = (int) $n . ' ' . mb_strtolower($words[(string) $k][1] ?? str_replace('_', ' ', (string) $k));
    }

    return $p === [] ? 'nothing selected' : implode(', ', $p);
}

/** The result of the last preview / install / uninstall, with the follow-up button of a preview. @param array<string,mixed> $res */
function rivetRmmStarterResultCard(array $res, string $csrf): string
{
    $titles = ['plan' => ['Preview: what an install would do', 'search'], 'install' => ['Install result', 'check-circle'], 'uninstall_preview' => ['Preview: what an uninstall would do', 'search'], 'uninstall' => ['Uninstall result', 'trash-alt']];
    [$title, $icon] = $titles[$res['kind']] ?? ['Result', 'info-circle'];
    $words = rivetRmmStarterActionWords();
    $rows = '';
    foreach ((array) $res['items'] as $i) {
        [$kind, $w] = $words[$i['what']] ?? ['off', ucfirst(str_replace('_', ' ', (string) $i['what']))];
        $rows .= '<tr><td class="ps-3">' . rmmH($i['name']) . '<div class="small text-muted">' . ($i['kind'] === 'policy' ? 'Policy draft' : 'Script') . ($i['platform'] !== '' ? ', ' . rmmH(rivetRmmStarterPlatformWords($i['platform'])) : '') . '</div></td>'
            . '<td>' . rivetRmmUiPill($kind, $w) . '</td><td class="small">' . rmmH($i['text'])
            . ($i['omitted'] !== [] ? '<div class="text-muted">Left out because their value is empty: ' . rmmH(implode(', ', $i['omitted'])) . '.</div>' : '') . '</td></tr>';
    }
    $table = $rows === '' ? '<p class="text-muted small mb-0">Nothing matched.</p>'
        : '<div class="table-responsive"><table class="table table-sm table-hover mb-0 rmm-starter-result"><caption class="visually-hidden">' . rmmH($title) . '</caption>' . rivetRmmUiThead(['Item', 'Outcome', 'Why']) . '<tbody>' . $rows . '</tbody></table></div>';
    $blocker = !empty($res['blocker']) ? '<div class="alert alert-warning py-2 m-3" role="alert"><div><i class="fas fa-key me-1" aria-hidden="true"></i>' . rmmH((string) $res['blocker']) . '</div></div>' : '';
    $follow = '';
    $canGo = $res['ids'] !== [] && ($res['ready'] ?? true) !== false && array_filter((array) $res['items'], static fn (array $i): bool => in_array($i['what'], ['add', 'update', 'stage', 'would_retire', 'would_delete'], true)) !== [];
    if ($canGo && in_array($res['kind'], ['plan', 'uninstall_preview'], true)) {
        $hidden = '';
        foreach ($res['ids'] as $id) {
            $hidden .= '<input type="hidden" name="ids[]" value="' . rmmH($id) . '">';
        }
        foreach ($res['policy_params'] as $policy => $params) {
            foreach ($params as $n => $v) {
                $hidden .= '<input type="hidden" name="pp[' . rmmH($policy) . '][' . rmmH($n) . ']" value="' . rmmH($v) . '">';
            }
        }
        $go = $res['kind'] === 'plan' ? ['install', 'btn btn-primary', '<i class="fas fa-download me-1" aria-hidden="true"></i>Install these', null]
            : ['uninstall', 'btn btn-danger', '<i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Uninstall these', 'Uninstall these items? Scripts are retired (their history stays) and untouched policy drafts are deleted. Anything you edited is left alone.'];
        $follow = '<form method="post" action="/agent/post/' . RIVET_RMM_STARTER_HANDLER . '.php" class="p-3 border-top d-flex flex-wrap align-items-center gap-2" data-rmm-starter-followup>'
            . rivetRmmAutoHidden($csrf, RIVET_RMM_STARTER_PAGE) . '<input type="hidden" name="action" value="' . $go[0] . '">' . $hidden
            . '<button type="submit" class="' . $go[1] . '"' . ($go[3] !== null ? ' data-rmm-confirm="' . rmmH($go[3]) . '"' : '') . '>' . $go[2] . '</button>'
            . '<span class="small text-muted">Only what the table above says will happen, and each change is written to the activity log.</span></form>';
    }

    return rivetRmmUiCard($title, $icon, $blocker . $table . $follow, $res['kind'] === 'install' || $res['kind'] === 'uninstall' ? 'success' : 'info',
        '<span class="small text-muted" data-rmm-starter-counts>' . rmmH(rivetRmmStarterCountsText((array) $res['counts'])) . '</span>', true, 'starter-result');
}

/** @param array<string,mixed> $s a catalog script */
function rivetRmmStarterScriptRow(array $s): string
{
    $id = (string) $s['id'];
    $params = '';
    foreach ((array) $s['params'] as $p) {
        $params .= '<li><code>' . rmmH($p['name']) . '</code> ' . rmmH((string) ($p['type'] ?? '')) . (!empty($p['required']) ? ', required' : (array_key_exists('default', $p) && $p['default'] !== null ? ', default ' . rmmH(is_bool($p['default']) ? ($p['default'] ? 'yes' : 'no') : (string) $p['default']) : '')) . '</li>';
    }
    $more = '<details class="rmm-starter-more"><summary class="small">Details</summary><div class="small mt-1">' . ($s['output'] !== '' ? '<p class="mb-1"><strong>Output:</strong> ' . rmmH((string) $s['output']) . '</p>' : '')
        . '<p class="mb-1"><strong>Time limit:</strong> ' . rmmH(rivetRmmScrSecondsWords((int) $s['timeout_s'])) . '. <strong>Tags:</strong> ' . rmmH(implode(', ', array_map('strval', (array) $s['tags']))) . '.</p>'
        . ($params !== '' ? '<p class="mb-1"><strong>Parameters:</strong></p><ul class="mb-1 ps-3">' . $params . '</ul>' : '<p class="mb-1">No parameters.</p>')
        . ($s['risk'] !== 'read_only' ? '<p class="mb-0 text-muted">It starts as a dry run: it prints what it would do and changes nothing until you run it with the dry run switched off.</p>' : '') . '</div></details>';

    return '<tr data-rmm-starter-row="' . rmmH($id) . '"><td class="ps-3"><input class="form-check-input" type="checkbox" name="ids[]" value="' . rmmH($id) . '" id="st-chk-' . rmmH($id) . '" aria-label="Select ' . rmmH($s['name']) . '" data-rmm-starter-pick></td>'
        . '<td><label for="st-chk-' . rmmH($id) . '" class="mb-0 fw-semibold">' . rmmH($s['name']) . '</label><div class="small text-muted">' . rmmH($s['description']) . '</div>' . $more . '</td>'
        . '<td>' . rmmH(rivetRmmStarterPlatformWords((string) $s['platform'])) . '</td><td>' . rivetRmmStarterRiskChip((string) $s['risk']) . ($s['requires_approval'] ? '<div class="mt-1">' . rivetRmmUiPill('warn', 'Needs approval') . '</div>' : '') . '</td>'
        . '<td>' . rivetRmmStarterStatePill((string) $s['state']) . ($s['script_id'] ? '<div class="small mt-1"><a href="/agent/rmm_script_library.php?script_id=' . (int) $s['script_id'] . '">Open in the library</a></div>' : '') . '</td></tr>';
}

/** One input of a policy parameter form. @param array<string,mixed> $p */
function rivetRmmStarterParamInput(string $policy, array $p): string
{
    $name = (string) $p['name'];
    $id = 'st-pp-' . $policy . '-' . $name;
    $field = 'pp[' . $policy . '][' . $name . ']';
    $hint = isset($p['example']) ? (is_array($p['example']) ? implode("\n", array_map('strval', $p['example'])) : (string) $p['example']) : '';
    $def = $p['default'] ?? null;
    $help = (string) ($p['description'] ?? '') . ($def !== null && !is_array($def) ? ' Default: ' . $def . '.' : ($def === null ? ' Leave empty to skip.' : ''));
    if (($p['type'] ?? '') === 'list') {
        $input = '<textarea class="form-control form-control-sm font-monospace" id="' . rmmH($id) . '" name="' . rmmH($field) . '" rows="3" maxlength="2000" spellcheck="false" placeholder="' . rmmH($hint) . '" aria-describedby="' . rmmH($id) . '-h"></textarea>';
        $help .= ' One per line, at most 10.';
    } else {
        $input = '<input class="form-control form-control-sm" id="' . rmmH($id) . '" name="' . rmmH($field) . '" type="' . (($p['type'] ?? '') === 'int' ? 'number' : 'text') . '"' . (($p['type'] ?? '') === 'int' ? ' min="0" max="1000000" step="1" inputmode="numeric"' : ' maxlength="253"')
            . ' placeholder="' . rmmH($def !== null ? (string) $def : $hint) . '" autocomplete="off" aria-describedby="' . rmmH($id) . '-h">';
    }

    return '<div class="col-md-6"><label class="form-label small mb-1" for="' . rmmH($id) . '">' . rmmH((string) ($p['label'] ?? $name)) . '</label>' . $input . '<div class="form-text" id="' . rmmH($id) . '-h">' . rmmH($help) . '</div></div>';
}

/** @param array<string,mixed> $p a catalog policy @param array<string,array<string,mixed>> $sets check sets by id */
function rivetRmmStarterPolicyRow(array $p, array $sets): string
{
    $id = (string) $p['id'];
    $built = [];
    foreach ((array) $p['check_sets'] as $sid) {
        if (isset($sets[$sid])) {
            $built[] = rmmH($sets[$sid]['name']) . ' (' . count((array) $sets[$sid]['checks']) . ' checks)';
        }
    }
    $params = '';
    foreach ((array) $p['params'] as $prm) {
        $params .= rivetRmmStarterParamInput($id, $prm);
    }
    $use = $p['state'] === 'not_installed' ? '' : '<div class="small mt-1 text-muted">' . ($p['enabled'] ? 'Switched on' : 'Switched off') . ', ' . ((int) $p['assignments'] > 0 ? 'assigned to ' . (int) $p['assignments'] . ' scope' . ((int) $p['assignments'] === 1 ? '' : 's') : 'not assigned') . '. <a href="/agent/rmm_policies.php">Open policies</a></div>';

    return '<tr data-rmm-starter-row="' . rmmH($id) . '"><td class="ps-3"><input class="form-check-input" type="checkbox" name="ids[]" value="' . rmmH($id) . '" id="st-chk-' . rmmH($id) . '" aria-label="Select ' . rmmH($p['name']) . '" data-rmm-starter-pick></td>'
        . '<td><label for="st-chk-' . rmmH($id) . '" class="mb-0 fw-semibold">' . rmmH($p['name']) . '</label><div class="small text-muted">' . rmmH($p['description']) . '</div>'
        . ($built !== [] ? '<div class="small">Built from ' . implode(', ', $built) . '.</div>' : '')
        . ($params !== '' ? '<details class="rmm-starter-more mt-1"><summary class="small">Settings for this draft (' . count((array) $p['params']) . ')</summary><div class="row g-2 mt-1">' . $params . '</div></details>' : '') . '</td>'
        . '<td>' . rmmH(rivetRmmStarterPlatformWords((string) $p['platform'])) . '</td><td>' . rivetRmmUiPill('off', 'Draft: off, unassigned') . '</td>'
        . '<td>' . rivetRmmStarterStatePill((string) $p['state']) . $use . '</td></tr>';
}

/** @param array<string,mixed> $vm rivetRmmStarterView() */
function rivetRmmStarterPageHtml(array $vm, array $ctx): string
{
    $csrf = $ctx['csrf'];
    $self = RIVET_RMM_STARTER_PAGE;
    $f = $vm['filters'];
    $o = rivetRmmAutoHeader('Starter pack', 'box-open', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_script_library.php"><i class="fas fa-file-code me-1" aria-hidden="true"></i>Script library</a>'
        . ' <a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_policies.php"><i class="fas fa-sliders-h me-1" aria-hidden="true"></i>Policies</a>',
        'Ready-made scripts, check sets and policy drafts, so a new install is useful on day one. Nothing is installed or run by itself, and no policy reaches a device until you have read it, switched it on and assigned it.');
    if (!$vm['ok']) {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error'] !== '' ? $vm['error'] : 'The starter pack could not be read.') . '</div>';
    }
    if (!$vm['ready']) {
        $o .= '<div class="alert alert-warning" role="alert" data-rmm-starter-blocker><div><i class="fas fa-key me-1" aria-hidden="true"></i><strong>Installing is not possible yet.</strong> ' . rmmH((string) ($vm['blocker'] ?? 'The instance signing key is not available.'))
            . ' <a href="/admin/settings_endpoint_agent.php">Open Administration &gt; Endpoint agent</a>. You can still browse and preview.</div></div>';
    }
    if ($vm['result'] !== null) {
        $o .= rivetRmmStarterResultCard($vm['result'], $csrf);
    }
    $t = $vm['totals'];
    $pk = $vm['pack'];
    $o .= '<div class="row g-3 mb-3">'
        . '<div class="col-6 col-lg-3"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">Scripts installed</div><div class="fs-4 fw-semibold" data-rmm-starter-kpi="scripts">' . (int) $t['installed'] . ' <span class="fs-6 text-muted">of ' . (int) $t['scripts'] . '</span></div></div></div></div>'
        . '<div class="col-6 col-lg-3"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">Policy drafts installed</div><div class="fs-4 fw-semibold" data-rmm-starter-kpi="policies">' . (int) ($t['policies_installed'] ?? 0) . ' <span class="fs-6 text-muted">of ' . (int) $t['policies'] . '</span></div></div></div></div>'
        . '<div class="col-6 col-lg-3"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">Windows / Linux scripts</div><div class="fs-4 fw-semibold">' . (int) ($vm['counts']['windows'] ?? 0) . ' / ' . (int) ($vm['counts']['linux'] ?? 0) . '</div></div></div></div>'
        . '<div class="col-6 col-lg-3"><div class="card card-dark h-100"><div class="card-body py-2"><div class="small text-muted">Pack version</div><div class="fs-4 fw-semibold">' . rmmH((string) ($pk['version'] ?? '')) . '</div></div></div></div></div>';

    // ---- filter (GET)
    $opt = static function (array $choices, string $sel): string {
        $s = '';
        foreach ($choices as $k => $label) {
            $s .= '<option value="' . rmmH((string) $k) . '"' . ((string) $k === $sel ? ' selected' : '') . '>' . rmmH($label) . '</option>';
        }
        return $s;
    };
    $stateChoices = ['' => 'Any state'];
    foreach (rivetRmmStarterStateWords() as $k => [, $w]) {
        $stateChoices[$k] = $w;
    }
    $o .= '<form method="get" action="' . $self . '" id="rmm-starter-filter" class="card card-dark mb-3" role="search" aria-label="Filter the starter pack"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-3"><label class="form-label" for="st_q">Search</label><input class="form-control" type="search" id="st_q" name="q" maxlength="100" value="' . rmmH($f['q']) . '" placeholder="Name or description"></div>'
        . '<div class="col-6 col-md-2"><label class="form-label" for="st_pf">Platform</label><select class="form-select" id="st_pf" name="platform">' . $opt(['' => 'Any', 'windows' => 'Windows', 'linux' => 'Linux'], $f['platform']) . '</select></div>'
        . '<div class="col-6 col-md-2"><label class="form-label" for="st_rk">Risk</label><select class="form-select" id="st_rk" name="risk">' . $opt(['' => 'Any', 'read_only' => 'Read only', 'changes_system' => 'Changes the system', 'disruptive' => 'Disruptive'], $f['risk']) . '</select></div>'
        . '<div class="col-6 col-md-2"><label class="form-label" for="st_tag">Tag</label><input class="form-control" type="text" id="st_tag" name="tag" maxlength="30" list="st_tags" value="' . rmmH($f['tag']) . '"><datalist id="st_tags">'
        . implode('', array_map(static fn (string $t): string => '<option value="' . rmmH($t) . '">', $vm['tags'])) . '</datalist></div>'
        . '<div class="col-6 col-md-2"><label class="form-label" for="st_state">State</label><select class="form-select" id="st_state" name="state">' . $opt($stateChoices, $f['state']) . '</select></div>'
        . '<div class="col-12 col-md-1"><button class="btn btn-outline-primary w-100" type="submit"><i class="fas fa-search me-1" aria-hidden="true"></i><span class="d-md-none d-lg-inline">Filter</span></button></div></div>'
        . ($f !== ['q' => '', 'platform' => '', 'risk' => '', 'tag' => '', 'state' => ''] ? '<p class="small mb-0 mt-2">Showing ' . (int) $vm['shown']['scripts'] . ' scripts and ' . (int) $vm['shown']['policies'] . ' policy drafts. <a href="' . $self . '">Clear the filter</a></p>' : '') . '</div></form>';

    // ---- the one selection form
    $sets = [];
    foreach ($vm['check_sets'] as $cs) {
        $sets[(string) $cs['id']] = $cs;
    }
    $scriptRows = '';
    foreach ($vm['scripts'] as $s) {
        $scriptRows .= rivetRmmStarterScriptRow($s);
    }
    $policyRows = '';
    foreach ($vm['policies'] as $p) {
        $policyRows .= rivetRmmStarterPolicyRow($p, $sets);
    }
    $head = static fn (string $what): string => '<thead><tr><th scope="col" class="ps-3" style="width:2.5rem"><input class="form-check-input" type="checkbox" aria-label="Select every ' . $what . ' shown" data-rmm-starter-all></th>'
        . '<th scope="col">Name</th><th scope="col">Platform</th><th scope="col">' . ($what === 'script' ? 'Risk' : 'Kind') . '</th><th scope="col">State</th></tr></thead>';
    $o .= '<form method="post" action="/agent/post/' . RIVET_RMM_STARTER_HANDLER . '.php" id="rmm-starter-form" data-rmm-starter-form>' . rivetRmmAutoHidden($csrf, $self);
    $o .= rivetRmmUiCard('Scripts', 'file-code', $vm['scripts'] === [] ? rivetRmmUiEmpty('fas fa-file-code', 'No script matches', 'Try another search or clear the filter.')
        : '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 rmm-starter-table" data-rmm-starter-table="scripts"><caption class="visually-hidden">Starter scripts</caption>' . $head('script') . '<tbody>' . $scriptRows . '</tbody></table></div>',
        '', '<span class="small text-muted">' . (int) $vm['shown']['scripts'] . ' shown</span>', true, 'starter-scripts');
    $o .= rivetRmmUiCard('Policy drafts', 'sliders-h', $vm['policies'] === [] ? rivetRmmUiEmpty('fas fa-sliders-h', 'No policy draft matches', 'Try another search or clear the filter.')
        : '<p class="small text-muted px-3 pt-3 mb-2">A draft is created switched off and assigned to nothing. Open it under Policies to read it, then switch it on and assign it. The settings under each draft decide the time server, the services to watch and so on; empty keeps the default.</p>'
        . '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 rmm-starter-table" data-rmm-starter-table="policies"><caption class="visually-hidden">Starter policy drafts</caption>' . $head('policy') . '<tbody>' . $policyRows . '</tbody></table></div>',
        '', '<span class="small text-muted">' . (int) $vm['shown']['policies'] . ' shown</span>', true, 'starter-policies');
    $o .= '<div class="card card-dark mb-3 rmm-starter-actions" id="starter-actions"><div class="card-body d-flex flex-wrap align-items-center gap-2">'
        . '<span class="me-auto" role="status" aria-live="polite" data-rmm-starter-selected>0 selected</span>'
        . '<button type="submit" name="action" value="plan" class="btn btn-outline-primary"><i class="fas fa-search me-1" aria-hidden="true"></i>Preview</button>'
        . '<button type="submit" name="action" value="install" class="btn btn-primary"' . ($vm['ready'] ? '' : ' disabled aria-disabled="true" title="Generate the instance signing key first"') . '><i class="fas fa-download me-1" aria-hidden="true"></i>Install selected</button>'
        . '<button type="submit" name="action" value="uninstall_preview" class="btn btn-outline-danger"><i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Uninstall selected</button>'
        . '<span class="w-100 small text-muted">Nothing is chosen for you: tick what you want first. Uninstall shows a preview, and only touches items you have not edited.</span></div></div></form>';

    // ---- check sets (information)
    $csRows = '';
    foreach ($vm['check_sets'] as $cs) {
        $checks = '';
        foreach ((array) $cs['checks'] as $c) {
            $checks .= '<li>' . rmmH((string) $c['label']) . ' <span class="text-muted">(' . rmmH((string) $c['type']) . ', every ' . rmmH(rivetRmmScrSecondsWords((int) $c['interval_s'])) . ($c['when'] ? ', only when ' . rmmH((string) $c['when']) . ' is set' : '') . ')</span></li>';
        }
        $csRows .= '<details class="mb-2"><summary class="fw-semibold">' . rmmH((string) $cs['name']) . ' <span class="small text-muted fw-normal">' . rmmH(rivetRmmStarterPlatformWords((string) $cs['platform'])) . ', ' . count((array) $cs['checks']) . ' checks</span></summary>'
            . '<p class="small mb-1">' . rmmH((string) $cs['description']) . '</p><ul class="small mb-0 ps-3">' . $checks . '</ul></details>';
    }
    if ($csRows !== '') {
        $o .= rivetRmmUiCard('Check template sets', 'clipboard-check', '<p class="small text-muted">The checks the policy drafts are built from. They are installed as part of a draft, not on their own.</p>' . $csRows, '', '', false, 'starter-checksets');
    }

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}
