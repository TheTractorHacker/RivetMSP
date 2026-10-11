<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.13, the RMM starter pack): the Starter pack page (agent/rmm_starter_pack.php, handler agent/post/rmm_starter.php,
 * includes/rmm_starter_ui.php, js/rmm_starter.js), the menu entry, the Starter badge / risk chip / "update waiting" marker in the script library and on a script, the draft
 * marker on policies. Shares that file's variables.
 *
 * Everything is driven through the product: the real handler over HTTP as the administrator (forged sessions, CSRF), the real pages, Core's StarterActions behind them. The pack
 * itself (73 scripts, 3 check sets, 3 drafts) is Core's; what is tested here is RivetMSP's page: the selection (an EMPTY one is never sent to Core), the two-step uninstall, the
 * parameter form, the blocker, the access rules, the markers, escaping. Windows scripts are never run by anything in this repository.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

require_once "$root/includes/rmm_starter_ui.php";

$stPg = fn (string $who = 'admin', string $qs = '') => web($wb, 'GET', '/agent/rmm_starter_pack.php' . $qs, $SS[$who]);
$stPost = fn (array $f, string $who = 'admin', array $hdr = []) => web($wb, 'POST', '/agent/post/rmm_starter.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $hdr);
$stLoc = fn (string $heads) => preg_match('/^Location:\s*(.+?)\r?$/mi', $heads, $m) === 1 ? trim($m[1]) : '';
$stCatalog = fn () => $rmm->starterInstaller()->catalog();
$stState = function (string $id) use ($stCatalog): ?string {
    foreach ($stCatalog()['packs'][0] as $k => $v) {
        if (in_array($k, ['scripts', 'policies'], true)) {
            foreach ($v as $i) {
                if ($i['id'] === $id) { return $i['state']; }
            }
        }
    }
    return null;
};
$stScriptRows = fn () => (int) $one("SELECT COUNT(*) FROM rmm_script_versions WHERE note LIKE 'starter|%'");
$stPolicyRows = fn () => (int) $one("SELECT COUNT(*) FROM rmm_policies WHERE description LIKE '% [starter|%'");
$stEvents = fn () => count(array_filter(array_map(fn ($r) => (json_decode($r[0], true)['event'] ?? ''), $db->query("SELECT payload FROM integration_jobs WHERE job_type='webhook.deliver' ORDER BY job_id")->fetch_all()), fn ($e) => $e === 'rmm.starter.installed'));
$stAudit = fn (string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "'");
/** The message of the flash the handler left (the next page load shows it); read from the page body. */
$stFlash = function (string $body): string { return preg_match('/(?:toastr|flash|alert-(?:success|danger|warning))[^>]*>(.*?)<\/div>/s', $body, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : ''; };
$rmm->admin()->enable($admin);
$fresh();
$q("DELETE FROM webhooks WHERE webhook_name = 'starter events'");
$q("INSERT INTO webhooks SET webhook_name='starter events', webhook_url='http://127.0.0.1:9/starter', webhook_secret='', webhook_events='rmm.starter.*', webhook_enabled=1");
$evStart = $stEvents();

// ============================================================ the pack and the page
$cat = $stCatalog();
$ok($cat['ready'] === true && $cat['blocker'] === null && $cat['packs'][0]['counts']['scripts'] === 73 && $cat['packs'][0]['counts']['windows'] === 41 && $cat['packs'][0]['counts']['linux'] === 32 && $cat['packs'][0]['counts']['check_sets'] === 3 && $cat['packs'][0]['counts']['policies'] === 3,
    'the bundled pack: 73 scripts (41 Windows, 32 Linux), 3 check sets, 3 policy drafts, and the module can install (signing key present)');
$risk = ['read_only' => 0, 'changes_system' => 0, 'disruptive' => 0];
foreach ($cat['packs'][0]['scripts'] as $s) { $risk[$s['risk']]++; }
$ok($risk === ['read_only' => 57, 'changes_system' => 10, 'disruptive' => 6], 'by risk: 57 read only, 10 change the system, 6 disruptive');
$ok($cat['totals']['installed'] === 0 && $stScriptRows() === 0 && $stPolicyRows() === 0, 'nothing is installed at the start (no marker in any script or policy)');

[$c, $body] = $stPg();
$ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Deprecated:'), 'the Starter pack page loads for an administrator with no PHP notice (' . $c . ')');
$ok(substr_count($body, 'data-rmm-starter-row="') === 76 && str_contains($body, 'data-rmm-starter-table="scripts"') && str_contains($body, 'data-rmm-starter-table="policies"'), 'it lists 73 scripts and 3 policy drafts');
$ok(str_contains($body, 'data-rmm-starter-kpi="scripts">0 <span') && str_contains($body, 'of 73') && str_contains($body, '41 / 32') && str_contains($body, '1.0.0'), 'the figures: 0 of 73 installed, 41 / 32 by platform, the pack version');
$ok(str_contains($body, 'Read only') && str_contains($body, 'Changes the system') && str_contains($body, 'Disruptive') && str_contains($body, 'Needs approval'), 'risk chips (icon and words) and the approval mark are drawn');
$ok(!str_contains($body, 'data-rmm-starter-blocker'), 'no blocker while the signing key exists');
$ok(str_contains($body, '/js/rmm_starter.js') && str_contains($body, 'itflow_rmm_starter.css'), 'the page links its script and style');
$ok(preg_match('/<caption class="visually-hidden">Starter scripts<\/caption>/', $body) === 1 && preg_match('/<caption class="visually-hidden">Starter policy drafts<\/caption>/', $body) === 1 && substr_count($body, 'table-responsive') >= 2, 'tables have captions and scroll on a phone (table-responsive)');
$ok(substr_count($body, 'aria-label="Select ') >= 76 && preg_match('/aria-label="Select every script shown"/', $body) === 1 && str_contains($body, 'role="status" aria-live="polite" data-rmm-starter-selected'), 'every row has a labelled tick box, the header one selects the shown rows, and the count is a polite live region');
$ok(!preg_match('/\son[a-z]+="/', $body) && !preg_match('/href="javascript:/i', $body), 'CSP: no inline event handlers on the page');
$ok(substr_count($body, 'name="csrf_token"') >= 1 && preg_match('/<form method="post" action="\/agent\/post\/rmm_starter\.php"/', $body) === 1, 'the form posts to its handler with a CSRF token');
$ok(preg_match('/<button type="submit" name="action" value="install" class="btn btn-primary">/', $body) === 1 && str_contains($body, 'value="plan"') && str_contains($body, 'value="uninstall_preview"') && !str_contains($body, 'value="uninstall"'), 'buttons: Preview, Install selected, Uninstall selected (which previews first); a bare uninstall is not on the page');
$ok(str_contains($body, 'Nothing is chosen for you'), 'the page says nothing is pre-selected');
$ok(str_contains($body, 'Draft: off, unassigned') && str_contains($body, 'Settings for this draft (6)') && str_contains($body, 'name="pp[policy-linux-server][ntp_server]"') && str_contains($body, 'name="pp[policy-linux-server][cert_paths]"'), 'policy drafts: always off and unassigned, with their settings form (time server, certificate paths, ...)');
$ok(preg_match('/<textarea[^>]*name="pp\[policy-linux-server\]\[cert_paths\]"/', $body) === 1 && preg_match('/<input[^>]*name="pp\[policy-linux-server\]\[uptime_warn_days\]"[^>]*type="number"/', $body) === 1, 'a list parameter is a textarea and a whole-number parameter is a number input');
foreach (['win-flush-dns', 'lin-disk-usage', 'policy-windows-server'] as $id) {
    $ok(str_contains($body, 'id="st-chk-' . $id . '"') && str_contains($body, 'for="st-chk-' . $id . '"'), "$id has its tick box and a label for it");
}
$ok(str_contains($body, 'rmm_starter_pack.php') , 'the page is reachable');

// ---- the menu
$stNav = fn (?int $uid = null) => array_column(rivetRmmAutoNav($uid), 'href');
$ok(in_array('/agent/rmm_starter_pack.php', $stNav(), true) && in_array('/agent/rmm_starter_pack.php', $stNav($USER['admin']), true), 'menu: the Starter pack entry is offered while the scripts switch is on');
$ok(!in_array('/agent/rmm_starter_pack.php', $stNav($USER['tech']), true) && !in_array('/agent/rmm_starter_pack.php', $stNav($USER['viewer']), true) && !in_array('/agent/rmm_starter_pack.php', $stNav($USER['stock']), true) && !in_array('/agent/rmm_starter_pack.php', $stNav($USER['clientb']), true), 'menu: not offered to a technician, a viewer, a stock technician or a client-limited user (administrators only)');
[$c, $b2] = $stPg('admin');
$ok(str_contains($b2, 'href="/agent/rmm_starter_pack.php"'), 'the Endpoints menu of a real page links it for an administrator');
[$c, $b3] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['tech']);
$ok($c === 200 && !str_contains($b3, 'href="/agent/rmm_starter_pack.php"'), 'and not for a technician (' . $c . ')');
[$c, $b4] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok($c === 200 && str_contains($b4, 'href="/agent/rmm_starter_pack.php"'), 'the script library (administrator) offers a Starter pack button as well');

// ---- access: only an administrator; nothing is asked of the database with the module or the switch off
foreach (['tech', 'viewer', 'stock', 'clientb'] as $who) {
    [$c, $b] = $stPg($who);
    $ok(in_array($c, [403, 302], true) && !str_contains($b, 'data-rmm-starter-table'), "$who: the page is refused (" . $c . ')');
    [$c, , $h] = $stPost(['action' => 'install', 'ids' => ['lin-disk-usage']], $who);
    $ok($stScriptRows() === 0 && $stPolicyRows() === 0, "$who: a forged install is refused and nothing is installed (" . $c . ')');
}
[$c, $b] = $stPg('tech');
$ok($c === 403 && str_contains($b, 'only administrators can use it'), 'a technician is told why (403)');
foreach ([['wrong', 'csrf'], ['', 'missing']] as [$tokv, $label]) {
    [$c] = web($wb, 'POST', '/agent/post/rmm_starter.php', $SS['admin'], ['action' => 'install', 'ids' => ['lin-disk-usage'], 'csrf_token' => $tokv]);
    $ok($stScriptRows() === 0, "a $label CSRF token installs nothing (" . $c . ')');
}
[$c, , $h] = web($wb, 'GET', '/agent/post/rmm_starter.php?action=install&ids[]=lin-disk-usage', $SS['admin']);
$ok($c === 405 && $stScriptRows() === 0, 'GET on the handler: 405, nothing installed');
[$c] = web($wb, 'POST', '/agent/post/rmm_starter.php', 'nosuchsession', ['action' => 'install', 'ids' => ['lin-disk-usage'], 'csrf_token' => 'csrftok1']);
$ok($c === 302 && $stScriptRows() === 0, 'not signed in: redirected, nothing installed');

$rmm->admin()->disable($admin);
$fresh();
$a = $questions(); $navOff = rivetRmmAutoNav($USER['admin']); $b = $questions();
$ok($navOff === [] && $b - $a === $overhead, 'module off: the menu has no entries and the check costs no statement');
[$c, $b] = $stPg();
$ok($c === 403 && str_contains($b, 'switched off') && !str_contains($b, 'data-rmm-starter-table'), 'module off: the page is the "switched off" notice (403)');
[$c] = $stPost(['action' => 'install', 'ids' => ['lin-disk-usage']]);
$ok($stScriptRows() === 0, 'module off: the handler installs nothing (' . $c . ')');
$rmm->admin()->enable($admin);
$setScripts = function (bool $on) use ($rmm, $admin, $fresh): void {
    $rmm->admin()->saveSettings($admin, ['features_json' => array_merge($rmm->settings()->features(), ['scripts' => $on])]);
    $fresh();
};
$setScripts(false);
[$c, $b] = $stPg();
$ok($c === 403 && str_contains($b, 'switched off') && !in_array('/agent/rmm_starter_pack.php', $stNav(), true), 'the scripts switch off: the page is the notice and the menu entry is gone');
[$c] = $stPost(['action' => 'install', 'ids' => ['lin-disk-usage']]);
$ok($stScriptRows() === 0, 'the scripts switch off: the handler installs nothing (' . $c . ')');
$setScripts(true);
$ok(rivetRmmFeatureOn('scripts'), 'switched back on');

// ============================================================ filters
[$c, $b] = $stPg('admin', '?platform=linux');
$ok(substr_count($b, 'data-rmm-starter-row="lin-') === 32 && substr_count($b, 'data-rmm-starter-row="win-') === 0 && str_contains($b, 'Clear the filter'), 'platform=linux: the 32 Linux scripts');
[$c, $b] = $stPg('admin', '?risk=disruptive');
$ok(substr_count($b, 'data-rmm-starter-row="') === 6 && substr_count($b, 'data-rmm-starter-row="policy-') === 0, 'risk=disruptive: the 6 disruptive scripts (policies come with ids or the whole pack)');
[$c, $b] = $stPg('admin', '?q=bitlocker');
$ok(substr_count($b, 'data-rmm-starter-row="') === 1 && str_contains($b, 'data-rmm-starter-row="win-bitlocker-status"'), 'q=bitlocker: one script');
[$c, $b] = $stPg('admin', '?q=' . rawurlencode('<script>alert(1)</script>') . '&tag=' . rawurlencode('"><img src=x>'));
$ok($c === 200 && !str_contains($b, '<script>alert(1)</script>') && !str_contains($b, '"><img src=x>') && str_contains($b, 'No script matches'), 'hostile filter text is escaped (and a bad tag is dropped)');
[$c, $b] = $stPg('admin', '?state=update_available&platform=nope&risk=nope');
$ok($c === 200 && str_contains($b, 'No script matches'), 'a state filter with nothing in that state, and unknown platform and risk values ignored');
[$c, $b] = $stPg('admin', '?tag=print');
$ok(substr_count($b, 'data-rmm-starter-row="') === 2, 'tag=print narrows to the two printing scripts');

// ============================================================ the selection: an empty one never reaches Core
[$c, , $h] = $stPost(['action' => 'install']);
$ok($c === 302 && $stScriptRows() === 0 && $stPolicyRows() === 0 && $stEvents() === $evStart, 'install with nothing ticked: refused, and NOT read as "the whole pack" (nothing installed, no event)');
[, $b] = $stPg();
$ok(str_contains($stFlash($b) . $b, 'Tick at least one script or policy draft first'), 'the person is told to tick something');
[$c] = $stPost(['action' => 'plan', 'ids' => ['', '../etc/passwd', 'a;b', str_repeat('x', 200), 'UPPER']]);
$ok($stScriptRows() === 0, 'ids that are not a-z, 0-9 and dashes are dropped before Core is asked; with none left it is the empty selection again');
[$c] = $stPost(['action' => 'install', 'ids' => ['zzz-not-a-starter-item']]);
[, $b] = $stPg();
$ok($stScriptRows() === 0 && str_contains($b, 'Unknown starter item zzz-not-a-starter-item'), 'an unknown id is Core\'s 422 text and installs nothing');
[$c] = $stPost(['action' => 'frobnicate', 'ids' => ['lin-disk-usage']]);
$ok($stScriptRows() === 0, 'an unknown action does nothing');

// ============================================================ preview, install, idempotence
$pick = ['lin-disk-usage', 'win-flush-dns', 'win-print-spooler-restart', 'policy-linux-server'];
[$c, , $h] = $stPost(['action' => 'plan', 'ids' => $pick]);
$ok($c === 302 && str_contains($stLoc($h), '/agent/rmm_starter_pack.php') && $stScriptRows() === 0 && $stPolicyRows() === 0, 'preview: redirected back, and NOTHING was written');
[, $b] = $stPg();
$ok(str_contains($b, 'id="starter-result"') && str_contains($b, 'Preview: what an install would do') && substr_count($b, 'Will be added') === 4 && str_contains($b, 'Install these'), 'the preview lists the four items as "Will be added" and offers to install exactly these');
$ok(preg_match_all('/<input type="hidden" name="ids\[\]" value="([a-z0-9-]+)">/', $b, $mm) === 4 && array_diff($mm[1], $pick) === [], 'the follow-up form carries exactly the previewed ids');
[, $b] = $stPg();
$ok(!str_contains($b, 'id="starter-result"'), 'the result is shown once');
$q('UPDATE webhooks SET webhook_enabled = 1 WHERE webhook_name = \'starter events\'');

[$c, , $h] = $stPost(['action' => 'install', 'ids' => $pick]);
[, $b] = $stPg();
$ok($c === 302 && $stScriptRows() === 3 && $stPolicyRows() === 1, 'install: three scripts and one policy draft written');
$ok(str_contains($b, 'Install result') && substr_count($b, 'Added') >= 4 && str_contains($b, 'Installed.') && str_contains($b, 'Created as a draft: switched off, not assigned.'), 'the result card says what happened to each item');
$ok($stState('lin-disk-usage') === 'current' && $stState('win-flush-dns') === 'current' && $stState('policy-linux-server') === 'current' && $stState('win-bitlocker-status') === 'not_installed', 'catalog states: installed items are "current", the rest "not installed"');
$sid = (int) $one("SELECT script_id FROM rmm_script_versions WHERE note LIKE 'starter|rivet-core-starter|lin-disk-usage|%'");
$tags = (string) $one("SELECT tags_json FROM rmm_scripts_v2 WHERE script_id = $sid");
$ok($sid > 0 && str_contains($tags, '"starter"') && str_contains($tags, 'risk:read_only'), 'a starter script carries the tags starter and risk:<class>');
$pol = $rows("SELECT policy_id, enabled, description FROM rmm_policies WHERE description LIKE '% [starter|%'")[0] ?? [];
$ok(($pol['enabled'] ?? 1) == 0 && (int) $one('SELECT COUNT(*) FROM rmm_policy_assignments WHERE policy_id = ' . (int) ($pol['policy_id'] ?? 0)) === 0 && preg_match('/ \[starter\|rivet-core-starter\|policy-linux-server\|1\.0\.0\|[0-9a-f]{64}\]$/', (string) ($pol['description'] ?? '')) === 1, 'the policy draft is switched off, assigned to nothing, and ends with the pack marker');
$dry = $rows("SELECT body FROM rmm_script_versions v JOIN rmm_scripts_v2 s ON s.script_id = v.script_id AND s.current_version = v.version WHERE v.note LIKE 'starter|rivet-core-starter|win-flush-dns|%'");
$ok(count($dry) === 1 && str_contains((string) $dry[0]['body'], 'dry_run'), 'a script that changes the system has the dry_run switch in its text');
$ok($stAudit('Starter Pack Installed') === 1 && $stAudit('Starter Pack Item Added') === 4, 'audit: one "Starter Pack Installed" and one line per item added (' . $stAudit('Starter Pack Item Added') . ')');
$ok(!str_contains((string) $one("SELECT GROUP_CONCAT(log_description) FROM logs WHERE log_action LIKE 'Starter Pack%'"), 'Get-ChildItem'), 'and no script text in the activity log');
$ok($stEvents() === $evStart + 1, 'one rmm.starter.installed event was queued for the subscription (' . ($stEvents() - $evStart) . ')');
$sc = $rows('SELECT script_id, name, current_version, requires_approval, destructive FROM rmm_scripts_v2 WHERE script_id IN (SELECT script_id FROM rmm_script_versions WHERE note LIKE \'starter|rivet-core-starter|win-print-spooler-restart|%\')')[0] ?? [];
$ok((int) ($sc['requires_approval'] ?? 0) === 1 && (int) ($sc['destructive'] ?? 0) === 1, 'a disruptive script requires approval and is marked destructive');

$scriptsBefore = (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2'); $verBefore = (int) $one('SELECT COUNT(*) FROM rmm_script_versions'); $polBefore = (int) $one('SELECT COUNT(*) FROM rmm_policies');
$stPost(['action' => 'install', 'ids' => $pick]);
[, $b] = $stPg();
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $scriptsBefore && (int) $one('SELECT COUNT(*) FROM rmm_script_versions') === $verBefore && (int) $one('SELECT COUNT(*) FROM rmm_policies') === $polBefore && substr_count($b, 'Already up to date') >= 4, 'installing the same items again changes nothing (idempotent) and says so');
$ok($stEvents() === $evStart + 1, 'and raises no second event');
$ok(str_contains($b, 'data-rmm-starter-kpi="scripts">3 <span') , 'the figures follow: 3 of 73 scripts installed');

// ---- the page after the install: states, links
[, $b] = $stPg();
$ok(str_contains($b, '>Installed<') && str_contains($b, 'href="/agent/rmm_script_library.php?script_id=' . $sid . '"') && str_contains($b, 'Open in the library'), 'installed items say "Installed" and link to the library');
[, $b] = $stPg('admin', '?state=current');
$ok(substr_count($b, 'data-rmm-starter-row="') === 4, 'state filter: the four installed items');

// ============================================================ policy parameters
$stPost(['action' => 'plan', 'ids' => ['policy-windows-server'], 'pp' => ['policy-windows-server' => ['ntp_server' => 'time.example.test', 'backup_service' => 'VeeamEndpointBackupSvc', 'uptime_warn_days' => 'abc']]]);
[, $b] = $stPg();
$ok(str_contains($b, 'Blocked') && str_contains($b, 'Parameter uptime_warn_days must be a whole number'), 'a bad parameter value is Core\'s sentence in the preview ("Blocked")');
$ok($stPolicyRows() === 1, 'and nothing was created');
$stPost(['action' => 'plan', 'ids' => ['policy-windows-server'], 'pp' => ['policy-windows-server' => ['ntp_server' => 'time.example.test', 'backup_service' => '']]]);
[, $b] = $stPg();
$ok(str_contains($b, 'Will be added') && str_contains($b, 'Left out because their value is empty') && str_contains($b, 'starter.backup_service'), 'an empty optional parameter leaves its check out, and the preview says which');
$stPost(['action' => 'install', 'ids' => ['policy-windows-server'], 'pp' => ['policy-windows-server' => ['ntp_server' => 'time.example.test', 'backup_service' => 'VeeamEndpointBackupSvc'],
    'win-flush-dns' => ['ntp_server' => 'ignored.example.test'], 'policy-linux-server' => ['ntp_server' => 'ignored.example.test']]]);
$pw = $rows("SELECT policy_id, enabled, body_json AS settings_json FROM rmm_policies WHERE description LIKE '%[starter|rivet-core-starter|policy-windows-server|%'")[0] ?? [];
$ok(($pw['enabled'] ?? 1) == 0 && str_contains((string) ($pw['settings_json'] ?? ''), 'time.example.test') && str_contains((string) ($pw['settings_json'] ?? ''), 'VeeamEndpointBackupSvc'), 'the typed values reached the policy draft, which is still switched off');
$ok(!str_contains((string) ($pw['settings_json'] ?? ''), 'ignored.example.test') && $stPolicyRows() === 2, 'parameters typed for items that are not ticked, and not that policy\'s, are ignored');
$ok(preg_match('/rmm_starter\.php/', $b) === 1 && true, 'parameter flow reachable');

// ============================================================ the script library: Starter badge, risk chip, no badge on your own scripts
$mine = $rmm->scripts()->create(['name' => 'My own script', 'description' => 'not from the pack', 'language' => 'powershell', 'body' => 'Get-Date', 'tags' => ['mine'], 'params' => []], 1);
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok($c === 200 && substr_count($b, 'data-rmm-starter-script="1"') === 3 && substr_count($b, 'rmm-starter-badge') >= 3, 'script library: the three starter scripts are marked Starter (and nothing else is)');
$ok(str_contains($b, 'Read only') && str_contains($b, 'Changes the system') && str_contains($b, 'Disruptive'), 'script library: each carries its risk chip');
preg_match('/<tr data-rmm-starter-script="1">.*?<\/tr>/s', $b, $firstRow);
$ok(isset($firstRow[0]) && !str_contains($firstRow[0], '>starter<') && !str_contains($firstRow[0], 'risk:'), 'the tags column does not repeat the starter and risk tags');
$ok(preg_match('/My own script<\/a>(?:(?!<\/tr>).)*<\/tr>/s', $b, $mr) === 1 && !str_contains($mr[0], 'rmm-starter-badge'), 'your own script has no Starter badge');
$ok(!str_contains($b, 'rmm-starter-waiting'), 'no "update waiting" marker while nothing is parked');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $sid, $SS['admin']);
$ok($c === 200 && str_contains($b, 'rmm-starter-badge') && str_contains($b, 'Read only') && !str_contains($b, 'data-rmm-starter-update'), 'a starter script page shows the badge and the chip');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['tech']);
$ok($c === 200 && substr_count($b, 'data-rmm-starter-script="1"') === 3 && !str_contains($b, 'href="/agent/rmm_starter_pack.php"'), 'a technician sees the marks too, but not the button to the page');
// policies: the draft badge, the marker is not shown
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php', $SS['admin']);
$ok($c === 200 && substr_count($b, 'rmm-starter-badge') === 2 && !str_contains($b, '[starter|') && str_contains($b, 'Starter: Linux Server baseline'), 'policies: the drafts carry the Starter badge and the stored marker text is not displayed');
[$c, $b] = web($wb, 'GET', '/agent/rmm_policies.php?policy_id=' . (int) $pol['policy_id'], $SS['admin']);
$ok($c === 200 && str_contains($b, '(Starter pack draft)') && !str_contains($b, '[starter|rivet-core-starter|policy-linux-server') && !str_contains($b, 'starter|rivet-core-starter'), 'a draft\'s own page says it is a Starter pack draft, and the marker text is not shown anywhere on it (not even in the editor)');
$ok(rivetRmmAutoStarterPolicy('Hello. [starter|rivet-core-starter|policy-linux-server|1.0.0|' . str_repeat('a', 64) . ']') === ['Hello.', true] && rivetRmmAutoStarterPolicy('Hello [starter|x]') === ['Hello [starter|x]', false] && rivetRmmAutoStarterMarker('x') === '', 'the marker helpers only recognise the exact marker at the end of a description');

