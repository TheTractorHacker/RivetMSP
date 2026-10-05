<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Http;

use RivetCore\Contracts\RequestContextInterface;

/**
 * Supplies per-request facts to RivetCore. Prefers the app's own resolved $session_ip / $session_user_agent
 * (what logAction() records, proxy-aware) and falls back to the raw server values. The request id is assigned by the
 * server ($_SERVER['RIVET_REQUEST_ID'] if the entry point set one, otherwise generated); a client-supplied
 * X-Request-ID header is never trusted, so a caller cannot choose what lands in audit rows.
 */
final class ServerRequestContext implements RequestContextInterface
{
    private static ?string $generated = null;

    public function ipAddress(): ?string
    {
        return $GLOBALS['session_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    }

    public function userAgent(): ?string
    {
        return $GLOBALS['session_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    }

    public function requestId(): ?string
    {
        return $_SERVER['RIVET_REQUEST_ID'] ?? (self::$generated ??= 'req_' . bin2hex(random_bytes(8)));
    }
}
