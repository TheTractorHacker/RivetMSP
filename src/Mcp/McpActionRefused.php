<?php

declare(strict_types=1);

namespace RivetMSP\Mcp;

/** A write action cannot be queued or applied (bad argument, closed ticket, ...). The message is safe to show to the caller/approver. */
final class McpActionRefused extends \RuntimeException
{
}
