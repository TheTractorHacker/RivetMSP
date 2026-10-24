<?php

/*
 * RivetPanel device-token API (GitHub roadmap "Prompt 2"; RivetCore\Panel, migration 0040/0041 per DB 2.6.111).
 * Reuses the RMM module's own tenancy/audit adapters and the RMM enrollment/device tables - Panel works whether
 * or not the RMM module itself is switched on (rivetRmmModule()'s own config_core_rmm_enabled kill switch is a
 * separate, unrelated concern: Panel shares RMM's tables, not RMM's feature flag).
 *
 * Unlike RivetIT's own adoption (which has no master switch - "nobody can reach it until an administrator mints
 * a token"), this edition adds one: settings.config_core_panel_enabled (default OFF), the same config_core_<module>
 * _enabled convention as Problems & Changes/Workflows (includes/core_module_gates.php), wired through
 * RivetMSP\Core\CoreBridge::enabled('core.panel.enabled'). While off, PanelModule's own 'enabled' option makes
 * every device-facing route answer 503 module_disabled (PANEL_API.md) rather than relying on "no token was ever
 * minted" - the Deploy page (agent/panel_deploy.php) is gated the same way via rivetModulePageOn() so an admin
 * cannot mint a token while the module is off either.
 */

require_once __DIR__ . '/rmm_bootstrap.php';
require_once __DIR__ . '/core_module_gates.php';
require_once dirname(__DIR__) . '/src/Core/Adapter/Endpoint/EndpointPanelGateway.php';

if (!function_exists('rivetPanelModule')) {
    /** The Panel module for this request's database connection (built once per connection, like rivetRmmModule()). */
    function rivetPanelModule($mysqli = null): \RivetCore\Panel\PanelModule
    {
        static $built = [];
        $mysqli ??= $GLOBALS['mysqli'] ?? null;
        if (!$mysqli instanceof \mysqli) {
            throw new \RuntimeException('The Panel module needs the database connection.');
        }
        $key = spl_object_id($mysqli);
        if (isset($built[$key]) && $built[$key][0] === $mysqli) {
            return $built[$key][1];
        }
        $db = rivetCoreDb($mysqli);
        $module = new \RivetCore\Panel\PanelModule(
            $db,
            new \RivetCore\Support\SystemClock(),
            new \RivetMSP\Core\Adapter\Endpoint\EndpointTenancy($db),
            new \RivetMSP\Core\Adapter\Endpoint\EndpointAudit(),
            new \RivetMSP\Core\Adapter\Endpoint\EndpointPanelGateway($mysqli),
            [
                'enabled' => static fn (): bool => \RivetMSP\Core\CoreBridge::enabled('core.panel.enabled'),
                'allow_insecure_http' => defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true,
            ]
        );
        $built[$key] = [$mysqli, $module];

        return $module;
    }
}
