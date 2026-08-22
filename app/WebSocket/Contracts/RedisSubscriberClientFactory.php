<?php

namespace App\WebSocket\Contracts;

use App\WebSocket\RedisConnectionConfig;

interface RedisSubscriberClientFactory
{
    public function connect(RedisConnectionConfig $config, callable $callback): RedisSubscriberClient;
}
