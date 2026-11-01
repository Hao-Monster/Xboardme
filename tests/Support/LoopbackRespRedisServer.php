<?php

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Connection as ConnectionContract;
use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Arr;
use RuntimeException;

final class LoopbackRespRedisServer
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $host = '127.0.0.1';

    private int $port = 0;

    private string $password;

    public function __construct(string $password = '')
    {
        $this->password = $password;
    }

    public function start(string $bindAddress = '127.0.0.1:0'): void
    {
        if ($this->process !== null) {
            return;
        }

        $rootPath = dirname(__DIR__, 2);
        $daemonPath = $rootPath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'loopback-resp-redis-daemon.php';
        $cmd = [PHP_BINARY, $daemonPath, $bindAddress];
        if ($this->password !== '') {
            $cmd[] = $this->password;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, $rootPath);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to spawn loopback RESP Redis test daemon.');
        }

        stream_set_timeout($pipes[1], 5);
        $readyLine = fgets($pipes[1]);
        if (!is_string($readyLine) || !str_starts_with(trim($readyLine), 'READY ')) {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            proc_terminate($proc);
            proc_close($proc);
            throw new RuntimeException("Loopback RESP daemon failed to start: {$stderr}");
        }

        $bound = trim(substr(trim($readyLine), 6));
        $lastColon = strrpos($bound, ':');
        if ($lastColon === false) {
            proc_terminate($proc);
            proc_close($proc);
            throw new RuntimeException("Unexpected READY address: {$bound}");
        }

        $this->host = substr($bound, 0, $lastColon);
        $this->port = (int) substr($bound, $lastColon + 1);
        $this->process = $proc;
        $this->pipes = $pipes;
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        try {
            $this->controlCommand(['XTEST_SHUTDOWN']);
        } catch (\Throwable) {
            // Ignore shutdown command failure if already terminated.
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = null;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function configureApplication(
        Application $app,
        string $prefix = 'xboard_test_database_',
        ?string $clientDriver = null
    ): void {
        $config = $app['config'];
        $resolvedDriver = $clientDriver ?? (extension_loaded('redis') ? 'phpredis' : 'loopback-resp');
        $config->set('database.redis.client', $resolvedDriver);
        $config->set('database.redis.options.prefix', $prefix);

        foreach (['default', 'cache', 'hwid_observation_publish'] as $connName) {
            $config->set("database.redis.{$connName}.url", null);
            $config->set("database.redis.{$connName}.host", $this->host);
            $config->set("database.redis.{$connName}.port", $this->port);
            $config->set("database.redis.{$connName}.password", $this->password !== '' ? $this->password : null);
            $config->set("database.redis.{$connName}.username", null);
        }

        $config->set('database.redis.default.database', 0);
        $config->set('database.redis.hwid_observation_publish.database', 0);
        $config->set('database.redis.cache.database', 1);

        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            $config->set('horizon.prefix', rtrim($prefix, '_') . '_horizon:');
            $config->set('horizon.environments.testing.hwid-observation', [
                'connection' => 'hwid_observation_worker', 'queue' => ['hwid_observation'],
                'balance' => 'simple', 'minProcesses' => 1, 'maxProcesses' => 1, 'timeout' => 45,
            ]);
            \Laravel\Horizon\Horizon::use((string) $config->get('horizon.use', 'default'));
        }

        $this->rebindRedisManager($app);
    }

    public static function rebindRedisManager(Application $app): void
    {
        $redisConfig = (array) $app['config']->get('database.redis', []);
        $driver = (string) ($redisConfig['client'] ?? 'loopback-resp');

        $manager = new \Illuminate\Redis\RedisManager($app, $driver, $redisConfig);
        $manager->extend('loopback-resp', fn (): Connector => new LoopbackRespRedisConnector());

        $app->instance('redis', $manager);
        $app->forgetInstance('redis.connection');
        if ($app->resolved('cache')) {
            $app['cache']->forgetDriver('redis');
        }
        $app->forgetInstance('queue');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('redis');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('queue');
        if (class_exists(\Laravel\Horizon\Contracts\JobRepository::class)) {
            $app->forgetInstance(\Laravel\Horizon\Contracts\JobRepository::class);
            $app->forgetInstance(\Laravel\Horizon\Contracts\TagRepository::class);
        }
    }

    public function resetCommandLog(): void
    {
        $this->controlCommand(['XTEST_CMDLOG_RESET']);
    }

    /**
     * @return list<string>
     */
    public function commandLog(): array
    {
        $res = $this->controlCommand(['XTEST_CMDLOG_GET']);

        return is_array($res) ? array_values(array_map('strval', $res)) : [];
    }

    public function setFaultMode(string $mode, int $sleepMs = 0): void
    {
        $this->controlCommand(['XTEST_SET_FAULT', $mode, (string) $sleepMs]);
    }

    public function flushAll(): void
    {
        $this->controlCommand(['FLUSHALL']);
    }

    /**
     * @param list<string> $args
     */
    public function controlCommand(array $args, int $db = 0): mixed
    {
        $client = new LoopbackRespSocketClient(
            host: $this->host,
            port: $this->port,
            connectTimeout: 1.0,
            readTimeout: 2.0,
            password: $this->password !== '' ? $this->password : null,
            database: $db,
            prefix: ''
        );
        try {
            return $client->rawExecute($args);
        } finally {
            $client->disconnect();
        }
    }
}

