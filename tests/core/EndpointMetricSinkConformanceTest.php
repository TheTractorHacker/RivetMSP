<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricSinkConformanceTestCase;

/** RivetMSP has no metrics subsystem (decision D5): the module is given Core's NullRmmMetricSink, which must satisfy the contract (and keeps nothing). */
final class EndpointMetricSinkConformanceTest extends RmmMetricSinkConformanceTestCase
{
    protected function sink(): RmmMetricSinkInterface
    {
        return new \RivetCore\Rmm\Support\NullRmmMetricSink();
    }
}
