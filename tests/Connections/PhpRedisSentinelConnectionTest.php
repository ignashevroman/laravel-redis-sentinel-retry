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

namespace Ignashevroman\Redis\Sentinel\Tests\Connections;

use Ignashevroman\Redis\Sentinel\Connections\PhpRedisSentinelConnection;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;

class PhpRedisSentinelConnectionTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_handle_failover_returns_true_when_exception_message_matches(): void
    {
        $clientBefore = $this->createMock(Redis::class);
        $clientAfter = $this->createMock(Redis::class);

        $called = false;

        $connection = new PhpRedisSentinelConnection($clientBefore, function () use (&$called, $clientAfter) {
            $called = true;

            return $clientAfter;
        }, []);

        $result = $connection->handleFailover(new RedisException('Connection lost'));

        $this->assertTrue($result);
        $this->assertTrue($called);
    }

    /**
     * @throws Exception
     */
    public function test_handle_failover_returns_false_when_exception_message_does_not_match(): void
    {
        $client = $this->createMock(Redis::class);

        $connection = new PhpRedisSentinelConnection($client, function () {
            $this->fail('Connector should not be called');
        }, []);

        $result = $connection->handleFailover(new RedisException('Something unrelated'));

        $this->assertFalse($result);
    }

    /**
     * @throws Exception
     */
    public function test_set_client_replaces_client(): void
    {
        $client1 = $this->createMock(Redis::class);
        $client2 = $this->createMock(Redis::class);

        $connection = new PhpRedisSentinelConnection($client1, null, []);

        $this->assertSame($client1, $connection->client());

        $connection->setClient($client2);

        $this->assertSame($client2, $connection->client());
    }
}