final class LoopbackRespRedisConnector implements Connector
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    public function connect(array $config, array $options): LoopbackRespRedisConnection
    {
        $mergedOptions = array_merge($options, Arr::pull($config, 'options', []));
        $prefix = array_key_exists('prefix', $config) && $config['prefix'] !== null
            ? (string) $config['prefix']
            : (string) ($mergedOptions['prefix'] ?? '');

        $connectTimeout = isset($config['timeout']) ? (float) $config['timeout'] : 2.0;
        $readTimeout = isset($config['read_timeout'])
            ? (float) $config['read_timeout']
            : (isset($config['read_write_timeout']) ? (float) $config['read_write_timeout'] : 2.0);

        $client = new LoopbackRespSocketClient(
            host: (string) ($config['host'] ?? '127.0.0.1'),
            port: (int) ($config['port'] ?? 6379),
            connectTimeout: $connectTimeout > 0 ? $connectTimeout : 2.0,
            readTimeout: $readTimeout > 0 ? $readTimeout : 2.0,
            password: !empty($config['password']) ? (string) $config['password'] : null,
            database: (int) ($config['database'] ?? 0),
            prefix: $prefix
        );

        return new LoopbackRespRedisConnection($client);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $clusterOptions
     * @param array<string, mixed> $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): mixed
    {
        throw new RuntimeException('Cluster connections are not used in loopback tests.');
    }
}

