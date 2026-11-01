<?php

namespace App\Support;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Horizon\SupervisorOptions;
use Symfony\Component\Console\Input\ArgvInput;

final class HwidObservationConfigValidator
{
    public const DRIVER = 'hwid-observation-redis';

    public const MAX_PUBLISH_TIMEOUT_SECONDS = 1.0;

    /**
     * Build the dedicated producer Redis connection array by parsing any business REDIS_URL
     * first and then forcefully overriding non-persistent, zero-retry, and bounded timeout options
     * so URL query parameters or global Redis options cannot weaken request-path isolation.
     *
     * @param array<string, mixed> $baseConfig
     * @return array<string, mixed>
     */
    public static function buildPublishRedisConnectionConfig(
        array $baseConfig,
        float $connectTimeout = 0.10,
        float $readTimeout = 0.10
    ): array {
        $parsed = self::parseRedisUrlConfiguration($baseConfig);
        $nestedOptions = is_array($parsed['options'] ?? null) ? $parsed['options'] : [];

        unset(
            $parsed['url'],
            $parsed['options'],
            $parsed['persistent_id'],
            $parsed['retry'],
            $parsed['command_retries'],
            $parsed['backoff_algorithm'],
            $parsed['backoff_base'],
            $parsed['backoff_cap'],
            $nestedOptions['url'],
            $nestedOptions['persistent'],
            $nestedOptions['persistent_id'],
            $nestedOptions['timeout'],
            $nestedOptions['read_timeout'],
            $nestedOptions['read_write_timeout'],
            $nestedOptions['retry_interval'],
            $nestedOptions['max_retries'],
            $nestedOptions['command_retries'],
            $nestedOptions['retry'],
            $nestedOptions['backoff_algorithm'],
            $nestedOptions['backoff_base'],
            $nestedOptions['backoff_cap']
        );

        $enforced = [
            'persistent' => false,
            'persistent_id' => null,
            'timeout' => $connectTimeout,
            'read_timeout' => $readTimeout,
            'read_write_timeout' => $readTimeout,
            'retry_interval' => 0,
            'max_retries' => 0,
            'command_retries' => 0,
        ];

        $result = array_merge($parsed, ['url' => null], $enforced);
        $result['options'] = array_merge($nestedOptions, $enforced);

        return $result;
    }

