<?php

/*
 * This file is part of the Laravel Redis Sentinel Retry package.
 *
 * (c) Roman Ignashev <ignashevroman99@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.md.
 */

declare(strict_types=1);

namespace Ignashevroman\Redis\Sentinel;

use Ignashevroman\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ServiceProvider;

/**
 * Registers and boots services of the Laravel Redis Sentinel package.
 */
class RedisSentinelServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->extend('redis', function (RedisManager $service) {
            return $service->extend('phpredis-sentinel', fn () => new PhpRedisSentinelConnector);
        });
    }
}
