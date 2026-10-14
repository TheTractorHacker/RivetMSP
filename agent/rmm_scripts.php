<?php
/*
 * Scripts: the single place for scripts (RivetMSP 26.10.22). Tabs:
 *   Library                  the RivetCore script library: the Starter pack and your own signed, versioned scripts (default tab; needs the RMM module and its `scripts` switch)
 *   Synced from <provider>   the older list (table rmm_scripts): scripts written by hand or synced from a vendor RMM such as Tactical RMM, with the one-time import into the Library
 * One menu entry. Older addresses keep working: rmm_script_library.php redirects here (its query string kept), rmm_scripts.php?category=... opens the synced tab.
 * See includes/rmm_scripts_hub.php. The Library tab reads and writes only through RivetCore's technician API as the signed-in user (includes/rmm_scr_ui.php).
 */

ob_start();   // buffer the page: a denial can then still answer 403 after the app shell has started, and a POST can still redirect
require_once "includes/inc_all.php";
if (lookupUserPermission('module_rmm') < 1) {
    http_response_code(403);
}
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_scripts_hub.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!empty($config_core_rmm_enabled)) {
    require_once dirname(__DIR__) . '/includes/rmm_scr_ui.php';   // (loads the RMM bootstrap)
}
$hub = rivetScriptsHubTabs($mysqli, (int) $session_user_id);
$tab = rivetScriptsHubPick($hub, $_GET);
if (($_GET['tab'] ?? '') === 'library' || $hub['tabs'] === []) {
    $tab = 'library';   // asked for, or nothing else to show: the Library page explains why it is not available
}

echo rivetScriptsHubStrip($hub['tabs'], $tab);

if ($tab === 'synced') {
    require __DIR__ . '/includes/rmm_scripts_synced.php';
} elseif (empty($config_core_rmm_enabled)) {
    echo '<div class="alert alert-warning" role="alert">The RivetCore RMM module is switched off, so there is no script library to show.</div>';
} else {
    $ctx = rivetRmmAutoPage($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
    if ($ctx !== null) {
        $rmm_auto_extra_js = ['rmm_scr.js'];
        if (isset($_GET['script_id']) || isset($_GET['new'])) {
            echo rivetRmmScrScriptPage(rivetRmmScrScriptView($ctx, $_GET), $ctx);
        } else {
            echo rivetRmmScrLibraryPage(rivetRmmScrLibraryView($ctx, $_GET), $ctx);
        }
    }
}

require_once "../includes/footer.php";
