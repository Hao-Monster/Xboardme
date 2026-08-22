<?php

namespace App\WebSocket;

use Workerman\Redis\Client;

/**
 * Typed boundary around workerman/redis's callback API. The upstream client
 * intentionally hides SUBSCRIBE acknowledgements, but readiness must only be
 * published after Redis confirms every requested channel.
 */
final class WorkermanRedisClient extends Client
{
    private ?int $acknowledgedConnectionId = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $address, array $options, callable $connectionCallback)
    {
        parent::__construct($address, $options);
        $this->_connectionCallback = $connectionCallback;
    }

    /**
     * @param string|array{0:string,1:string} $auth
     */
    public function authenticate(string|array $auth, callable $callback): void
    {
        $format = function (mixed $result) use ($auth): mixed {
            $this->_auth = $auth;

            return $result;
        };
        $this->_queue[] = [
            ['AUTH', $auth],
            time(),
            static function (mixed $result) use ($callback): void {
                $callback($result === true);
            },
            $format,
        ];
        $this->process();
    }

    /**
     * @param string[] $channels
     */
    public function subscribeWithAcknowledgement(
        array $channels,
        callable $messageCallback,
        callable $readyCallback
    ): void {
        $pendingChannels = array_fill_keys($channels, true);
        $observedConnectionId = null;
        $callback = function (mixed $result) use (
            $channels,
            &$pendingChannels,
            &$observedConnectionId,
            $messageCallback,
            $readyCallback
        ): void {
            if (!is_array($result) || !isset($result[0])) {
                $readyCallback(false);
                return;
            }

            if ($result[0] === 'subscribe') {
                $connectionId = $this->_connection === null ? null : spl_object_id($this->_connection);
                if ($observedConnectionId !== $connectionId) {
                    $pendingChannels = array_fill_keys($channels, true);
                    $observedConnectionId = $connectionId;
                }
                unset($pendingChannels[(string) ($result[1] ?? '')]);
                if ($pendingChannels === []) {
                    $this->acknowledgedConnectionId = $connectionId;
                    $readyCallback(true);
                }
                return;
            }

            if ($result[0] === 'message') {
                $messageCallback((string) ($result[1] ?? ''), (string) ($result[2] ?? ''));
                return;
            }

            $readyCallback(false);
        };

        $this->_queue[] = [['SUBSCRIBE', $channels], time(), $callback];
        $this->process();
    }

    public function subscriptionReady(): bool
    {
        return $this->_connection !== null
            && $this->_connection->getStatus(false) === 'ESTABLISHED'
            && $this->acknowledgedConnectionId === spl_object_id($this->_connection);
    }
}
