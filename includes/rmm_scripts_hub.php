<?php

/*
 * The single Scripts area (RivetMSP 26.10.22). One menu entry, one page (agent/rmm_scripts.php) with tabs:
 *   Library                  the RivetCore script library (signed, versioned scripts: the Starter pack and your own); the default tab
 *   Synced from <provider>   the older list of scripts kept in the rmm_scripts table (created by hand, or synced from a vendor RMM such as Tactical RMM or Level)
 * Older URLs keep working: agent/rmm_script_library.php redirects to the Library tab with its query string, agent/rmm_scripts.php?category=... lands on the synced tab, and
 * agent/rmm_script_run.php (one run of a synced script) is unchanged.
 *
 * This file also holds the one-time "Import into the library" action for the scripts that were created by hand (rows without a vendor script id): it creates library scripts through
 * Core's technician API as the signed-in user (so Core's checks and audit apply), is idempotent (a script already in the library with the same name and the same text is skipped) and
 * never changes or removes the older rows, which then show "Imported".
 */

const RIVET_SCRIPTS_HUB_URL = '/agent/rmm_scripts.php';
const RIVET_SCRIPTS_IMPORT_NOTE = 'imported from legacy Scripts';
const RIVET_SCRIPTS_IMPORT_RISKS = ['read_only' => 'Read only', 'changes_system' => 'Changes the system', 'disruptive' => 'Disruptive'];

/**
 * The name of the vendor RMM the synced scripts come from: the default integration, else the first enabled integration that can hand out scripts. Null when there is none.
 *
 * @return array{id:int,name:string}|null
 */
function rivetScriptsHubProvider(\mysqli $mysqli): ?array
{
    $default = (int) ($GLOBALS['config_rmm_default_integration_id'] ?? 0);
    $names = ['tactical_rmm' => 'Tactical RMM', 'level' => 'Level.io'];
    $r = mysqli_query($mysqli, "SELECT id, name, type FROM rmm_integrations WHERE enabled = 1 AND type IN ('tactical_rmm', 'level') ORDER BY (id = " . $default . ') DESC, id ASC LIMIT 1');
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row) {
        return null;
    }

    return ['id' => (int) $row['id'], 'name' => trim((string) $row['name']) !== '' ? trim((string) $row['name']) : ($names[$row['type']] ?? 'RMM')];
}

/**
 * Which tabs this person sees, and which is the default. The Library needs the RivetCore RMM module (its `scripts` switch, when off, is explained inside the tab). The synced tab shows
 * when there are older rows, or the vendor RMM integration is on and can provide scripts. Reading it needs the older `module_rmm` grant, like the page.
 *
 * @return array{tabs:array<string,string>,default:string,provider:?array{id:int,name:string},rows:int}
 */
function rivetScriptsHubTabs(\mysqli $mysqli, int $userId): array
{
    $tabs = [];
    $core = !empty($GLOBALS['config_core_rmm_enabled']) && function_exists('rivetRmmEnabled') && rivetRmmEnabled($mysqli);
    $rows = (int) (mysqli_fetch_row(mysqli_query($mysqli, 'SELECT COUNT(*) FROM rmm_scripts'))[0] ?? 0);
    $provider = !empty($GLOBALS['config_module_enable_rmm']) ? rivetScriptsHubProvider($mysqli) : null;
    if ($core) {
        $tabs['library'] = 'Library';
    }
    if ($rows > 0 || $provider !== null) {
        $tabs['synced'] = $provider !== null ? 'Synced from ' . $provider['name'] : 'Older scripts';
    }

    return ['tabs' => $tabs, 'default' => array_key_first($tabs) ?? 'library', 'provider' => $provider, 'rows' => $rows];
}

/** The tab to show: the one asked for when it exists; `category`/`q` (an older bookmark of the list) mean the synced tab; otherwise the default. @param array<string,string> $tabs */
function rivetScriptsHubPick(array $hub, array $get): string
{
    $want = is_string($get['tab'] ?? null) ? $get['tab'] : '';
    if (isset($hub['tabs'][$want])) {
        return $want;
    }
    if ($want === '' && (isset($get['category']) || isset($get['q'])) && isset($hub['tabs']['synced']) && !isset($get['script_id']) && !isset($get['new'])) {
        return 'synced';
    }

    return $hub['default'];
}

