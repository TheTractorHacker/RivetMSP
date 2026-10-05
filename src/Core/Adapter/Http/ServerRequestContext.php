<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Http;

use RivetCore\Contracts\RequestContextInterface;

/**
 * Supplies per-request facts to RivetCore. Prefers the app's own resolved $session_ip / $session_user_agent
 * (what logAction() records, proxy-aware) and falls back to the raw server values.
 */
final class ServerRequestContext implements RequestContextInterface
{
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
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
    }
}
