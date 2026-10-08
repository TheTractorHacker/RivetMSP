<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

use PHPUnit\Framework\TestCase;
use RivetMSP\Core\Adapter\Http\ServerRequestContext;

/** The audit trail must never store a request id chosen by the client. */
final class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['RIVET_REQUEST_ID']);
        $_SERVER['HTTP_X_REQUEST_ID'] = 'attacker-chosen-id';
    }

    public function testClientRequestIdHeaderIsIgnored(): void
    {
        $ctx = new ServerRequestContext();
        $id = $ctx->requestId();
        $this->assertNotSame('attacker-chosen-id', $id);
        $this->assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', (string) $id);
        $this->assertSame($id, $ctx->requestId(), 'stable for the whole request');
    }

    public function testAServerAssignedIdIsUsed(): void
    {
        $_SERVER['RIVET_REQUEST_ID'] = 'req_server_set';
        $this->assertSame('req_server_set', (new ServerRequestContext())->requestId());
        unset($_SERVER['RIVET_REQUEST_ID']);
    }
}
