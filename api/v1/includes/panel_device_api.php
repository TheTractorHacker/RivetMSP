<?php
defined('FROM_API') || die();

/**
 * Bridge for the five RivetPanel device-facing endpoints (panel_enroll, panel_me, panel_categories, panel_tickets,
 * panel_ticket_chat) - GitHub roadmap "Prompt 2". Twin of api/v1/includes/agent_device_api.php for the same
 * reason: a panel device credential is not an api_tokens row, and PanelApi bounds and rate-limits its own body
 * read, so these routes are dispatched from api/v1/index.php ABOVE the Bearer/legacy-key parsing and above its
 * pre-auth request-body read. The handlers live in rivet/rivet-core (RivetCore\Panel\Http\PanelApi, wire
 * protocol: vendor/rivet/rivet-core/docs/panel/PANEL_API.md); this file only builds the request from the
 * superglobals, supplies RivetMSP's rate limiter (the same api_rate_limit() the RMM device API uses), and emits
 * the response.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/includes/panel_bootstrap.php';

function panel_dispatch(string $endpoint): void
{
    global $session_user_id, $session_ip, $session_user_agent;
    // Audit entries made on behalf of a device have no session user.
    $session_user_id = 0;
    $session_ip = getIP();
    $session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    mysqli_report(MYSQLI_REPORT_OFF);

    try {
        // Besides PanelModule's own 'enabled' option (settings.config_core_panel_enabled, this edition's kill switch -
        // see includes/panel_bootstrap.php), PanelApi itself answers 503 module_disabled (Retry-After 3600) while off.
        $api = rivetPanelModule()->api(
            static fn (string $bucket, int $limit, int $window): bool => api_rate_limit($bucket, $limit, $window)
        );
        $response = $api->handle(rivetRmmRequest($endpoint));
    } catch (\Throwable $e) {
        error_log('rivetpanel: ' . get_class($e) . ': ' . $e->getMessage());
        $response = \RivetCore\Rmm\Http\RmmResponse::json(500, ['error' => 'Internal error.', 'code' => 'internal']);
    }
    (new \RivetCore\Rmm\Http\SapiEmitter())->emit($response);
    exit;
}
