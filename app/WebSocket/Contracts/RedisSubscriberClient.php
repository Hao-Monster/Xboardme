<?php

namespace App\WebSocket\Contracts;

interface RedisSubscriberClient
{
    /**
     * @param string|array{0:string,1:string} $auth
     */
    public function authenticate(string|array $auth, callable $callback): void;

    /**
     * @param string[] $channels
     */
    public function subscribe(array $channels, callable $messageCallback, callable $readyCallback): void;

    public function isReady(): bool;

    public function close(): void;
}
