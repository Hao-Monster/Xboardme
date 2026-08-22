<?php

namespace App\WebSocket;

use App\WebSocket\Contracts\RedisSubscriberClient;
use App\WebSocket\Contracts\RedisSubscriberClientFactory;

final class WorkermanRedisSubscriberClientFactory implements RedisSubscriberClientFactory
{
    public function connect(RedisConnectionConfig $config, callable $callback): RedisSubscriberClient
    {
        $client = new WorkermanRedisClient(
            $config->uri,
            ['connect_timeout' => 5, 'wait_timeout' => 30],
            static function ($success) use ($callback): void {
                $callback($success === true);
            }
        );

        return new WorkermanRedisSubscriberClient($client);
    }
}
