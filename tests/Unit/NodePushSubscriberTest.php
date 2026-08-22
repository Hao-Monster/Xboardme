<?php

namespace Tests\Unit;

use App\WebSocket\Contracts\RedisSubscriberClient;
use App\WebSocket\Contracts\RedisSubscriberClientFactory;
use App\WebSocket\NodePushSubscriber;
use App\WebSocket\RedisConnectionConfig;
use PHPUnit\Framework\TestCase;

class NodePushSubscriberTest extends TestCase
{
    public function test_connection_without_auth_subscribes_and_becomes_ready(): void
    {
        $client = new FakeRedisSubscriberClient();
        $factory = new FakeRedisSubscriberClientFactory($client);
        $messages = [];
        $subscriber = new NodePushSubscriber(
            RedisConnectionConfig::fromArray(['host' => '/data/redis.sock']),
            $factory,
            static function (string $channel, string $message) use (&$messages): void {
                $messages[] = [$channel, $message];
            }
        );

        $subscriber->start();
        $factory->completeConnection(true);

        $this->assertFalse($subscriber->isReady());
        $client->completeSubscription(true);
        $this->assertTrue($subscriber->isReady());
        $this->assertSame(['node:push', 'node:connection-replaced'], $client->channels);

        $client->emit('node:push', '{"event":"sync"}');
        $this->assertSame([['node:push', '{"event":"sync"}']], $messages);
    }

    public function test_acl_authentication_completes_before_subscription(): void
    {
        $client = new FakeRedisSubscriberClient();
        $factory = new FakeRedisSubscriberClientFactory($client);
        $subscriber = new NodePushSubscriber(
            RedisConnectionConfig::fromArray([
                'host' => 'redis-infra',
                'username' => 'xboard_ws',
                'password' => 'secret',
            ]),
            $factory,
            static function (): void {
            }
        );

        $subscriber->start();
        $factory->completeConnection(true);

        $this->assertFalse($subscriber->isReady());
        $this->assertSame(['xboard_ws', 'secret'], $client->auth);
        $this->assertSame([], $client->channels);

        $client->completeAuth(true);

        $this->assertFalse($subscriber->isReady());
        $client->completeSubscription(true);
        $this->assertTrue($subscriber->isReady());
        $this->assertSame(['node:push', 'node:connection-replaced'], $client->channels);
    }

    public function test_failed_connection_or_authentication_stays_unready_and_can_retry(): void
    {
        $first = new FakeRedisSubscriberClient();
        $second = new FakeRedisSubscriberClient();
        $factory = new FakeRedisSubscriberClientFactory($first, $second);
        $subscriber = new NodePushSubscriber(
            RedisConnectionConfig::fromArray(['host' => 'redis-infra', 'password' => 'secret']),
            $factory,
            static function (): void {
            }
        );

        $subscriber->start();
        $factory->completeConnection(false);
        $this->assertFalse($subscriber->isReady());
        $this->assertTrue($first->closed);

        $subscriber->ensureStarted();
        $factory->completeConnection(true);
        $second->completeAuth(false);

        $this->assertFalse($subscriber->isReady());
        $this->assertTrue($second->closed);
    }

    public function test_failed_subscription_acknowledgement_is_closed_and_retried(): void
    {
        $first = new FakeRedisSubscriberClient();
        $second = new FakeRedisSubscriberClient();
        $factory = new FakeRedisSubscriberClientFactory($first, $second);
        $subscriber = new NodePushSubscriber(
            RedisConnectionConfig::fromArray(['host' => 'redis-infra']),
            $factory,
            static function (): void {
            }
        );

        $subscriber->start();
        $factory->completeConnection(true);
        $first->completeSubscription(false);
        $this->assertFalse($subscriber->isReady());
        $this->assertTrue($first->closed);

        $subscriber->ensureStarted();
        $factory->completeConnection(true);
        $second->completeSubscription(true);
        $this->assertTrue($subscriber->isReady());
    }

    public function test_synchronous_authentication_error_closes_the_partial_client(): void
    {
        $client = new FakeRedisSubscriberClient();
        $client->throwOnAuthenticate = true;
        $factory = new FakeRedisSubscriberClientFactory($client);
        $subscriber = new NodePushSubscriber(
            RedisConnectionConfig::fromArray(['host' => 'redis-infra', 'password' => 'secret']),
            $factory,
            static function (): void {
            }
        );

        $subscriber->start();
        $factory->completeConnection(true);

        $this->assertFalse($subscriber->isReady());
        $this->assertTrue($client->closed);
    }
}

class FakeRedisSubscriberClient implements RedisSubscriberClient
{
    public string|array|null $auth = null;
    public array $channels = [];
    public bool $closed = false;
    public bool $throwOnAuthenticate = false;
    private $authCallback = null;
    private $messageCallback = null;
    private $subscriptionCallback = null;
    private bool $ready = false;

    public function authenticate(string|array $auth, callable $callback): void
    {
        if ($this->throwOnAuthenticate) {
            throw new \RuntimeException('Synthetic authentication failure.');
        }
        $this->auth = $auth;
        $this->authCallback = $callback;
    }

    public function subscribe(array $channels, callable $messageCallback, callable $readyCallback): void
    {
        $this->channels = $channels;
        $this->messageCallback = $messageCallback;
        $this->subscriptionCallback = $readyCallback;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->ready = false;
    }

    public function completeAuth(bool $success): void
    {
        ($this->authCallback)($success);
    }

    public function emit(string $channel, string $message): void
    {
        ($this->messageCallback)($channel, $message);
    }

    public function completeSubscription(bool $success): void
    {
        $this->ready = $success;
        ($this->subscriptionCallback)($success);
    }

    public function isReady(): bool
    {
        return $this->ready;
    }
}

class FakeRedisSubscriberClientFactory implements RedisSubscriberClientFactory
{
    private array $clients;
    private $connectionCallback = null;

    public function __construct(FakeRedisSubscriberClient ...$clients)
    {
        $this->clients = $clients;
    }

    public function connect(RedisConnectionConfig $config, callable $callback): RedisSubscriberClient
    {
        $this->connectionCallback = $callback;

        return array_shift($this->clients);
    }

    public function completeConnection(bool $success): void
    {
        ($this->connectionCallback)($success);
    }
}
