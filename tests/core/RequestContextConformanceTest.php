<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

require_once __DIR__ . '/ConformanceSupport.php';

use RivetCore\Contracts\RequestContextInterface;

if (ConformanceSupport::kitAvailable()) {
    final class RequestContextConformanceTest extends \RivetCore\Testing\RequestContextConformanceTestCase
    {
        protected function context(): RequestContextInterface
        {
            return new \RivetMSP\Core\Adapter\Http\ServerRequestContext();
        }
    }
} else {
    final class RequestContextConformanceTest extends KitMissingTestCase
    {
    }
}
