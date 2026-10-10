<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricSinkConformanceTestCase;

/** RivetMSP has no metrics subsystem (decision D5): since RivetCore 1.0.0-rc.9 the module is given Core's DatabaseMetricSink (hourly rollups in the Core tables), which must satisfy the sink contract. */
final class EndpointMetricSinkConformanceTest extends RmmMetricSinkConformanceTestCase
{
    protected function sink(): RmmMetricSinkInterface
    {
        return new \RivetCore\Rmm\Support\DatabaseMetricSink(EndpointKit::db(), new \RivetCore\Support\SystemClock());
    }

    protected function stored(int $assetId): ?array
    {
        $out = [];
        foreach (EndpointKit::db()->fetchAll('SELECT metric_key, value FROM rmm_metric_latest WHERE asset_id = ? ORDER BY metric_key', [$assetId]) as $r) {
            $out[] = ['key' => (string) $r['metric_key'], 'value' => (float) $r['value']];
        }

        return $out;
    }
}
