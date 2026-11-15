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

namespace Ignashevroman\Redis\Sentinel\Connections;

use Illuminate\Redis\Connections\PhpRedisConnection;
use RedisException;

/**
 * The connection to Redis after connecting through a Sentinel using the PhpRedis extension.
 */
class PhpRedisSentinelConnection extends PhpRedisConnection
{
    // The following array contains all exception message parts which are interpreted as a connection loss or
    // another unavailability of Redis.
    private const ERROR_MESSAGES_INDICATING_UNAVAILABILITY = [
        'connection closed',
        'connection refused',
        'connection lost',
        'failed while reconnecting',
        'is loading the dataset in memory',
        'php_network_getaddresses',
        'read error on connection',
        'socket',
        'went away',
        'loading',
        'readonly',
        "can't write against a read only replica",
    ];

    /**
     * Inspects the given exception and reconnects the client if the reported error indicates that the server
     * went away or is in readonly mode, which may happen in case of a Redis Sentinel failover.
     */
    private function tryReconnect(RedisException $exception): bool
    {
        if (! $this->isUnavailabilityError($exception->getMessage())) {
            return false;
        }

        // Here we reconnect through Redis Sentinel if we lost connection to the server or if another unavailability occurred.
        // We may actually reconnect to the same, broken server. But after a failover occured, we should be ok.
        // It may take a moment until the Sentinel returns the new master, so this may be triggered multiple times.
        $this->reconnect();

        return true;
    }

    /**
     * Checks whether the given error message indicates a connection loss or another unavailability of Redis.
     */
    public function isUnavailabilityError(string $message): bool
    {
        // We convert the message to lower-case in order to perform case-insensitive comparison.
        $message = strtolower($message);

        // Because we also match only partial messages, we cannot use in_array() at this point.
        foreach (self::ERROR_MESSAGES_INDICATING_UNAVAILABILITY as $errorMessage) {
            if (str_contains($message, $errorMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reconnects to the Redis server by overriding the current connection.
     */
    private function reconnect(): void
    {
        $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
    }

    public function handleFailover(RedisException $exception): bool
    {
        return $this->tryReconnect($exception);
    }

    /**
     * {@inheritdoc}
     *
     * The pipeline with a callback is retried as a whole after a failover.
     *
     * @throws RedisException
     */
    public function pipeline(?callable $callback = null)
    {
        if ($callback === null) {
            return parent::pipeline();
        }

        return $this->retryOnFailover(fn () => parent::pipeline($callback));
    }

    /**
     * {@inheritdoc}
     *
     * The transaction with a callback is retried as a whole after a failover.
     *
     * @throws RedisException
     */
    public function transaction(?callable $callback = null)
    {
        if ($callback === null) {
            return parent::transaction();
        }

        return $this->retryOnFailover(fn () => parent::transaction($callback));
    }

    /**
     * Runs the operation and repeats it after reconnecting if the error indicates unavailability of Redis.
     *
     * @throws RedisException
     */
    private function retryOnFailover(callable $operation): mixed
    {
        $maxRetries = (int) ($this->config['sentinel_max_retries'] ?? 3);
        $retryDelay = (int) ($this->config['sentinel_retry_delay'] ?? 100_000);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $operation();
            } catch (RedisException $e) {
                if ($attempt >= $maxRetries || ! $this->reconnectAfter($e)) {
                    throw $e;
                }

                usleep($retryDelay);
            }
        }
    }

    /**
     * Reconnects if the error indicates unavailability of Redis. A failed reconnect is retried with the next attempt.
     */
    private function reconnectAfter(RedisException $exception): bool
    {
        if (! $this->isUnavailabilityError($exception->getMessage())) {
            return false;
        }

        try {
            $this->reconnect();
        } catch (RedisException) {
            // Sentinel may not return the new master yet.
        }

        return true;
    }

    public function setClient($client): void
    {
        $this->client = $client;
    }
}