/** The tab strip (links to the same page, so each tab is a real address). Nothing when there is only one tab. @param array<string,string> $tabs */
function rivetScriptsHubStrip(array $tabs, string $active): string
{
    if (count($tabs) < 2) {
        return '';
    }
    $icons = ['library' => 'file-code', 'synced' => 'cloud-download-alt'];
    $o = '<nav aria-label="Scripts sections" class="mb-3"><ul class="nav nav-tabs" id="scripts-tabs">';
    foreach ($tabs as $key => $label) {
        $o .= '<li class="nav-item"><a class="nav-link' . ($key === $active ? ' active' : '') . '" href="' . RIVET_SCRIPTS_HUB_URL . '?tab=' . $key . '"' . ($key === $active ? ' aria-current="page"' : '') . ' id="scripts-tab-' . $key . '">'
            . '<i class="fas fa-' . ($icons[$key] ?? 'code') . ' me-1" aria-hidden="true"></i>' . nullable_htmlentities($label) . '</a></li>';
    }

    return $o . '</ul></nav>';
}

// ------------------------------------------------------------------ the one-time import

/** The text Core would store: line ends as \n (what the library editor posts). */
function rivetScriptsImportText(string $body): string
{
    return str_replace("\r\n", "\n", $body);
}

/** The hash of an older script's text as the library stores it. */
function rivetScriptsImportHash(string $body): string
{
    return hash('sha256', rivetScriptsImportText($body));
}

/**
 * The older rows that were created by hand (no vendor script id), each with its import state: `imported` (a library script with that name, or "<name> (legacy)", holds a version with the
 * same text), `blocked` (a different script already has the name, so it would be imported under the second name), or `new`. Rows that cannot be imported (CMD scripts, no text) say why.
 *
 * @return list<array<string,mixed>>
 */
function rivetScriptsLegacyImportables(\mysqli $mysqli): array
{
    $out = [];
    $q = mysqli_query($mysqli, 'SELECT id, name, category, description, script_type, script_body, enabled FROM rmm_scripts WHERE (tactical_script_id IS NULL OR tactical_script_id = 0) ORDER BY category, name LIMIT 500');
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $body = (string) $r['script_body'];
        $why = '';
        if (trim($body) === '') {
            $why = 'it has no stored text';
        } elseif (!in_array($r['script_type'], ['powershell', 'bash', 'python'], true)) {
            $why = 'the library runs PowerShell, Bash and Python scripts, not ' . ($r['script_type'] === 'cmd' ? 'CMD / Batch' : (string) $r['script_type']);
        }
        $hash = rivetScriptsImportHash($body);
        $state = 'new';
        $target = (string) $r['name'];
        if ($why === '') {
            foreach ([(string) $r['name'], (string) $r['name'] . ' (legacy)'] as $i => $name) {
                $ex = mysqli_query($mysqli, "SELECT s.script_id, EXISTS(SELECT 1 FROM rmm_script_versions v WHERE v.script_id = s.script_id AND v.body_sha256 = '" . mysqli_real_escape_string($mysqli, $hash)
                    . "') AS same FROM rmm_scripts_v2 s WHERE s.name = '" . mysqli_real_escape_string($mysqli, $name) . "' LIMIT 1");
                $row = $ex ? mysqli_fetch_assoc($ex) : null;
                if ($row && (int) $row['same'] === 1) {
                    $state = 'imported';
                    $target = $name;
                    break;
                }
                if (!$row) {
                    $target = $name;
                    $state = 'new';
                    break;
                }
                $state = 'blocked';   // the name is taken by a different text: try the second name, then give up
                $target = $name;
            }
        }
        $out[] = $r + ['hash' => $hash, 'state' => $why !== '' ? 'skipped' : $state, 'why' => $why, 'target' => $target];
    }

    return $out;
}

/** Ids of older rows that are already in the library (for the "Imported" mark of the list). @return array<int,string> row id => library script name */
function rivetScriptsLegacyImportedMap(\mysqli $mysqli): array
{
    $map = [];
    if (!function_exists('rivetRmmEnabled') || !rivetRmmEnabled($mysqli)) {
        return $map;
    }
    foreach (rivetScriptsLegacyImportables($mysqli) as $r) {
        if ($r['state'] === 'imported') {
            $map[(int) $r['id']] = (string) $r['target'];
        }
    }

    return $map;
}

/**
 * Import the hand-made older scripts into the library. `$risk` is the class every imported script gets (the administrator's choice; read only, changes the system or disruptive):
 * it becomes the `risk:` tag, and a disruptive script is marked destructive and needs approval (the starter pack's defaults). Each script is created through the technician API as the
 * person (Core's ability checks, size limits and audit apply). Never throws.
 *
 * @return array{imported:int,skipped:int,failed:int,lines:list<string>}
 */
