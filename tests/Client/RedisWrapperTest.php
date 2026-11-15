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

namespace Ignashevroman\Redis\Sentinel\Tests\Client;

use Ignashevroman\Redis\Sentinel\Client\RedisWrapper;
use Ignashevroman\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

class RedisWrapperTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_delegates_method_call_to_client()
    {
        $mockRedis = $this->createMock(Redis::class);
        $mockRedis->expects($this->once())
            ->method('get')
            ->with('foo')
            ->willReturn('bar');

        $wrapper = new RedisWrapper($mockRedis);

        $this->assertSame('bar', $wrapper->get('foo'));
    }

    /**
     * @throws Exception
     */
    public function test_retries_on_failure_and_succeeds()
    {
        $mockRedis = $this->getMockBuilder(Redis::class)
            ->onlyMethods(['get'])
            ->getMock();

        $mockRedis->expects($this->exactly(2))
            ->method('get')
            ->with('foo')
            ->willReturn(
                $this->throwException(new RedisException('fail')),
                'recovered'
            );

        $mockConnection = $this->createStub(PhpRedisSentinelConnection::class);
        $mockConnection->method('handleFailover')->willReturn(true);

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);
        $this->assertSame('recovered', $wrapper->get('foo'));
    }

    /**
     * @throws Exception
     */
    public function test_fails_after_max_retries()
    {
        $this->expectException(RedisException::class);

        $mockRedis = $this->createStub(Redis::class);
        $mockRedis->method('get')->willThrowException(new RedisException('fail'));

        $mockConnection = $this->createStub(PhpRedisSentinelConnection::class);
        $mockConnection->method('handleFailover')->willReturn(true);

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);
        $wrapper->get('foo');
    }

    /**
     * @throws Exception
     */
    public function test_does_not_retry_if_handleFailover_returns_false()
    {
        $this->expectException(RedisException::class);

        $mockRedis = $this->createMock(Redis::class);
        $mockRedis->expects($this->once())->method('get')->willThrowException(new RedisException('fail'));

        $mockConnection = $this->createStub(PhpRedisSentinelConnection::class);
        $mockConnection->method('handleFailover')->willReturn(false);

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);
        $wrapper->get('foo');
    }

    /**
     * @throws Exception
     */
    public function test_retries_when_false_is_returned_with_unavailability_error(): void
    {
        $staleClient = $this->createMock(Redis::class);
        $staleClient->expects($this->once())->method('eval')->willReturn(false);
        $staleClient->method('getLastError')->willReturn('LOADING Redis is loading the dataset in memory');

        $freshClient = $this->createMock(Redis::class);
        $freshClient->expects($this->once())->method('eval')->willReturn([1, 2, 3]);

        $wrapper = new RedisWrapper($staleClient, maxRetries: 3, retryDelay: 0);

        $connection = new PhpRedisSentinelConnection($wrapper, function () use ($wrapper, $freshClient) {
            $wrapper->setClient($freshClient);

            return $wrapper;
        }, []);
        $wrapper->setConnection($connection);

        $this->assertSame([1, 2, 3], $wrapper->eval('return {1, 2, 3}', [], 0));
        $this->assertSame($freshClient, $wrapper->getClient());
    }

    /**
     * @throws Exception
     */
    public function test_returns_false_when_last_error_does_not_indicate_unavailability(): void
    {
        $mockRedis = $this->createMock(Redis::class);
        $mockRedis->expects($this->once())->method('get')->willReturn(false);
        $mockRedis->method('getLastError')->willReturn('WRONGTYPE Operation against a key holding the wrong kind of value');

        $mockConnection = $this->createMock(PhpRedisSentinelConnection::class);
        $mockConnection->method('isUnavailabilityError')->willReturn(false);
        $mockConnection->expects($this->never())->method('handleFailover');

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);

        $this->assertFalse($wrapper->get('foo'));
    }

    /**
     * @throws Exception
     */
    public function test_returns_false_when_there_is_no_last_error(): void
    {
        $mockRedis = $this->createMock(Redis::class);
        $mockRedis->expects($this->once())->method('get')->willReturn(false);
        $mockRedis->method('getLastError')->willReturn(null);

        $mockConnection = $this->createMock(PhpRedisSentinelConnection::class);
        $mockConnection->expects($this->never())->method('isUnavailabilityError');
        $mockConnection->expects($this->never())->method('handleFailover');

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);

        $this->assertFalse($wrapper->get('missing'));
    }

    /**
     * @throws Exception
     */
    public function test_fails_after_max_retries_when_false_is_returned_with_unavailability_error(): void
    {
        $mockRedis = $this->createMock(Redis::class);
        $mockRedis->expects($this->exactly(3))->method('get')->willReturn(false);
        $mockRedis->method('getLastError')->willReturn('Connection lost');

        $mockConnection = $this->createStub(PhpRedisSentinelConnection::class);
        $mockConnection->method('isUnavailabilityError')->willReturn(true);
        $mockConnection->method('handleFailover')->willReturn(true);

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);

        $this->expectException(RedisException::class);
        $wrapper->get('foo');
    }

    /**
     * @throws Exception
     */
    public function test_set_client_replaces_underlying_client(): void
    {
        $originalClient = $this->createStub(Redis::class);
        $newClient = $this->createMock(Redis::class);

        $wrapper = new RedisWrapper($originalClient);
        $wrapper->setClient($newClient);

        $newClient->expects($this->once())
            ->method('get')
            ->with('foo')
            ->willReturn('bar');

        $this->assertSame('bar', $wrapper->get('foo'));
    }

    /**
     * @throws Exception
     */
    public function test_set_connection_replaces_internal_reference(): void
    {
        $mockClient = $this->createMock(Redis::class);

        $mockClient->expects($this->once())
            ->method('get')
            ->willThrowException(new RedisException('fail'));

        $originalConnection = $this->createMock(PhpRedisSentinelConnection::class);
        $originalConnection->expects($this->never())->method('handleFailover');

        $replacementConnection = $this->createMock(PhpRedisSentinelConnection::class);
        $replacementConnection->expects($this->once())->method('handleFailover')->willReturn(false);

        $wrapper = new RedisWrapper($mockClient, $originalConnection, maxRetries: 1, retryDelay: 0);
        $wrapper->setConnection($replacementConnection);

        $this->expectException(RedisException::class);
        $wrapper->get('foo');
    }

    /**
     * @throws Exception
     */
    public function test_getClient_returns_the_current_client(): void
    {
        $originalClient = $this->createStub(Redis::class);
        $newClient = $this->createStub(Redis::class);

        $wrapper = new RedisWrapper($originalClient);

        $this->assertSame($originalClient, $wrapper->getClient());

        $wrapper->setClient($newClient);
        $this->assertSame($newClient, $wrapper->getClient());
    }
}
