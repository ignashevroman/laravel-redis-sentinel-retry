<?php

/* @noinspection PhpRedundantCatchClauseInspection */

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
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::ERROR_MESSAGES_INDICATING_UNAVAILABILITY as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                // Here we reconnect through Redis Sentinel if we lost connection to the server or if another unavailability occurred.
                // We may actually reconnect to the same, broken server. But after a failover occured, we should be ok.
                // It may take a moment until the Sentinel returns the new master, so this may be triggered multiple times.
                $this->reconnect();

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

    public function setClient($client): void
    {
        $this->client = $client;
    }
}