final class LoopbackRespRedisConnection extends Connection implements ConnectionContract
{
    public function __construct(LoopbackRespSocketClient $client)
    {
        $this->client = $client;
    }

    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        throw new RuntimeException('Pub/Sub is not required in subscription download tests.');
    }

    /**
     * @param array<int, string> $keys
     * @return array<int, string|null>
     */
    public function mget(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        /** @var array<int, string|null> $result */
        $result = $this->command('mget', [$keys]);

        return $result;
    }

    public function eval($script, $numberOfKeys, ...$arguments): mixed
    {
        return $this->command('eval', [$script, (int) $numberOfKeys, ...$arguments]);
    }

    public function transaction(?callable $callback = null): mixed
    {
        if ($callback === null) {
            $this->command('multi');
            return $this;
        }

        $this->command('multi');
        try {
            $callback($this);
            return $this->command('exec');
        } catch (\Throwable $e) {
            try {
                $this->command('discard');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function pipeline(?callable $callback = null): mixed
    {
        return $this->transaction($callback);
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }
}

final class LoopbackRespSocketClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $connectTimeout,
        private readonly float $readTimeout,
        private readonly ?string $password,
        private readonly int $database,
        private readonly string $prefix
    ) {
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $cmd = strtoupper($method);

        switch ($cmd) {
            case 'GET':
            case 'INCR':
            case 'DECR':
            case 'LLEN':
            case 'LPOP':
            case 'ZCARD':
            case 'HGETALL':
            case 'SMEMBERS':
            case 'SCARD':
                return $this->rawExecute([$cmd, $this->prefixed((string) $arguments[0])]);

            case 'LINDEX':
            case 'EXPIRE':
            case 'EXPIREAT':
            case 'PEXPIRE':
            case 'INCRBY':
            case 'DECRBY':
            case 'HGET':
                return $this->rawExecute([$cmd, $this->prefixed((string) $arguments[0]), (string) $arguments[1]]);

            case 'HINCRBY':
            case 'HINCRBYFLOAT':
                return $this->rawExecute([
                    $cmd,
                    $this->prefixed((string) $arguments[0]),
                    (string) $arguments[1],
                    (string) $arguments[2],
                ]);

            case 'HMGET':
                $wire = ['HMGET', $this->prefixed((string) $arguments[0])];
                $fields = isset($arguments[1]) && is_array($arguments[1]) ? $arguments[1] : array_slice($arguments, 1);
                foreach ($fields as $field) {
                    $wire[] = (string) $field;
                }
                return $this->rawExecute($wire);

            case 'LRANGE':
            case 'ZRANGE':
            case 'ZREVRANGE':
            case 'ZREMRANGEBYRANK':
            case 'ZREMRANGEBYSCORE':
                return $this->rawExecute([
                    $cmd,
                    $this->prefixed((string) $arguments[0]),
                    (string) $arguments[1],
                    (string) $arguments[2],
                ]);

            case 'ZRANGEBYSCORE':
            case 'ZREVRANGEBYSCORE':
                $wire = [
                    $cmd,
                    $this->prefixed((string) $arguments[0]),
                    (string) $arguments[1],
                    (string) $arguments[2],
                ];
                if (isset($arguments[3]) && is_array($arguments[3]) && isset($arguments[3]['limit'])) {
                    $limit = $arguments[3]['limit'];
                    $wire[] = 'LIMIT';
                    $wire[] = (string) ($limit[0] ?? 0);
                    $wire[] = (string) ($limit[1] ?? -1);
                } else {
                    foreach (array_slice($arguments, 3) as $extra) {
                        $wire[] = (string) $extra;
                    }
                }
                return $this->rawExecute($wire);

            case 'SETEX':
                return $this->rawExecute([
                    'SETEX',
                    $this->prefixed((string) $arguments[0]),
                    (string) $arguments[1],
                    (string) $arguments[2],
                ]);

            case 'SET':
                $wire = ['SET', $this->prefixed((string) $arguments[0]), (string) $arguments[1]];
                foreach (array_slice($arguments, 2) as $opt) {
                    if (is_array($opt)) {
                        foreach ($opt as $k => $v) {
                            if (is_int($k) && $v !== null) {
                                $wire[] = (string) $v;
                            } elseif (is_string($k) && $v !== null) {
                                $wire[] = strtoupper($k);
                                $wire[] = (string) $v;
                            }
                        }
                    } elseif ($opt !== null) {
                        $wire[] = (string) $opt;
                    }
                }
                return $this->rawExecute($wire);

            case 'MGET':
                $keys = isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : $arguments;
                $wire = ['MGET'];
                foreach ($keys as $key) {
                    $wire[] = $this->prefixed((string) $key);
                }
                return $this->rawExecute($wire);

            case 'DEL':
            case 'EXISTS':
                $keys = isset($arguments[0]) && is_array($arguments[0]) ? $arguments[0] : $arguments;
                $wire = [$cmd];
                foreach ($keys as $key) {
                    $wire[] = $this->prefixed((string) $key);
                }
                return $this->rawExecute($wire);

            case 'RPUSH':
            case 'SADD':
            case 'SREM':
            case 'ZREM':
            case 'HDEL':
                $wire = [$cmd, $this->prefixed((string) $arguments[0])];
                foreach (array_slice($arguments, 1) as $val) {
                    if (is_array($val)) {
                        foreach ($val as $item) {
                            $wire[] = (string) $item;
                        }
                    } else {
                        $wire[] = (string) $val;
                    }
                }
                return $this->rawExecute($wire);

            case 'ZADD':
                $wire = ['ZADD', $this->prefixed((string) $arguments[0])];
                if (isset($arguments[1]) && is_array($arguments[1])) {
                    foreach ($arguments[1] as $member => $score) {
                        $wire[] = (string) $score;
                        $wire[] = (string) $member;
                    }
                } else {
                    foreach (array_slice($arguments, 1) as $item) {
                        $wire[] = (string) $item;
                    }
                }
                return $this->rawExecute($wire);

            case 'HSET':
            case 'HMSET':
                $wire = [$cmd, $this->prefixed((string) $arguments[0])];
                if (isset($arguments[1]) && is_array($arguments[1])) {
                    foreach ($arguments[1] as $field => $val) {
                        $wire[] = (string) $field;
                        $wire[] = (string) $val;
                    }
                } else {
                    foreach (array_slice($arguments, 1) as $item) {
                        $wire[] = (string) $item;
                    }
                }
                return $this->rawExecute($wire);

            case 'KEYS':
                $pattern = $this->prefixed((string) ($arguments[0] ?? '*'));
                $res = $this->rawExecute(['KEYS', $pattern]);
                if (!is_array($res) || $this->prefix === '') {
                    return $res;
                }
                $prefixLen = strlen($this->prefix);
                return array_map(
                    fn (string $k): string => str_starts_with($k, $this->prefix) ? substr($k, $prefixLen) : $k,
                    $res
                );

            case 'EVAL':
                $script = (string) $arguments[0];
                $numKeys = (int) $arguments[1];
                $rest = array_slice($arguments, 2);
                $wire = ['EVAL', $script, (string) $numKeys];
                foreach ($rest as $idx => $arg) {
                    $wire[] = $idx < $numKeys ? $this->prefixed((string) $arg) : (string) $arg;
                }
                return $this->rawExecute($wire);

            case 'MULTI':
            case 'EXEC':
            case 'DISCARD':
            case 'FLUSHDB':
            case 'FLUSHALL':
            case 'PING':
                $wire = [$cmd];
                foreach ($arguments as $arg) {
                    $wire[] = (string) $arg;
                }
                return $this->rawExecute($wire);

            default:
                $wire = [$cmd];
                foreach ($arguments as $arg) {
                    $wire[] = (string) $arg;
                }
                return $this->rawExecute($wire);
        }
    }

    /**
     * @param array<int, string> $args
     */
    public function rawExecute(array $args): mixed
    {
        $sock = $this->ensureConnected();

        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $s = (string) $arg;
            $payload .= '$' . strlen($s) . "\r\n" . $s . "\r\n";
        }

        $totalLen = strlen($payload);
        $written = 0;
        while ($written < $totalLen) {
            $chunk = @fwrite($sock, substr($payload, $written));
            if ($chunk === false || $chunk === 0) {
                $this->disconnect();
                throw new RuntimeException('Redis socket write failed before command completion.');
            }
            $written += $chunk;
        }

        try {
            return $this->readReply($sock);
        } catch (\Throwable $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * @return resource
     */
    private function ensureConnected()
    {
        if (is_resource($this->socket) && !feof($this->socket)) {
            return $this->socket;
        }

        $this->disconnect();

        $sock = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($sock)) {
            throw new RuntimeException("Redis connection failed [{$errno}]: {$errstr}");
        }

        $sec = (int) floor($this->readTimeout);
        $usec = (int) round(($this->readTimeout - $sec) * 1_000_000);
        if ($sec === 0 && $usec <= 0) {
            $usec = 100_000;
        }
        stream_set_timeout($sock, $sec, $usec);
        $this->socket = $sock;

        if ($this->password !== null && $this->password !== '') {
            $this->rawHandshake($sock, ['AUTH', $this->password]);
        }
        if ($this->database !== 0) {
            $this->rawHandshake($sock, ['SELECT', (string) $this->database]);
        }

        return $sock;
    }

    /**
     * @param resource $sock
     * @param array<int, string> $args
     */
    private function rawHandshake($sock, array $args): void
    {
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        if (@fwrite($sock, $payload) === false) {
            $this->disconnect();
            throw new RuntimeException('Redis handshake write failed.');
        }
        $this->readReply($sock);
    }

    /**
     * @param resource $sock
     */
    private function readReply($sock): mixed
    {
        $line = @fgets($sock);
        if ($line === false || $line === '') {
            $meta = stream_get_meta_data($sock);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('read error on connection: read timed out');
            }
            throw new RuntimeException('read error on connection: eof while reading');
        }

        $type = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");

        return match ($type) {
            '+' => $body === 'OK' ? true : $body,
            '-' => throw new RuntimeException("Redis server error: {$body}"),
            ':' => (int) $body,
            '$' => $this->readBulk($sock, (int) $body),
            '*' => $this->readMultiBulk($sock, (int) $body),
            default => throw new RuntimeException("Malformed RESP reply prefix: {$type}"),
        };
    }

    /**
     * @param resource $sock
     */
    private function readBulk($sock, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        $remaining = $length + 2;
        $data = '';
        while (strlen($data) < $remaining) {
            $chunk = @fread($sock, $remaining - strlen($data));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($sock);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('read error on connection: read timed out');
                }
                throw new RuntimeException('read error on connection: eof while reading');
            }
            $data .= $chunk;
        }

        return substr($data, 0, $length);
    }

    /**
     * @param resource $sock
     * @return array<int, mixed>|null
     */
    private function readMultiBulk($sock, int $count): ?array
    {
        if ($count < 0) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readReply($sock);
        }

        return $items;
    }

    private function prefixed(string $key): string
    {
        return $this->prefix . $key;
    }
}
