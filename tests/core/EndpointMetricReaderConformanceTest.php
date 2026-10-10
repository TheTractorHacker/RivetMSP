<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricReaderConformanceTestCase;

/** RivetMSP's metric reader: Core's DatabaseMetricSink (latest, peak, series over rmm_metric_latest and rmm_metric_hourly, migration 0018). */
final class EndpointMetricReaderConformanceTest extends RmmMetricReaderConformanceTestCase
{
    protected function store(): RmmMetricSinkInterface&RmmMetricReaderInterface
    {
        return new \RivetCore\Rmm\Support\DatabaseMetricSink(EndpointKit::db(), new \RivetCore\Support\SystemClock());
    }
}
