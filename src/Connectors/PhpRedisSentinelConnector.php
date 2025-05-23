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

namespace Ignashevroman\Redis\Sentinel\Connectors;

use Ignashevroman\Redis\Sentinel\Client\RedisWrapper;
use Ignashevroman\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Ignashevroman\Redis\Sentinel\Exceptions\ConfigurationException;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Redis;
use RedisException;
use RedisSentinel;

/**
 * Allows to connect to a Sentinel driven Redis master using the PhpRedis extension.
 */
class PhpRedisSentinelConnector extends PhpRedisConnector
{
    /**
     * {@inheritdoc}
     *
     * @throws RedisException
     */
    public function connect(array $config, array $options): PhpRedisSentinelConnection
    {
        $mergedConfig = array_merge(
            $config,
            $options,
            Arr::pull($config, 'options', [])
        );

        $maxRetries = $mergedConfig['sentinel_max_retries'] ?? 3;
        $retryDelay = $mergedConfig['sentinel_retry_delay'] ?? 100_000;

        $rawClient = $this->createClient($mergedConfig);
        $wrapper = new RedisWrapper($rawClient, null, $maxRetries, $retryDelay);
        $connector = function () use (&$wrapper, $mergedConfig) {
            $newClient = $this->createClient($mergedConfig);
            $wrapper->setClient($newClient);

            return $wrapper;
        };

        $connection = new PhpRedisSentinelConnection($rawClient, $connector, $mergedConfig);
        $connection->setClient($wrapper);
        $wrapper->setConnection($connection);

        return $connection;
    }

    /**
     * Create the PhpRedis client instance which connects to Redis Sentinel.
     *
     * @throws ConfigurationException
     * @throws RedisException
     */
    protected function createClient(array $config): Redis
    {
        $service = $config['sentinel_service'] ?? 'mymaster';

        $sentinel = $this->connectToSentinel($config);

        $master = $sentinel->master($service);

        if (! $this->isValidMaster($master)) {
            throw new RedisException(sprintf("No master found for service '%s'.", $service));
        }

        return parent::createClient(array_merge($config, [
            'host' => $master['ip'],
            'port' => $master['port'],
        ]));
    }

    /**
     * Check whether master is valid or not.
     */
    protected function isValidMaster(mixed $master): bool
    {
        return is_array($master) && isset($master['ip']) && isset($master['port']);
    }

    /**
     * Connect to the configured Redis Sentinel instance.
     *
     * @throws ConfigurationException
     */
    protected function connectToSentinel(array $config): RedisSentinel
    {
        $host = $config['sentinel_host'] ?? '';
        $port = $config['sentinel_port'] ?? 26379;
        $timeout = $config['sentinel_timeout'] ?? 0.2;
        $persistent = $config['sentinel_persistent'] ?? null;
        $retryInterval = $config['sentinel_retry_interval'] ?? 0;
        $readTimeout = $config['sentinel_read_timeout'] ?? 0;
        $username = $config['sentinel_username'] ?? '';
        $password = $config['sentinel_password'] ?? '';
        $ssl = $config['sentinel_ssl'] ?? null;

        if (strlen(trim($host)) === 0) {
            throw new ConfigurationException('No host has been specified for the Redis Sentinel connection.');
        }

        $auth = null;
        if (strlen(trim($username)) !== 0 && strlen(trim($password)) !== 0) {
            $auth = [$username, $password];
        } elseif (strlen(trim($password)) !== 0) {
            $auth = $password;
        }

        if (version_compare(phpversion('redis'), '6.0', '>=')) {
            $options = [
                'host' => $host,
                'port' => $port,
                'connectTimeout' => $timeout,
                'persistent' => $persistent,
                'retryInterval' => $retryInterval,
                'readTimeout' => $readTimeout,
            ];

            if ($auth !== null) {
                $options['auth'] = $auth;
            }

            if (version_compare(phpversion('redis'), '6.1', '>=') && $ssl !== null) {
                $options['ssl'] = $ssl;
            }

            return new RedisSentinel($options);
        }

        if ($auth !== null) {
            /** @noinspection PhpMethodParametersCountMismatchInspection */
            return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout, $auth);
        }

        return new RedisSentinel($host, $port, $timeout, $persistent, $retryInterval, $readTimeout);
    }
}
