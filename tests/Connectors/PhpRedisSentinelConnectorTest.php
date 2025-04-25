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
use Ignashevroman\Redis\Sentinel\Tests\TestCase;
use Redis;
use RedisException;
use ReflectionClass;
use ReflectionException;

/**
 * Ensures that the {@see PhpRedisSentinelConnection} and {@see RedisWrapper} function properly.
 */
class PhpRedisSentinelConnectorTest extends TestCase
{
    public function test_connecting_to_redis_through_sentinel_without_password_works(): void
    {
        /** @var PhpRedisSentinelConnection $connection */
        $connection = app('redis')->connection('default');

        self::assertTrue($connection->ping());
    }

    public function test_wrapper_retries_on_transient_failure(): void
    {
        $this->assertRedisWrapperReconnectsOnException(
            new RedisException("READONLY You can't write against a read only replica."),
            'some_key'
        );
    }

    public function test_wrapper_retries_on_connection_lost(): void
    {
        $this->assertRedisWrapperReconnectsOnException(
            new RedisException('Connection lost.'),
            'another_key'
        );
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

        $innerBefore = $this->getInnerClientId($client);

        // Подменим клиент на мок, который бросает исключение
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

        $innerAfter = $this->getInnerClientId($connection->client());

        self::assertNotSame($innerBefore, $innerAfter, 'Inner Redis client should be replaced after exception');
    }

    /**
     * @throws ReflectionException
     */
    private function getInnerClientId(RedisWrapper $client): string
    {
        $property = (new ReflectionClass($client))->getProperty('client');

        return spl_object_hash($property->getValue($client));
    }
}
