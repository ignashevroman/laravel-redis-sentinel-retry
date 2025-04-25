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

        $sentinel = $this->createMock(RedisSentinel::class);
        $sentinel->method('master')->willReturn(null);

        $connector->method('connectToSentinel')->willReturn($sentinel);

        $this->expectException(RedisException::class);
        $this->expectExceptionMessageMatches('/No master found/');

        $connector->connect([
            'sentinel_service' => 'invalid',
            'sentinel_host' => '127.0.0.1',
        ], []);
    }

    public function test_throws_if_sentinel_host_is_empty(): void
    {
        $connector = new PhpRedisSentinelConnector();

        $this->expectException(ConfigurationException::class);
        $connector->connect([], []);
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

        $mock = $this->getMockBuilder(Redis::class)
            ->onlyMethods(['get'])
            ->getMock();

        $mock->expects($this->any())
            ->method('get')
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
