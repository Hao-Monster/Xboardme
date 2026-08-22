<?php

namespace App\WebSocket;

use App\WebSocket\Contracts\RedisSubscriberClient;
use App\WebSocket\Contracts\RedisSubscriberClientFactory;
use Throwable;

class NodePushSubscriber
{
    private ?RedisSubscriberClient $client = null;
    private bool $ready = false;
    private int $generation = 0;

    public function __construct(
        private readonly RedisConnectionConfig $config,
        private readonly RedisSubscriberClientFactory $factory,
        private readonly mixed $messageHandler,
    ) {
        if (!is_callable($messageHandler)) {
            throw new \InvalidArgumentException('Redis message handler must be callable.');
        }
    }

    public function start(): void
    {
        if ($this->client !== null) {
            return;
        }

        $generation = ++$this->generation;
        $this->ready = false;

        try {
            $this->client = $this->factory->connect(
                $this->config,
                function (bool $connected) use ($generation): void {
                    if ($generation !== $this->generation) {
                        return;
                    }
                    if (!$connected) {
                        $this->disconnect($generation);
                        return;
                    }

                    $this->authenticateAndSubscribe($generation);
                }
            );
        } catch (Throwable) {
            $this->disconnect($generation);
        }
    }

    public function ensureStarted(): void
    {
        if ($this->client === null) {
            $this->start();
        }
    }

    public function markUnavailable(): void
    {
        $this->disconnect($this->generation);
    }

    public function isReady(): bool
    {
        return $this->ready && $this->client?->isReady() === true;
    }

    private function authenticateAndSubscribe(int $generation): void
    {
        if ($this->client === null || $generation !== $this->generation) {
            return;
        }

        if ($this->config->auth === null) {
            $this->subscribe($generation);
            return;
        }

        try {
            $this->client->authenticate(
                $this->config->auth,
                function (bool $authenticated) use ($generation): void {
                    if (!$authenticated) {
                        $this->disconnect($generation);
                        return;
                    }

                    $this->subscribe($generation);
                }
            );
        } catch (Throwable) {
            $this->disconnect($generation);
        }
    }

    private function subscribe(int $generation): void
    {
        if ($this->client === null || $generation !== $this->generation) {
            return;
        }

        try {
            $this->client->subscribe(
                [$this->config->pushChannel, $this->config->replacementChannel],
                $this->messageHandler,
                function (bool $subscribed) use ($generation): void {
                    if ($generation !== $this->generation) {
                        return;
                    }
                    if (!$subscribed) {
                        $this->disconnect($generation);
                        return;
                    }
                    $this->ready = true;
                }
            );
        } catch (Throwable) {
            $this->disconnect($generation);
        }
    }

    private function disconnect(int $generation): void
    {
        if ($generation !== $this->generation) {
            return;
        }

        $client = $this->client;
        $this->client = null;
        $this->ready = false;
        ++$this->generation;

        try {
            $client?->close();
        } catch (Throwable) {
        }
    }
}
