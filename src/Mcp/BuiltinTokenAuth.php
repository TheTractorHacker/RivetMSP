<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

use RivetMSP\Mcp\OAuth\ClientMetadata;
use RivetMSP\Mcp\OAuth\OAuthConfig;
use RivetMSP\Mcp\OAuth\OAuthStore;

/**
 * Validates the opaque access tokens RivetMSP's own built-in OAuth server issued (settings_mcp.php, "Built-in
 * sign-in"). This is the only token source the v1 resource server (mcp_server/index.php) accepts: an external
 * identity provider's JWTs (the "Issuer URL"/"Audience" fields on the same settings page) are not yet wired to
 * a resource server here - see the limitation note in mcp_server/index.php.
 *
 * A token is accepted only if its hash is stored, unexpired, its consent (grant) is not revoked or expired, its
 * client is not disabled, the current client-metadata-document trust policy still allows that client, and the
 * consenting user is still an active agent (user_type 1, user_status 1, not archived). All of that is one query
 * (OAuthStore::liveAccessToken), so a revoked grant, a disabled client or a disabled agent all answer "invalid
 * token" on the very next call - nothing here is cached beyond the single request.
 *
 * Deliberately NOT a port of ITFlow's BuiltinTokenValidator class: that one implements the mcp/sdk's
 * AuthorizationTokenValidatorInterface, which RivetMSP does not vendor (no mcp/sdk dependency was added for
 * this v1 - see mcp_server/index.php). Same checks, plain return value instead.
 */
final class BuiltinTokenAuth
{
    private OAuthStore $store;

    public function __construct(private \mysqli $db, private string $resource)
    {
        $this->store = new OAuthStore($db);
    }

    /**
     * @return array{user_id:int, scopes:list<string>, client_id:string}|null null means: respond 401 invalid_token.
     */
    public function validate(string $accessToken): ?array
    {
        if (!preg_match('/^rvt_at_[A-Za-z0-9_-]{43}$/D', $accessToken)) {
            return null;
        }
        try {
            $row = $this->store->liveAccessToken(OAuthStore::hash($accessToken));
        } catch (\Throwable $e) {
            error_log('MCP OAuth token lookup failed: ' . $e::class);

            return null;
        }
        if ($row === null || !hash_equals($this->resource, (string) $row['resource'])) {
            return null;
        }
        try {
            $allowed = ClientMetadata::policyAllows((string) $row['registration_type'], $row['source_url'] ?? null, OAuthConfig::cimd($this->db));
        } catch (\Throwable $e) {
            error_log('MCP OAuth policy check failed: ' . $e::class);
            $allowed = false;   // cannot tell: fail closed
        }
        if (!$allowed) {
            return null;
        }
        $scopes = array_values(array_filter(explode(' ', (string) $row['scope'])));
        if (!in_array(OAuthConfig::SCOPE, $scopes, true)) {
            return null;
        }
        try {
            $this->store->touchGrant((int) $row['grant_id']);
        } catch (\Throwable) { /* bookkeeping only */
        }

        return ['user_id' => (int) $row['user_id'], 'scopes' => $scopes, 'client_id' => (string) $row['client_id']];
    }
}
