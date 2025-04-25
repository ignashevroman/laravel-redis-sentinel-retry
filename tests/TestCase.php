<?php

/*
 * This file is part of the Laravel Redis Sentinel Retry package.
 *
 * (c) Roman Ignashev <ignashevroman99@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.md.
 */

/** @noinspection PhpMissingParamTypeInspection */

declare(strict_types=1);

namespace Ignashevroman\Redis\Sentinel\Tests;

/**
 * Base for all unit tests.
 */
abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Returns a list of service providers required for the tests.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return string[]
     */
    protected function getPackageProviders($app): array
    {
        return [
            \Illuminate\Redis\RedisServiceProvider::class,
            \Ignashevroman\Redis\Sentinel\RedisSentinelServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // We use the phpredis-sentinel redis driver as default.
        $app['config']->set('database.redis.client', 'phpredis-sentinel');

        // Setup configuration for different types of supported databases.
        $app['config']->set('database.redis.default', [
            'sentinel_host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
            'sentinel_port' => (int) env('REDIS_SENTINEL_PORT', 6379),
            'sentinel_username' => env('REDIS_SENTINEL_USERNAME'),
            'sentinel_password' => env('REDIS_SENTINEL_PASSWORD'),
            'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        ]);
    }
}
