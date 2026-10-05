<?php

declare(strict_types=1);

namespace RivetMSP\Core\Adapter\Redis;

use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Gives RivetCore the app's shared Redis connection: getRedisClient() in includes/redis_functions.php, which
 * returns null when Redis is down (every Core Redis feature then fails open).
 */
final class GlobalRedisClientProvider implements RedisClientProviderInterface
{
    public function client(): ?\Predis\Client
    {
        if (!function_exists('getRedisClient')) {
            $file = dirname(__DIR__, 4) . '/includes/redis_functions.php';
            if (is_file($file)) {
                require_once $file;
            }
        }

        return function_exists('getRedisClient') ? getRedisClient() : null;
    }
}
