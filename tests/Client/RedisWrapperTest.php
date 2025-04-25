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

        $mockConnection = $this->createMock(PhpRedisSentinelConnection::class);
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

        $mockRedis = $this->createMock(Redis::class);
        $mockRedis->method('get')->willThrowException(new RedisException('fail'));

        $mockConnection = $this->createMock(PhpRedisSentinelConnection::class);
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

        $mockConnection = $this->createMock(PhpRedisSentinelConnection::class);
        $mockConnection->method('handleFailover')->willReturn(false);

        $wrapper = new RedisWrapper($mockRedis, $mockConnection, maxRetries: 3, retryDelay: 0);
        $wrapper->get('foo');
    }

    /**
     * @throws Exception
     */
    public function test_set_client_replaces_underlying_client(): void
    {
        $originalClient = $this->createMock(Redis::class);
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
        $originalClient = $this->createMock(Redis::class);
        $newClient = $this->createMock(Redis::class);

        $wrapper = new RedisWrapper($originalClient);

        $this->assertSame($originalClient, $wrapper->getClient());

        $wrapper->setClient($newClient);
        $this->assertSame($newClient, $wrapper->getClient());
    }
}
