<?php

namespace RivetMSP\Integrations\Microsoft;

/**
 * Builds a GraphClient for one microsoft_integrations ROW (one Entra/Intune tenant per CLIENT,
 * stored by slice 1 - agent/post/intune_settings.php): decrypted secret, shared (encrypted)
 * per-row token cache, optional time limit.
 *
 * Test-injectability, same convention as includes/pax8_functions.php's makePax8Client(): the
 * RIVETMSP_TEST_GRAPH_API_BASE / RIVETMSP_TEST_GRAPH_AUTHORITY env vars, when set, point every
 * GraphClient built here at a loopback fake server (tests/support/fake_graph_router.php) instead
 * of Microsoft's real endpoints - see tests/intune_device_sync.php. Unset in every real
 * environment, where this builds a client against Microsoft's actual endpoints and a later run
 * against a real tenant needs only real credentials entered in slice 1's settings page, not a
 * code change.
 */
final class GraphClientFactory
{
    /**
     * @param array<string,mixed> $row a microsoft_integrations row (one per client)
     * @param array<string,mixed> $options extra GraphClient options (deadline is set separately below)
     */
    public static function forRow(\mysqli $mysqli, array $row, ?int $deadline = null, array $options = []): GraphClient
    {
        $tenant = (string) ($row['tenant_id'] ?? '');
        $azureClientId = (string) ($row['azure_client_id'] ?? '');
        $integrationId = (int) ($row['microsoft_integration_id'] ?? 0);
        $cache = new MysqliGraphTokenCache($mysqli, $integrationId, $tenant, $azureClientId);

        $graphBase = getenv('RIVETMSP_TEST_GRAPH_API_BASE') ?: GraphClient::DEFAULT_GRAPH_BASE;
        $authority = getenv('RIVETMSP_TEST_GRAPH_AUTHORITY') ?: GraphClient::DEFAULT_AUTHORITY;

        return new GraphClient(
            $tenant,
            $azureClientId,
            decryptSetting((string) ($row['client_secret_enc'] ?? '')),
            $cache,
            $graphBase,
            $authority,
            ($deadline !== null ? ['deadline' => $deadline] : []) + $options
        );
    }
}