    /**
     * Bootstrap Laravel's real environment and configuration loaders without touching
     * Redis or database connections, enforce dual-switch and queue parity invariants,
     * and return the resolved boolean flags plus effective Horizon worker floor.
     *
     * @return array{hwid_async_metadata: bool, hwid_observation_worker_enabled: bool, required_horizon_workers: int}
     */
    public static function resolveAndValidateBootstrapSwitches(
        string $basePath,
        ?string $environmentPath = null,
        ?string $environmentFile = null
    ): array {
        $previousContainer = Container::getInstance();
        try {
            $app = new Application($basePath);
            if ($environmentPath !== null && $environmentPath !== '') {
                $app->useEnvironmentPath($environmentPath);
            }
            if ($environmentFile !== null && $environmentFile !== '') {
                $app->loadEnvironmentFrom($environmentFile);
            }

            $app->bootstrapWith([
                LoadEnvironmentVariables::class,
                LoadConfiguration::class,
            ]);

            $subscriptionConfig = (array) $app['config']->get('subscription', []);
            $horizonConfig = (array) $app['config']->get('horizon', []);
            $appEnv = (string) $app['config']->get('app.env', 'production');
            self::assertReleaseSwitchCombination($subscriptionConfig);

            $asyncEnabled = (bool) ($subscriptionConfig['hwid_async_metadata'] ?? false);
            $workerEnabled = (bool) ($subscriptionConfig['hwid_observation_worker_enabled'] ?? false);

            if ($asyncEnabled || $workerEnabled) {
                self::assertQueueParity(
                    (array) $app['config']->get('database', []),
                    (array) $app['config']->get('queue', []),
                    $subscriptionConfig,
                    $horizonConfig,
                    $appEnv
                );
            }

            return [
                'hwid_async_metadata' => $asyncEnabled,
                'hwid_observation_worker_enabled' => $workerEnabled,
                'required_horizon_workers' => self::calculateEffectiveHorizonMinWorkers($horizonConfig, $appEnv),
            ];
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    /**
     * Verify that producer and worker queue connections target the exact same
     * effective Redis endpoint, database, key prefix, and queue name, and that the
     * effective post-URL-parsed publish connection enforces short timeouts, zero retries,
     * and non-persistent sockets.
     *
     * @param array<string, mixed>|null $databaseConfig
     * @param array<string, mixed>|null $queueConfig
     * @param array<string, mixed>|null $subscriptionConfig
     * @param array<string, mixed>|null $horizonConfig
     */
    public static function assertQueueParity(
        ?array $databaseConfig = null,
        ?array $queueConfig = null,
        ?array $subscriptionConfig = null,
        ?array $horizonConfig = null,
        ?string $appEnv = null
    ): void {
        $databaseConfig ??= (array) config('database', []);
        $queueConfig ??= (array) config('queue', []);
        $subscriptionConfig ??= (array) config('subscription', []);
        $horizonConfig ??= (array) config('horizon', []);

        $publishConnName = (string) ($subscriptionConfig['hwid_observation_publish_connection'] ?? 'hwid_observation_publish');
        $workerConnName = (string) ($subscriptionConfig['hwid_observation_worker_connection'] ?? 'hwid_observation_worker');
        $rawExpectedQueue = (string) ($subscriptionConfig['hwid_observation_queue'] ?? 'hwid_observation');
        $expectedQueue = trim($rawExpectedQueue);
        $jobTimeout = (int) ($subscriptionConfig['hwid_observation_job_timeout'] ?? 45);

        if ($expectedQueue === '' || $rawExpectedQueue !== $expectedQueue || str_contains($expectedQueue, ',')) {
            throw new InvalidArgumentException('HWID observation queue name must be a single non-empty trimmed identifier.');
        }

        $connections = (array) ($queueConfig['connections'] ?? []);
        $publishQueue = $connections[$publishConnName] ?? null;
        $workerQueue = $connections[$workerConnName] ?? null;

        if (!is_array($publishQueue) || !is_array($workerQueue)) {
            throw new InvalidArgumentException('Dedicated HWID observation queue connections are missing.');
        }

        if (($publishQueue['driver'] ?? null) !== self::DRIVER || ($workerQueue['driver'] ?? null) !== self::DRIVER) {
            throw new InvalidArgumentException('HWID observation queue connections must use the hwid-observation-redis driver.');
        }

        $publishQueueName = (string) ($publishQueue['queue'] ?? '');
        $workerQueueName = (string) ($workerQueue['queue'] ?? '');
        if ($publishQueueName !== $expectedQueue || $workerQueueName !== $expectedQueue) {
            throw new InvalidArgumentException('Producer and worker HWID observation queue names must match.');
        }

        if (($publishQueue['block_for'] ?? null) !== null || ($workerQueue['block_for'] ?? null) !== null) {
            throw new InvalidArgumentException('HWID observation queue connections must keep block_for set to null.');
        }

        $publishRetryAfter = (int) ($publishQueue['retry_after'] ?? 0);
        $workerRetryAfter = (int) ($workerQueue['retry_after'] ?? 0);
        if ($publishRetryAfter <= $jobTimeout || $workerRetryAfter <= $jobTimeout) {
            throw new InvalidArgumentException('HWID observation retry_after must be strictly greater than worker timeout.');
        }

        $redisConfig = (array) ($databaseConfig['redis'] ?? []);
        $redisClientDriver = strtolower(trim((string) ($redisConfig['client'] ?? 'phpredis')));
        $globalRedisOptions = is_array($redisConfig['options'] ?? null) ? $redisConfig['options'] : [];

        $publishRedisName = (string) ($publishQueue['connection'] ?? 'default');
        $workerRedisName = (string) ($workerQueue['connection'] ?? 'default');
        $publishRedis = $redisConfig[$publishRedisName] ?? null;
        $workerRedis = $redisConfig[$workerRedisName] ?? null;

        if (!is_array($publishRedis) || !is_array($workerRedis)) {
            throw new InvalidArgumentException('Redis connections for HWID observation producer/worker are missing.');
        }

        $publishEffective = self::resolveEffectiveRedisConfigs($publishRedis, $globalRedisOptions, $publishRedisName);
        $workerEffective = self::resolveEffectiveRedisConfigs($workerRedis, $globalRedisOptions, $workerRedisName);

        self::assertPublishConnectionInvariants($publishEffective['parsed'], 'parsed');
        self::assertPublishConnectionInvariants($publishEffective['merged'], 'merged');

        $identityView = $redisClientDriver === 'predis' ? 'parsed' : 'merged';
        if (
            self::normalizeRedisIdentity($publishEffective[$identityView])
            !== self::normalizeRedisIdentity($workerEffective[$identityView])
        ) {
            throw new InvalidArgumentException('Producer and worker Redis instance, database, and prefix must match.');
        }

        $plans = [];
        $defaults = (array) ($horizonConfig['defaults'] ?? []);
        foreach ((array) ($horizonConfig['environments'] ?? []) as $environment => $supervisors) {
            if (is_array($supervisors)) {
                $plans[$environment] = array_replace_recursive($defaults, $supervisors);
            }
        }

        if ((bool) ($subscriptionConfig['hwid_observation_worker_enabled'] ?? false)) {
            $fallbackEnv = $appEnv !== null && $appEnv !== ''
                ? $appEnv
                : (string) config('app.env', 'production');
            $environments = array_unique([
                (string) ($horizonConfig['env'] ?? $fallbackEnv),
                self::resolveHorizonEnvironment($horizonConfig, $fallbackEnv),
            ]);
            foreach ($environments as $environment) {
                // Child processes use the configured environment; the master may override it.
                $activePlan = collect($plans)->first(
                    static fn ($plan, $pattern): bool => Str::is((string) $pattern, $environment)
                );
                if (!is_array($activePlan) || !isset($activePlan['hwid-observation'])) {
                    throw new InvalidArgumentException('Enabled HWID observation worker requires a dedicated supervisor in the active Horizon environment.');
                }
            }
        }

        foreach ($plans as $supervisors) {
            if (!isset($supervisors['hwid-observation'])) {
                continue;
            }
            if (!is_array($supervisors['hwid-observation'])) {
                throw new InvalidArgumentException('Horizon hwid-observation supervisor violates the dedicated 1-worker contract.');
            }
            $supervisor = $supervisors['hwid-observation'];
            $options = self::normalizeSupervisorOptions('hwid-observation', $supervisor, true);
            /** @var array<string, mixed> $state */
            $state = $options->toArray();
            $rawSupervisorQueue = (string) ($state['queue'] ?? '');
            $rawMin = $state['minProcesses'] ?? null;
            $rawMax = $state['maxProcesses'] ?? null;
            $rawTimeout = $state['timeout'] ?? null;
            $minProcessesValid = !is_bool($rawMin)
                && is_numeric($rawMin)
                && (int) $rawMin === 1
                && (float) $rawMin === 1.0;
            $maxProcessesValid = !is_bool($rawMax)
                && is_numeric($rawMax)
                && (int) $rawMax === 1
                && (float) $rawMax === 1.0;
            $timeoutValid = !is_bool($rawTimeout)
                && is_numeric($rawTimeout)
                && (int) $rawTimeout > 0;
            $supervisorTimeout = $timeoutValid ? (int) $rawTimeout : 0;

            if (($state['connection'] ?? null) !== $workerConnName
                || $rawSupervisorQueue !== $expectedQueue
                || ($state['balance'] ?? null) !== 'simple'
                || !$minProcessesValid
                || !$maxProcessesValid
                || !$timeoutValid
                || $workerRetryAfter <= $supervisorTimeout
            ) {
                throw new InvalidArgumentException('Horizon hwid-observation supervisor violates the dedicated 1-worker contract.');
            }
        }
    }

    /**
     * Normalize raw supervisor config options using Laravel Horizon's exact
     * ProvisioningPlan::convert() rules so aliases such as `processes`, `tries`,
     * and snake_case keys are evaluated identically to Horizon at runtime.
     *
     * @param array<string, mixed> $rawOptions
     */
    public static function normalizeSupervisorOptions(
        string $supervisorName,
        array $rawOptions,
        bool $rejectConflictingAliases = false
    ): SupervisorOptions {
        $normalized = [];
        foreach ($rawOptions as $key => $value) {
            $mappedKey = match ((string) $key) {
                'tries' => 'max_tries',
                'processes' => 'max_processes',
                default => (string) $key,
            };
            if ($mappedKey === 'queue' && is_array($value)) {
                $value = implode(',', array_map(static fn ($q): string => (string) $q, $value));
            }
            if ($mappedKey === 'backoff' && is_array($value)) {
                $value = implode(',', array_map(static fn ($b): string => (string) $b, $value));
            }

            $camelKey = Str::camel($mappedKey);
            if ($rejectConflictingAliases && array_key_exists($camelKey, $normalized)) {
                throw new InvalidArgumentException(
                    "Horizon {$supervisorName} supervisor defines conflicting option aliases for [{$camelKey}]."
                );
            }
            $normalized[$camelKey] = $value;
        }

        if (isset($normalized['minProcesses']) && (is_bool($normalized['minProcesses']) || !is_numeric($normalized['minProcesses']) || (int) $normalized['minProcesses'] < 1)) {
            throw new InvalidArgumentException("The value of [{$supervisorName}.minProcesses] must be greater than 0.");
        }

        $normalized['parentId'] = 0;
        $normalized['name'] = "validator:{$supervisorName}";
        $normalized['connection'] = (string) ($normalized['connection'] ?? '');

        return SupervisorOptions::fromArray($normalized);
    }

    /**
     * Compute the minimum baseline worker process count that Horizon will actually
     * maintain in the active environment(s) after ProvisioningPlan normalization,
     * pool-per-queue expansion (`balance=auto|simple`), and `Supervisor::scale()`.
     *
     * @param array<string, mixed> $horizonConfig
     */
    public static function calculateEffectiveHorizonMinWorkers(
        array $horizonConfig,
        ?string $appEnv = null
    ): int {
        $plans = [];
        $defaults = (array) ($horizonConfig['defaults'] ?? []);
        foreach ((array) ($horizonConfig['environments'] ?? []) as $environment => $supervisors) {
            if (is_array($supervisors)) {
                $plans[(string) $environment] = array_replace_recursive($defaults, $supervisors);
            }
        }

        $fallbackEnv = $appEnv !== null && $appEnv !== ''
            ? $appEnv
            : (string) config('app.env', 'production');
        $configuredEnv = (string) ($horizonConfig['env'] ?? $fallbackEnv);
        $environments = array_values(array_unique(array_filter([
            $configuredEnv,
            self::resolveHorizonEnvironment($horizonConfig, $fallbackEnv),
        ], static fn (string $env): bool => $env !== '')));

        if ($environments === []) {
            $environments = ['production'];
        }

        $maxRequiredWorkers = 0;
        foreach ($environments as $environment) {
            $activePlan = collect($plans)->first(
                static fn ($plan, $pattern): bool => Str::is((string) $pattern, $environment)
            );
            if (!is_array($activePlan)) {
                continue;
            }

            $planWorkers = 0;
            foreach ($activePlan as $supervisorName => $rawSupervisor) {
                if (!is_array($rawSupervisor)) {
                    continue;
                }
                $options = self::normalizeSupervisorOptions((string) $supervisorName, $rawSupervisor);
                /** @var array<string, mixed> $state */
                $state = $options->toArray();
                $rawMax = $state['maxProcesses'] ?? 0;
                $maxProcesses = is_numeric($rawMax) ? (int) $rawMax : 0;
                if ($maxProcesses <= 0) {
                    continue;
                }

                $rawMin = $state['minProcesses'] ?? 1;
                $minProcesses = is_numeric($rawMin) ? max(1, (int) $rawMin) : 1;
                $rawQueueString = (string) ($state['queue'] ?? '');
                $rawPools = $rawQueueString === '' ? [] : explode(',', $rawQueueString);
                $poolCount = $options->balancing() ? max(1, count($rawPools)) : 1;

                if (!$options->balancing()) {
                    $planWorkers += min($maxProcesses, $minProcesses);
                } elseif (!$options->autoScaling()) {
                    // `balance=simple`: Supervisor::scale() enforces max($maxProcesses, $poolCount)
                    // and AutoScaler maintains that total permanently across pools.
                    $planWorkers += max($poolCount * $minProcesses, $maxProcesses);
                } else {
                    // `balance=auto`: AutoScaler keeps at least $minProcesses workers per queue pool.
                    $planWorkers += $poolCount * $minProcesses;
                }
            }

            if ($planWorkers > $maxRequiredWorkers) {
                $maxRequiredWorkers = $planWorkers;
            }
        }

        return $maxRequiredWorkers;
    }

    /** @param array<string, mixed> $horizonConfig */
    private static function resolveHorizonEnvironment(array $horizonConfig, ?string $fallbackEnv = null): string
    {
        $input = new ArgvInput();
        if ($input->getFirstArgument() === 'horizon') {
            $override = $input->getParameterOption('--environment', null, true);
            if (is_string($override)) {
                return $override;
            }
        }

        if (isset($horizonConfig['env']) && (string) $horizonConfig['env'] !== '') {
            return (string) $horizonConfig['env'];
        }

        return $fallbackEnv !== null && $fallbackEnv !== ''
            ? $fallbackEnv
            : (string) config('app.env');
    }

    /**
     * Release & boot check: disallow enabling async producer when dedicated consumer is disabled.
     *
     * @param array<string, mixed>|null $subscriptionConfig
     */
    public static function assertReleaseSwitchCombination(?array $subscriptionConfig = null): void
    {
        $subscriptionConfig ??= (array) config('subscription', []);
        $producerEnabled = (bool) ($subscriptionConfig['hwid_async_metadata'] ?? false);
        $workerEnabled = (bool) ($subscriptionConfig['hwid_observation_worker_enabled'] ?? false);

        if ($producerEnabled && !$workerEnabled) {
            throw new InvalidArgumentException(
                'SUBSCRIPTION_HWID_ASYNC_METADATA cannot be enabled while SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED is false.'
            );
        }
    }

    /**
     * Parse a Redis connection config array through Laravel's URL parser in the exact
     * same manner as Illuminate\Redis\RedisManager::parseConnectionConfiguration().
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function parseRedisUrlConfiguration(array $config): array
    {
        $parsed = (new ConfigurationUrlParser())->parseConfiguration($config);

        $driver = strtolower((string) ($parsed['driver'] ?? ''));
        if (in_array($driver, ['tcp', 'tls'], true)) {
            $parsed['scheme'] = $driver;
        }

        return array_filter(
            $parsed,
            static fn ($key): bool => $key !== 'driver',
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Resolve both the Predis effective parameter/options view ('parsed') and the
     * PhpRedisConnector::createClient() effective view ('merged') using Laravel's
     * exact connector merge precedence (including top-level prefix overriding
     * nested options.prefix, and persistent=false ignoring global persistent_id).
     *
     * @param array<string, mixed> $connectionConfig
     * @param array<string, mixed> $globalRedisOptions
     * @return array{parsed: array<string, mixed>, merged: array<string, mixed>}
     */
    public static function resolveEffectiveRedisConfigs(
        array $connectionConfig,
        array $globalRedisOptions = [],
        string $connectionName = 'default'
    ): array {
        $parsed = self::parseRedisUrlConfiguration($connectionConfig);
        $connectorOptions = array_merge(
            Arr::except($globalRedisOptions, 'parameters'),
            [
                'parameters' => Arr::get(
                    $globalRedisOptions,
                    "parameters.{$connectionName}",
                    Arr::get($globalRedisOptions, 'parameters', [])
                ),
            ]
        );

        // Exact Illuminate\Redis\Connectors\PhpRedisConnector::connect() merge rules
        $phpRedisConfig = $parsed;
        $rawPulledOptions = Arr::pull($phpRedisConfig, 'options', []);
        $formattedOptions = is_array($rawPulledOptions) ? $rawPulledOptions : [];
        if (isset($phpRedisConfig['prefix'])) {
            $formattedOptions['prefix'] = $phpRedisConfig['prefix'];
        }
        $phpRedisClient = array_merge($phpRedisConfig, $connectorOptions, $formattedOptions);
        if (!($phpRedisClient['persistent'] ?? false)) {
            $phpRedisClient['persistent_id'] = null;
        }

        // Exact Illuminate\Redis\Connectors\PredisConnector::connect() merge rules
        $predisConfig = $parsed;
        $rawPredisPulled = Arr::pull($predisConfig, 'options', []);
        $predisPulled = is_array($rawPredisPulled) ? $rawPredisPulled : [];
        $predisOptions = array_merge(['timeout' => 10.0], $connectorOptions, $predisPulled);
        if (isset($predisConfig['prefix'])) {
            $predisOptions['prefix'] = $predisConfig['prefix'];
        }
        $globalParameters = is_array($predisOptions['parameters'] ?? null) ? $predisOptions['parameters'] : [];
        $predisEffective = array_merge($globalParameters, $predisConfig);
        $predisEffective['prefix'] = $predisOptions['prefix'] ?? null;
        if (($predisEffective['persistent'] ?? null) === false) {
            $predisEffective['persistent_id'] = null;
        }

        return [
            'parsed' => $predisEffective,
            'merged' => $phpRedisClient,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function assertPublishConnectionInvariants(array $state, string $view): void
    {
        if (($state['persistent'] ?? null) !== false || !empty($state['persistent_id'])) {
            throw new InvalidArgumentException(
                "HWID observation publish Redis connection ({$view}) must enforce persistent=false without persistent_id."
            );
        }

        foreach (['timeout', 'read_timeout', 'read_write_timeout'] as $timeoutKey) {
            if (!array_key_exists($timeoutKey, $state) || is_bool($state[$timeoutKey]) || !is_numeric($state[$timeoutKey])) {
                throw new InvalidArgumentException(
                    "HWID observation publish Redis connection ({$view}) must define numeric {$timeoutKey}."
                );
            }

            $seconds = (float) $state[$timeoutKey];
            if (!is_finite($seconds) || $seconds <= 0.0 || $seconds > self::MAX_PUBLISH_TIMEOUT_SECONDS) {
                throw new InvalidArgumentException(
                    sprintf(
                        'HWID observation publish Redis connection (%s) %s must be in (0, %.2f] seconds; got %s.',
                        $view,
                        $timeoutKey,
                        self::MAX_PUBLISH_TIMEOUT_SECONDS,
                        (string) $state[$timeoutKey]
                    )
                );
            }
        }

        foreach (['max_retries', 'retry_interval', 'command_retries'] as $retryKey) {
            if (!array_key_exists($retryKey, $state)) {
                if ($retryKey === 'command_retries') {
                    continue;
                }
                throw new InvalidArgumentException(
                    "HWID observation publish Redis connection ({$view}) must define numeric {$retryKey}=0."
                );
            }

            if (is_bool($state[$retryKey]) || !is_numeric($state[$retryKey])) {
                throw new InvalidArgumentException(
                    "HWID observation publish Redis connection ({$view}) must define numeric {$retryKey}=0."
                );
            }

            if ((float) $state[$retryKey] !== 0.0 || (int) $state[$retryKey] !== 0) {
                throw new InvalidArgumentException(
                    sprintf(
                        'HWID observation publish Redis connection (%s) %s must be 0; got %s.',
                        $view,
                        $retryKey,
                        (string) $state[$retryKey]
                    )
                );
            }
        }

        if (!empty($state['retry'])
            || (array_key_exists('backoff_algorithm', $state) && $state['backoff_algorithm'] !== null && $state['backoff_algorithm'] !== '' && $state['backoff_algorithm'] !== 0)
            || !empty($state['backoff_base'])
            || !empty($state['backoff_cap'])
        ) {
            throw new InvalidArgumentException(
                "HWID observation publish Redis connection ({$view}) must not enable retry or backoff options."
            );
        }
    }

    /**
     * @param array<string, mixed> $effectiveRedis
     * @return array{scheme: string, host: string, port: string, path: string, database: int, username: string, password: string, prefix: string}
     */
    private static function normalizeRedisIdentity(array $effectiveRedis): array
    {
        $configuredScheme = isset($effectiveRedis['scheme']) ? trim((string) $effectiveRedis['scheme']) : null;
        $scheme = strtolower($configuredScheme ?? 'tcp');
        if ($scheme === '' || $scheme === 'redis') {
            $scheme = 'tcp';
        } elseif ($scheme === 'rediss') {
            $scheme = 'tls';
        }

        $host = trim((string) ($effectiveRedis['host'] ?? '127.0.0.1'));
        $hostScheme = parse_url($host, PHP_URL_SCHEME);
        if (is_string($hostScheme) && $hostScheme !== '') {
            if ($configuredScheme !== null && $configuredScheme !== '' && strcasecmp($hostScheme, $configuredScheme) !== 0) {
                throw new InvalidArgumentException('The scheme configured in the Redis host option must match the scheme option.');
            }
            $scheme = strtolower($hostScheme);
            if ($scheme === 'redis') {
                $scheme = 'tcp';
            } elseif ($scheme === 'rediss') {
                $scheme = 'tls';
            }
            $host = substr($host, strlen($hostScheme) + 3);
        }

        $path = trim((string) ($effectiveRedis['path'] ?? ''));
        if ($scheme === 'unix' && $path === '' && str_starts_with($host, '/')) {
            $path = $host;
        }

        $port = $scheme === 'unix'
            ? ''
            : trim((string) ($effectiveRedis['port'] ?? '6379'));

        $prefix = !empty($effectiveRedis['prefix'])
            ? (string) $effectiveRedis['prefix']
            : '';

        $username = isset($effectiveRedis['username']) && $effectiveRedis['username'] !== ''
            ? (string) $effectiveRedis['username']
            : '';

        $password = isset($effectiveRedis['password']) && $effectiveRedis['password'] !== ''
            ? (string) $effectiveRedis['password']
            : '';

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'database' => (int) ($effectiveRedis['database'] ?? 0),
            'username' => $username,
            'password' => $password,
            'prefix' => $prefix,
        ];
    }
}