function rivetScriptsLegacyImport(\mysqli $mysqli, int $userId, string $userName, string $risk): array
{
    $res = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'lines' => []];
    if (!isset(RIVET_SCRIPTS_IMPORT_RISKS[$risk])) {
        $risk = 'changes_system';
    }
    foreach (rivetScriptsLegacyImportables($mysqli) as $r) {
        $label = (string) $r['name'];
        if ($r['state'] === 'imported') {
            $res['skipped']++;
            continue;
        }
        if ($r['state'] === 'skipped') {
            $res['skipped']++;
            $res['lines'][] = $label . ': skipped, ' . $r['why'] . '.';
            continue;
        }
        if ($r['state'] === 'blocked') {
            $res['failed']++;
            $res['lines'][] = $label . ': not imported, a different library script already has that name and the name "' . $label . ' (legacy)".';
            continue;
        }
        $cat = trim((string) $r['category']);
        $tags = ['imported', 'risk:' . $risk];
        if ($cat !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.:-]{0,29}$/', $cat) === 1) {
            $tags[] = $cat;
        }
        $body = [
            'name' => mb_substr((string) $r['target'], 0, 200), 'description' => mb_substr(trim((string) $r['description']), 0, 500), 'language' => (string) $r['script_type'],
            'body' => rivetScriptsImportText((string) $r['script_body']), 'tags' => $tags, 'note' => RIVET_SCRIPTS_IMPORT_NOTE,
            'requires_approval' => $risk === 'disruptive', 'destructive' => $risk === 'disruptive',
        ];
        $api = rivetRmmAutoApi($mysqli, $userId, $userName, 'POST', ['scripts'], [], $body);
        if ($api['ok']) {
            $res['imported']++;
        } else {
            $res['failed']++;
            $res['lines'][] = $label . ': not imported, ' . ($api['error'] !== '' ? $api['error'] : 'Core refused it') . '.';
        }
    }

    return $res;
}

/** The card above the synced list: what the import does, the class choice and the button; or why nothing can be imported. @param list<array<string,mixed>> $rows */
function rivetScriptsLegacyImportCard(array $rows, bool $canImport, string $csrf): string
{
    $new = count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'new' || $r['state'] === 'blocked'));
    $done = count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'imported'));
    if ($rows === []) {
        return '';
    }
    $body = '<p class="mb-2">' . (int) count($rows) . ' script' . (count($rows) === 1 ? ' was' : 's were') . ' written here by hand (not synced from a vendor RMM). The Library keeps signed, versioned scripts that run on the RivetCore agent, '
        . 'on Windows and Linux, with parameters, approvals and schedules. ' . ($done > 0 ? '<b>' . $done . '</b> already ' . ($done === 1 ? 'is' : 'are') . ' in the Library. ' : '') . 'Importing copies them there; these rows stay as they are.</p>';
    if (!$canImport) {
        return '<div class="card card-dark mb-3" id="scripts-import"><div class="card-body">' . $body . '<p class="small text-muted mb-0">Only an administrator with write access to scripts can import them.</p></div></div>';
    }
    if ($new === 0) {
        return '<div class="card card-dark mb-3" id="scripts-import"><div class="card-body">' . $body . '<p class="small text-muted mb-0"><i class="fas fa-check me-1" aria-hidden="true"></i>Nothing is left to import.</p></div></div>';
    }
    $opts = '';
    foreach (RIVET_SCRIPTS_IMPORT_RISKS as $k => $l) {
        $opts .= '<option value="' . $k . '"' . ($k === 'changes_system' ? ' selected' : '') . '>' . nullable_htmlentities($l) . '</option>';
    }

    return '<div class="card card-dark mb-3" id="scripts-import"><div class="card-body">' . $body
        . '<form method="post" class="row g-2 align-items-end"><input type="hidden" name="csrf_token" value="' . nullable_htmlentities($csrf) . '"><input type="hidden" name="tab" value="synced">'
        . '<div class="col-md-5"><label class="form-label" for="import_risk">Risk class of the imported scripts</label><select class="form-select" id="import_risk" name="import_risk" aria-describedby="import_risk_help">' . $opts . '</select>'
        . '<div class="form-text" id="import_risk_help">Disruptive scripts are marked destructive and need a second person to approve each run. You can change a script afterwards in the Library.</div></div>'
        . '<div class="col-md-4"><button type="submit" name="import_legacy" value="1" class="btn btn-primary"><i class="fas fa-file-import me-1" aria-hidden="true"></i>Import ' . $new . ' into the Library</button></div></form></div></div>';
}