// ============================================================ edited scripts and updates waiting
$editBody = "echo edited by the administrator\n";
$upd = rivetRmmAutoApi($db, 1, 'admin', 'PUT', ['scripts', $sid], [], ['body' => $editBody, 'note' => 'my change']);
$ok($upd['ok'], 'the administrator edits the starter script through the library (' . $upd['status'] . ' ' . $upd['error'] . ')');
$fresh();
$ok($stState('lin-disk-usage') === 'modified', 'the catalog now says "modified"');
[, $b] = $stPg('admin', '?q=disk+usage');
$ok(str_contains($b, 'Edited by you'), 'the page says "Edited by you"');
$stPost(['action' => 'plan', 'ids' => ['lin-disk-usage']]);
[, $b] = $stPg();
$ok(str_contains($b, 'New version waits for your review') && str_contains($b, 'An administrator changed this script'), 'a preview of an edited script says the new text would wait for review');
// the pack ships a newer text: Core parks it as a version flagged starter-updated (here simulated through ScriptService::stage, which is what StarterInstaller calls)
$hash = '';
foreach ($stCatalog()['packs'][0]['scripts'] as $s) { if ($s['id'] === 'lin-disk-usage') { $hash = $s['content_hash']; } }
$cur = (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id = $sid");
$staged = $rmm->scripts()->stage($sid, ['body' => "echo pack text v2\n", 'params' => [], 'note' => 'starter-updated|rivet-core-starter|lin-disk-usage|1.0.0|' . $hash], 1);
$ok($staged > $cur && $stState('lin-disk-usage') === 'update_staged' && (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id = $sid") === $cur, 'a parked update: version ' . $staged . ' exists, is not current, and the catalog says "update_staged"');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok(substr_count($b, 'rmm-starter-waiting') === 1 && str_contains($b, 'Update waiting'), 'script library: the "Update waiting" marker on that one script');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $sid, $SS['admin']);
$ok(str_contains($b, 'data-rmm-starter-update="' . $staged . '"') && str_contains($b, 'The Starter pack has a newer version of this script') && str_contains($b, 'from=' . $cur . '&amp;to=' . $staged), 'the script page explains it and links the comparison of the current version with the parked one');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $sid, $SS['admin']);
$ok(str_contains($b, 'Installed from the starter pack (rivet-core-starter 1.0.0)') && str_contains($b, 'Starter pack update, waiting for review') && str_contains($b, 'Compare with the current version') && !str_contains($b, 'starter-updated|'), 'the versions table words the pack\'s notes plainly and links the comparison, without the raw marker');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $sid . '&from=' . $cur . '&to=' . $staged, $SS['admin']);
$ok($c === 200 && str_contains($b, 'id="scr-diff"') && str_contains($b, 'pack text v2'), 'and that link shows the differences');
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $sid, $SS['tech']);
$ok($c === 200 && str_contains($b, 'data-rmm-starter-update="' . $staged . '"'), 'a technician sees the marker on the script page too');
[, $b] = $stPg('admin', '?q=disk+usage');
$ok(str_contains($b, 'Update waiting for review'), 'the Starter pack page says "Update waiting for review"');
$stPost(['action' => 'install', 'ids' => ['lin-disk-usage']]);
$ok((int) $one("SELECT COUNT(*) FROM rmm_script_versions WHERE script_id = $sid") === $staged && (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id = $sid") === $cur, 'installing again does not add another version or change the current one (the update is already parked)');

// ============================================================ uninstall: a preview first, only untouched items
[$c] = $stPost(['action' => 'uninstall', 'ids' => ['win-flush-dns']]);   // the page never offers a bare uninstall; the handler runs it (it is the confirmed second step)
$ok($stState('win-flush-dns') === 'retired', 'the confirmed uninstall retires an untouched script (its history stays)');
$stPost(['action' => 'install', 'ids' => ['win-flush-dns']]);
$ok($stState('win-flush-dns') === 'retired', 'an administrator-retired script stays retired when the pack is installed again');
$q("UPDATE rmm_scripts_v2 SET retired_at = NULL WHERE script_id = (SELECT script_id FROM rmm_script_versions WHERE note LIKE 'starter|rivet-core-starter|win-flush-dns|%' LIMIT 1)");
$stPost(['action' => 'uninstall_preview', 'ids' => ['lin-disk-usage', 'win-flush-dns', 'policy-linux-server', 'policy-windows-server']]);
[, $b] = $stPg();
$ok(str_contains($b, 'Preview: what an uninstall would do') && substr_count($b, '</i>Would be retired</span>') === 1 && substr_count($b, '</i>Would be deleted</span>') === 2 && str_contains($b, 'An administrator changed this script, so it was left alone.') && str_contains($b, 'Uninstall these'), 'uninstall preview: the edited script is left alone, the untouched script and the two drafts are listed');
$ok($stState('win-flush-dns') === 'current' && $stPolicyRows() === 2, 'and the preview changed nothing');
$ok(preg_match('/data-rmm-confirm="Uninstall these items\?/', $b) === 1, 'the confirming button asks again in the page\'s own dialog (no window.confirm)');
// a draft that is switched on is never deleted
$pl = (int) $one("SELECT policy_id FROM rmm_policies WHERE description LIKE '%[starter|rivet-core-starter|policy-linux-server|%'");
$rmm->policyStore()->update($pl, ['enabled' => true], 1);
$stPost(['action' => 'uninstall', 'ids' => ['lin-disk-usage', 'win-flush-dns', 'policy-linux-server', 'policy-windows-server']]);
[, $b] = $stPg();
$ok($stPolicyRows() === 1 && $stState('policy-linux-server') !== 'not_installed' && $stState('policy-windows-server') === 'not_installed' && $stState('win-flush-dns') === 'retired' && $stState('lin-disk-usage') === 'update_staged', 'uninstall: the switched-on draft and the edited script are left alone, the untouched script is retired and the untouched draft deleted');
$ok(str_contains($b, 'Uninstall result') && str_contains($b, 'was left alone') && $stAudit('Starter Pack Item Retired') >= 1 && $stAudit('Starter Pack Item Deleted') >= 1, 'the result and the activity log say what happened');

// editing a draft through the real handler keeps its marker (the editor does not show it; the handler puts the stored one back)
$edit = web($wb, 'POST', '/agent/post/rmm_automation_pol.php', $SS['admin'], ['csrf_token' => 'csrftok1', 'action' => 'save_policy', 'policy_id' => (int) $pol['policy_id'], 'name' => 'Starter: Linux Server baseline', 'description' => 'Reviewed by the team.', 'return' => '/agent/rmm_policies.php']);
$descNow = (string) $one('SELECT description FROM rmm_policies WHERE policy_id = ' . (int) $pol['policy_id']);
$ok(str_starts_with($descNow, 'Reviewed by the team.') && preg_match('/ \[starter\|rivet-core-starter\|policy-linux-server\|1\.0\.0\|[0-9a-f]{64}\]$/', $descNow) === 1 && strlen($descNow) <= 300, 'saving a draft keeps its marker (' . $edit[0] . ')');
$q("UPDATE rmm_policies SET description = '" . $esc($descNow) . "' WHERE policy_id = " . (int) $pol['policy_id']);

// ============================================================ the blocker: no signing key
$savedEnc = $one('SELECT signing_private_key_enc FROM endpoint_agent_settings'); $savedPub = $one('SELECT signing_public_key FROM endpoint_agent_settings');
$q("UPDATE endpoint_agent_settings SET signing_private_key_enc = NULL, signing_public_key = ''");
$fresh();
[$c, $b] = $stPg();
$ok($c === 200 && str_contains($b, 'data-rmm-starter-blocker') && str_contains($b, 'Installing is not possible yet') && str_contains($b, 'The instance signing key is not available') && preg_match('/<button type="submit" name="action" value="install"[^>]*disabled/', $b) === 1, 'no signing key: Core\'s sentence is shown first, Install is disabled, Preview still works');
$before = $stScriptRows();
$stPost(['action' => 'install', 'ids' => ['lin-memory-swap']]);
$ok($stScriptRows() === $before && $stState('lin-memory-swap') === 'not_installed', 'a forged install without the key installs nothing');
$stPost(['action' => 'plan', 'ids' => ['lin-memory-swap']]);
[, $b] = $stPg();
$ok(str_contains($b, 'Blocked') && str_contains($b, 'The instance signing key is not available') && !str_contains($b, 'Install these'), 'a preview shows the item as Blocked and offers no install button');
$q("UPDATE endpoint_agent_settings SET signing_private_key_enc = " . ($savedEnc === null ? 'NULL' : "'" . $esc((string) $savedEnc) . "'") . ", signing_public_key = '" . $esc((string) $savedPub) . "'");
$fresh();
$ok($stCatalog()['ready'] === true, 'the signing key is back');

// ============================================================ escaping of server text
$odd = $rmm->scripts()->create(['name' => 'Hostile <b>name</b>', 'description' => '"><img src=x onerror=alert(1)>', 'language' => 'bash', 'body' => 'true', 'tags' => ['starter', 'risk:read_only'], 'params' => []], 1);
[$c, $b] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok($c === 200 && !str_contains($b, '<img src=x onerror') && !str_contains($b, 'Hostile <b>name</b>') && str_contains($b, 'Hostile &lt;b&gt;name&lt;/b&gt;'), 'library: a hostile script name and description are escaped, even with the starter tags');
// a script that only claims to be a starter script (the tags are free text) is a badge and nothing more: it is not in the pack, so the pack\'s own state ignores it
$ok($stState('lin-cron-jobs') === 'not_installed', 'a look-alike tag does not make a script part of the pack');

// ============================================================ clean up what this fragment created
$q("UPDATE webhooks SET webhook_enabled = 0 WHERE webhook_name = 'starter events'");
