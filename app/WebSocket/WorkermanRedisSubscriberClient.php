<?php

namespace App\WebSocket;

use App\WebSocket\Contracts\RedisSubscriberClient;

final class WorkermanRedisSubscriberClient implements RedisSubscriberClient
{
    public function __construct(private readonly WorkermanRedisClient $client)
    {
    }

    public function authenticate(string|array $auth, callable $callback): void
    {
        $this->client->authenticate($auth, $callback);
    }

    public function subscribe(array $channels, callable $messageCallback, callable $readyCallback): void
    {
        $this->client->subscribeWithAcknowledgement($channels, $messageCallback, $readyCallback);
    }

    public function close(): void
    {
        $this->client->close();
    }

    public function isReady(): bool
    {
        return $this->client->subscriptionReady();
    }
}
