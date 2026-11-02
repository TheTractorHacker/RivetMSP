<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

use RivetCore\Audit\AuditService;
use RivetCore\Mcp\ToolPipeline;
use RivetCore\Redis\RateLimiter;
use RivetMSP\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetMSP\Core\Adapter\Http\ServerRequestContext;
use RivetMSP\Core\Adapter\Redis\GlobalRedisClientProvider;

/** Builds the one shared RivetCore ToolPipeline (rate limit -> allow -> body -> audit -> envelope) for read AND write tools. */
final class McpPipeline
{
    public const RATE_LIMIT = 60;   // calls per user per window, shared by every tool
    public const RATE_WINDOW = 60;  // seconds

    public static function make(\mysqli $db): ToolPipeline
    {
        $request = new ServerRequestContext();

        return new ToolPipeline(
            new RateLimiter(new GlobalRedisClientProvider(), 'rivetmsp:'),
            new AuditService(new MysqliDatabaseAdapter($db), $request),
            $request,
            self::RATE_LIMIT,
            self::RATE_WINDOW,
        );
    }
}
