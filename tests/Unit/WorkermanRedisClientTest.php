<?php

namespace Tests\Unit;

use App\WebSocket\WorkermanRedisClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Redis\Client;

class WorkermanRedisClientTest extends TestCase
{
    public function test_subscription_is_ready_only_after_every_channel_is_acknowledged(): void
    {
        $client = (new ReflectionClass(WorkermanRedisClient::class))->newInstanceWithoutConstructor();
        $connectionProperty = new ReflectionProperty(Client::class, '_connection');
        $firstConnection = $this->createStub(AsyncTcpConnection::class);
        $firstConnection->method('getStatus')->willReturn('ESTABLISHED');
        $connectionProperty->setValue($client, $firstConnection);
        $ready = [];
        $messages = [];

        $client->subscribeWithAcknowledgement(
            ['node:push', 'node:connection-replaced'],
            static function (string $channel, string $message) use (&$messages): void {
                $messages[] = [$channel, $message];
            },
            static function (bool $subscribed) use (&$ready): void {
                $ready[] = $subscribed;
            }
        );

        $queue = (new ReflectionProperty(Client::class, '_queue'))->getValue($client);
        $callback = $queue[0][2];
        $callback(['subscribe', 'node:push', 1]);
        $this->assertSame([], $ready);

        $callback(['subscribe', 'node:connection-replaced', 2]);
        $this->assertSame([true], $ready);
        $this->assertTrue($client->subscriptionReady());

        $callback(['message', 'node:push', '{"event":"sync"}']);
        $this->assertSame([['node:push', '{"event":"sync"}']], $messages);

        $secondConnection = $this->createStub(AsyncTcpConnection::class);
        $secondConnection->method('getStatus')->willReturn('ESTABLISHED');
        $connectionProperty->setValue($client, $secondConnection);
        $this->assertFalse($client->subscriptionReady());

        $callback(['subscribe', 'node:push', 1]);
        $this->assertFalse($client->subscriptionReady());
        $callback(['subscribe', 'node:connection-replaced', 2]);
        $this->assertTrue($client->subscriptionReady());
    }
}
