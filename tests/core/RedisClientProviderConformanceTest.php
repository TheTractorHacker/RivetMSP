<?php

declare(strict_types=1);

require_once __DIR__ . '/ConformanceSupport.php';

use RivetCore\Redis\RedisClientProviderInterface;

if (ConformanceSupport::kitAvailable()) {
    final class RedisClientProviderConformanceTest extends \RivetCore\Testing\RedisClientProviderConformanceTestCase
    {
        protected function provider(): RedisClientProviderInterface
        {
            // The environment wins over stored settings (RedisSettings::resolve), so no database is needed.
            putenv('RIVETMSP_REDIS_HOST=127.0.0.1');
            putenv('RIVETMSP_REDIS_PORT=' . (string) getenv('RIVETCORE_TEST_REDIS_PORT'));

            return new \RivetMSP\Core\Adapter\Redis\GlobalRedisClientProvider();
        }

        protected function unreachableProvider(): RedisClientProviderInterface
        {
            putenv('RIVETMSP_REDIS_HOST=127.0.0.1');
            putenv('RIVETMSP_REDIS_PORT=' . self::closedPort());

            return new \RivetMSP\Core\Adapter\Redis\GlobalRedisClientProvider();
        }
    }
} else {
    final class RedisClientProviderConformanceTest extends KitMissingTestCase
    {
    }
}
