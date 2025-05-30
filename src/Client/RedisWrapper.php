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

namespace Ignashevroman\Redis\Sentinel\Client;

use Ignashevroman\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Redis;
use RedisException;

/**
 * @mixin Redis;
 */
class RedisWrapper
{
    public function __construct(
        private Redis $client,
        private ?PhpRedisSentinelConnection $connection = null,
        protected int $maxRetries = 3,
        protected int $retryDelay = 100_000,
    ) {
    }

    public function setClient(Redis $client): void
    {
        $this->client = $client;
    }

    public function getClient(): Redis
    {
        return $this->client;
    }

    public function setConnection(PhpRedisSentinelConnection $connection): void
    {
        $this->connection = $connection;
    }

    public function __call(string $name, array $arguments)
    {
        $attempt = 0;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            try {
                return $this->client->{$name}(...$arguments);
            } catch (RedisException $e) {
                try {
                    if ($this->connection && $this->connection->handleFailover($e)) {
                        usleep($this->retryDelay);

                        continue;
                    }
                } catch (RedisException $reconnectionException) {
                    if ($attempt >= $this->maxRetries) {
                        throw $reconnectionException;
                    }

                    usleep($this->retryDelay);

                    continue;
                }

                throw $e;
            }
        }

        // Unreachable in theory
        throw new RedisException("[RedisWrapper] Exceeded max retry attempts for method: {$name}");
    }
}
