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

namespace Ignashevroman\Redis\Sentinel\Tests\Connectors;

use Ignashevroman\Redis\Sentinel\Client\RedisWrapper;
use Ignashevroman\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use Ignashevroman\Redis\Sentinel\Connectors\PhpRedisSentinelConnector;
use Ignashevroman\Redis\Sentinel\Exceptions\ConfigurationException;
use Ignashevroman\Redis\Sentinel\Tests\TestCase;
use PHPUnit\Framework\MockObject\Exception;
use Redis;
use RedisException;
use RedisSentinel;
use ReflectionClass;
use ReflectionException;

/**
 * Ensures that the {@see PhpRedisSentinelConnection} and {@see RedisWrapper} function properly.
 */
class PhpRedisSentinelConnectorTest extends TestCase
{
    public function test_connects_without_password(): void
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');

        self::assertTrue($connection->ping());
    }

    /**
     * @throws ReflectionException
     */
    public function test_retries_on_transient_failure(): void
    {
        $this->assertRedisWrapperReconnectsOnException(
            new RedisException("READONLY You can't write against a read only replica."),
            'some_key'
        );
    }

    /**
     * @throws ReflectionException
     */
    public function test_retries_on_connection_lost(): void
    {
        $this->assertRedisWrapperReconnectsOnException(
            new RedisException('Connection lost.'),
            'another_key'
        );
    }

    /**
     * @throws Exception
     */
    public function test_throws_if_master_is_invalid(): void
    {
        $connector = $this->getMockBuilder(PhpRedisSentinelConnector::class)
            ->onlyMethods(['connectToSentinel'])
            ->getMock();

        $sentinel = $this->createStub(RedisSentinel::class);
        $sentinel->method('master')->willReturn(null);

        $connector->expects($this->exactly(2))->method('connectToSentinel')->willReturn($sentinel);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageMatches('/No master found/');

        $connector->connect([
            'sentinel_service' => 'invalid',
            'sentinel_host' => '127.0.0.1',
            'sentinel_connect_max_retries' => 2,
            'sentinel_connect_retry_delay' => 0,
        ], []);
    }

    public function test_throws_if_sentinel_host_is_empty(): void
    {
        $connector = new PhpRedisSentinelConnector();

        $this->expectException(ConfigurationException::class);
        $connector->connect([], []);
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function test_retries_first_connect_until_sentinel_is_available(): void
    {
        $connector = $this->getMockBuilder(PhpRedisSentinelConnector::class)
            ->onlyMethods(['connectToSentinel'])
            ->getMock();

        $sentinel = new RedisSentinel(['host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_SENTINEL_PORT', 26379)]);
        $calls = 0;

        $connector->expects($this->exactly(3))
            ->method('connectToSentinel')
            ->willReturnCallback(function () use (&$calls, $sentinel) {
                if (++$calls < 3) {
                    throw new RedisException('Connection refused');
                }

                return $sentinel;
            });

        $connection = $connector->connect($this->sentinelConfig(['sentinel_connect_max_retries' => 3, 'sentinel_connect_retry_delay' => 0]), []);

        self::assertTrue($connection->ping());
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function test_throws_after_max_retries_on_first_connect(): void
    {
        $connector = $this->getMockBuilder(PhpRedisSentinelConnector::class)
            ->onlyMethods(['connectToSentinel'])
            ->getMock();

        $connector->expects($this->exactly(2))
            ->method('connectToSentinel')
            ->willThrowException(new RedisException('Connection refused'));

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('Connection refused');

        $connector->connect($this->sentinelConfig(['sentinel_connect_max_retries' => '2', 'sentinel_connect_retry_delay' => '0']), []);
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function test_does_not_retry_first_connect_by_default(): void
    {
        $connector = $this->getMockBuilder(PhpRedisSentinelConnector::class)
            ->onlyMethods(['connectToSentinel'])
            ->getMock();

        $connector->expects($this->once())
            ->method('connectToSentinel')
            ->willThrowException(new RedisException('Connection refused'));

        $this->expectException(RedisException::class);

        $connector->connect($this->sentinelConfig(['sentinel_max_retries' => 5, 'sentinel_retry_delay' => 0]), []);
    }

    /**
     * @throws RedisException
     */
    public function test_throws_redis_exception_if_sentinel_host_cannot_be_resolved(): void
    {
        $this->expectException(RedisException::class);

        (new PhpRedisSentinelConnector())->connect($this->sentinelConfig([
            'sentinel_host' => 'no-such-host.invalid',
            'sentinel_connect_max_retries' => 2,
            'sentinel_connect_retry_delay' => 0,
        ]), []);
    }

    /**
     * @throws RedisException
     */
    public function test_connects_through_next_sentinel_host_if_previous_is_unavailable(): void
    {
        $connection = (new PhpRedisSentinelConnector())->connect($this->sentinelConfig([
            'sentinel_hosts' => [
                ['host' => 'no-such-host.invalid', 'port' => 26379],
                ['host' => '127.0.0.1', 'port' => 1],
                ['host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'), 'port' => env('REDIS_SENTINEL_PORT', 26379)],
            ],
        ]), []);

        self::assertTrue($connection->ping());
    }

    /**
     * @throws Exception
     * @throws RedisException
     */
    public function test_connects_through_next_sentinel_host_if_previous_has_no_master(): void
    {
        $connector = $this->getMockBuilder(PhpRedisSentinelConnector::class)
            ->onlyMethods(['connectToSentinel'])
            ->getMock();

        $sentinelWithoutMaster = $this->createStub(RedisSentinel::class);
        $sentinelWithoutMaster->method('master')->willReturn(false);

        $sentinel = new RedisSentinel(['host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_SENTINEL_PORT', 26379)]);

        $connector->expects($this->exactly(2))
            ->method('connectToSentinel')
            ->willReturnCallback(fn (array $config) => $config['sentinel_host'] === 'stale-sentinel' ? $sentinelWithoutMaster : $sentinel);

        $connection = $connector->connect($this->sentinelConfig([
            'sentinel_hosts' => [
                ['host' => 'stale-sentinel', 'port' => 26379],
                ['host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'), 'port' => env('REDIS_SENTINEL_PORT', 26379)],
            ],
        ]), []);

        self::assertTrue($connection->ping());
    }

    /**
     * @throws RedisException
     */
    public function test_throws_if_all_sentinel_hosts_are_unavailable(): void
    {
        $this->expectException(RedisException::class);

        (new PhpRedisSentinelConnector())->connect($this->sentinelConfig([
            'sentinel_hosts' => [
                ['host' => 'no-such-host.invalid', 'port' => 26379],
                ['host' => '127.0.0.1', 'port' => 1],
            ],
            'sentinel_connect_max_retries' => 2,
            'sentinel_connect_retry_delay' => 0,
        ]), []);
    }

    /**
     * @throws RedisException
     * @throws \Illuminate\Contracts\Redis\LimiterTimeoutException
     */
    public function test_throttle_reconnects_to_master_when_connected_to_replica(): void
    {
        $this->assertThrottleReconnectsFromReplica(env('REDIS_REPLICA_HOST', '127.0.0.1'), (int) env('REDIS_REPLICA_PORT', 6380));
    }

    /**
     * Redis < 7 wraps the READONLY error of a script into "ERR Error running script", so PhpRedis returns false.
     *
     * @throws RedisException
     * @throws \Illuminate\Contracts\Redis\LimiterTimeoutException
     */
    public function test_throttle_reconnects_to_master_when_connected_to_redis_6_replica(): void
    {
        $this->assertThrottleReconnectsFromReplica(env('REDIS_6_REPLICA_HOST', '127.0.0.1'), (int) env('REDIS_6_REPLICA_PORT', 6402));
    }

    /**
     * @throws RedisException
     */
    public function test_pipeline_is_retried_after_failover(): void
    {
        [$connection, $replica] = $this->connectionStuckOnReplica(env('REDIS_REPLICA_HOST', '127.0.0.1'), (int) env('REDIS_REPLICA_PORT', 6380));
        $calls = 0;

        $result = $connection->pipeline(function ($pipe) use (&$calls) {
            $calls++;
            $pipe->set('pipeline_after_failover', 'value');
            $pipe->get('pipeline_after_failover');
        });

        self::assertSame([true, 'value'], $result);
        self::assertSame(2, $calls);
        self::assertNotSame($replica, $connection->client()->getClient());
    }

    /**
     * @throws RedisException
     */
    public function test_transaction_is_retried_after_failover_on_redis_6(): void
    {
        [$connection, $replica] = $this->connectionStuckOnReplica(env('REDIS_6_REPLICA_HOST', '127.0.0.1'), (int) env('REDIS_6_REPLICA_PORT', 6402));
        $calls = 0;

        $result = $connection->transaction(function ($transaction) use (&$calls) {
            $calls++;
            $transaction->set('transaction_after_failover', 'value');
            $transaction->get('transaction_after_failover');
        });

        self::assertSame([true, 'value'], $result);
        self::assertSame(2, $calls);
        self::assertNotSame($replica, $connection->client()->getClient());
    }

    public function test_pipeline_is_not_retried_on_other_errors(): void
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');
        $calls = 0;

        try {
            $connection->pipeline(function () use (&$calls) {
                $calls++;

                throw new RedisException('Something unrelated');
            });
            self::fail('The exception should be rethrown');
        } catch (RedisException $e) {
            self::assertSame('Something unrelated', $e->getMessage());
        }

        self::assertSame(1, $calls);
    }

    public function test_transaction_fails_after_max_retries(): void
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');
        $calls = 0;

        try {
            $connection->transaction(function () use (&$calls) {
                $calls++;

                throw new RedisException('Connection lost');
            });
            self::fail('The exception should be rethrown');
        } catch (RedisException $e) {
            self::assertSame('Connection lost', $e->getMessage());
        }

        self::assertSame(3, $calls);
    }

    public function test_pipeline_without_callback_is_returned_as_is(): void
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');

        $pipeline = $connection->pipeline();
        $pipeline->set('pipeline_without_callback', 'value');

        self::assertSame([true], $pipeline->exec());
    }

    /**
     * @throws RedisException
     * @throws \Illuminate\Contracts\Redis\LimiterTimeoutException
     */
    private function assertThrottleReconnectsFromReplica(string $host, int $port): void
    {
        [$connection, $replica] = $this->connectionStuckOnReplica($host, $port);

        $result = $connection->throttle('throttle_after_failover')
            ->allow(10)
            ->every(60)
            ->then(fn () => 'acquired', fn () => 'limited');

        self::assertSame('acquired', $result);
        self::assertNotSame($replica, $connection->client()->getClient());
    }

    /**
     * Returns the default connection whose client is connected to a replica, as the old master after a failover.
     *
     * @return array{PhpRedisSentinelConnection, Redis}
     *
     * @throws RedisException
     */
    private function connectionStuckOnReplica(string $host, int $port): array
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');

        $replica = new Redis();
        $replica->connect($host, $port);
        self::assertSame('slave', $replica->info('replication')['role']);

        /** @var RedisWrapper $client */
        $client = $connection->client();
        $client->setClient($replica);

        return [$connection, $replica];
    }

    private function sentinelConfig(array $overrides = []): array
    {
        return array_merge([
            'sentinel_host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
            'sentinel_port' => (int) env('REDIS_SENTINEL_PORT', 26379),
            'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        ], $overrides);
    }

    /**
     * @throws ReflectionException
     */
    private function assertRedisWrapperReconnectsOnException(RedisException $exception, string $key): void
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');

        /** @var RedisWrapper $client */
        $client = $connection->client();
        self::assertInstanceOf(RedisWrapper::class, $client);

        $innerBefore = $client->getClient();

        $mock = $this->createStub(Redis::class);
        $mock->method('get')
            ->willThrowException($exception);

        $client->setClient($mock);

        try {
            $client->get($key);
        } catch (RedisException) {
            // intentionally ignored
        }

        $innerAfter = $client->getClient();

        self::assertNotSame($innerBefore, $innerAfter, 'Inner Redis client should be replaced after exception');
    }
}
