<?php

namespace RivetMSP\Mcp;

use GuzzleHttp\ClientInterface;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetMSP\Core\Adapter\Mcp\UsersAgentDirectory;

/**
 * "Is this actually going to work?" checks for Administration > Remote MCP. Each check returns
 * status ok|warn|fail|skip, a short label, and a plain-language detail with the fix. Network access goes
 * through an injected Guzzle client so the checks can be tested without a real identity provider.
 *
 * Ported from RivetIT's ITFlow\Mcp\McpDiagnostics. Edition adapter (not deprecated) over RivetCore\Mcp\McpDiagnostics;
 * the linked-agent count comes from RivetMSP's users.
 */
final class McpDiagnostics
{
    private \RivetCore\Mcp\McpDiagnostics $core;

    public function __construct(\mysqli $db, ClientInterface $http)
    {
        $this->core = new \RivetCore\Mcp\McpDiagnostics(
            new UsersAgentDirectory(new MysqliDatabaseAdapter($db)), $http, 'RIVETMSP_MCP_ENABLED', (defined('APP_NAME') ? APP_NAME : 'RivetMSP')
        );
    }

    /** @return list<array{status:string,label:string,detail:string}> */
    public function run(array $cfg, string $baseHost): array
    {
        return $this->core->run($cfg, $baseHost);
    }
}
