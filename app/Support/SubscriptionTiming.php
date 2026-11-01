<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SubscriptionTiming
{
    public const REQUEST_ATTRIBUTE = '_subscription_timing';

    public const STAGE_TOKEN_LOOKUP = 'token_lookup';
    public const STAGE_HWID_AUTHORIZE = 'hwid_authorize';
    public const STAGE_DELIVERY_CLAIM = 'delivery_claim';
    public const STAGE_SERVER_LOOKUP_TRANSFORM = 'server_lookup_transform';
    public const STAGE_SERVER_STATUS_CACHE = 'server_status_cache';
    public const STAGE_PROTOCOL_GENERATE = 'protocol_generate';
    public const STAGE_DELIVERY_ISSUE = 'delivery_issue';
    public const STAGE_RESPONSE_HEADERS = 'response_headers';
    public const STAGE_OBSERVATION_PUBLISH = 'observation_publish';

    public const STAGES = [
        self::STAGE_TOKEN_LOOKUP,
        self::STAGE_HWID_AUTHORIZE,
        self::STAGE_DELIVERY_CLAIM,
        self::STAGE_SERVER_LOOKUP_TRANSFORM,
        self::STAGE_SERVER_STATUS_CACHE,
        self::STAGE_PROTOCOL_GENERATE,
        self::STAGE_DELIVERY_ISSUE,
        self::STAGE_RESPONSE_HEADERS,
        self::STAGE_OBSERVATION_PUBLISH,
    ];

    public const STAGE_PARENTS = [
        self::STAGE_SERVER_STATUS_CACHE => self::STAGE_SERVER_LOOKUP_TRANSFORM,
    ];

    public const SUBSCRIPTION_ROUTES = [
        'client.subscribe',
        'client.subscribe.legacy',
    ];

    private string $requestId;

    /**
     * @var array<string, int|null>
     */
    private array $stageNs = [];

    private ?int $serverCount = null;

    private ?int $cacheUniqueKeys = null;

    private int $deliveryWriteAttempts = 0;

    private ?string $hwidBranch = null;

    private string $publishResult = 'not_applicable';

    private ?string $publishErrorCategory = null;

    public function __construct(private readonly int $startedAtNs)
    {
        $this->requestId = bin2hex(random_bytes(8));
        foreach (self::STAGES as $stage) {
            $this->stageNs[$stage] = null;
        }
    }

    public static function attach(Request $request): self
    {
        $timing = new self(hrtime(true));
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $timing);

        return $timing;
    }

    public static function forRequest(?Request $request): ?self
    {
        if ($request === null) {
            return null;
        }

        $timing = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $timing instanceof self ? $timing : null;
    }

    public static function detach(Request $request): void
    {
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function measure(string $stage, callable $callback): mixed
    {
        $startedAt = hrtime(true);
        try {
            return $callback();
        } finally {
            $this->addStageNs($stage, hrtime(true) - $startedAt);
        }
    }

    public function addStageNs(string $stage, int $elapsedNs): void
    {
        if (!array_key_exists($stage, $this->stageNs)) {
            return;
        }

        $clamped = max(0, $elapsedNs);
        $this->stageNs[$stage] = ($this->stageNs[$stage] ?? 0) + $clamped;
    }

    public function setServerCount(int $count): void
    {
        $this->serverCount = max(0, $count);
    }

    public function setCacheUniqueKeys(int $count): void
    {
        $this->cacheUniqueKeys = max(0, $count);
    }

    public function incrementDeliveryWriteAttempts(): void
    {
        $this->deliveryWriteAttempts++;
    }

    public function setHwidBranch(string $branch): void
    {
        $this->hwidBranch = $branch;
    }

    public function setPublishResult(string $result, ?string $errorCategory = null): void
    {
        $this->publishResult = $result;
        $this->publishErrorCategory = $errorCategory;
    }

    /**
     * @param (callable(): float)|null $randomUnitInterval
     * @return array<string, mixed>|null
     */
    public function finish(
        Request $request,
        ?Response $response,
        ?Throwable $unhandledException = null,
        ?callable $randomUnitInterval = null
    ): ?array {
        $routeName = $request->route()?->getName();
        if (!is_string($routeName) || !in_array($routeName, self::SUBSCRIPTION_ROUTES, true)) {
            return null;
        }

        $appDurationMs = round(max(0, hrtime(true) - $this->startedAtNs) / 1_000_000, 3);
        $httpStatus = $response?->getStatusCode();
        $attachedException = ($response !== null && property_exists($response, 'exception') && $response->exception instanceof Throwable)
            ? $response->exception
            : null;
        $effectiveException = $unhandledException ?? $attachedException;

        $outcome = self::classifyOutcome($httpStatus, $unhandledException, $effectiveException);
        $exceptionClass = $effectiveException !== null ? get_class($effectiveException) : null;
        $errorCategory = $effectiveException !== null
            ? self::classifyException($effectiveException)
            : $this->publishErrorCategory;

        $content = $response !== null ? $response->getContent() : false;
        $bodyBytes = is_string($content) ? strlen($content) : null;

        $stagesMs = [];
        foreach (self::STAGES as $stage) {
            $ns = $this->stageNs[$stage];
            $stagesMs[$stage] = $ns === null ? null : round($ns / 1_000_000, 3);
        }

        $payload = [
            'request_id' => $this->requestId,
            'correlation_id' => self::extractSafeCorrelationId($request),
            'route' => $routeName,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'app_duration_ms' => $appDurationMs,
            'stages_ms' => $stagesMs,
            'stage_parents' => self::STAGE_PARENTS,
            'server_count' => $this->serverCount,
            'cache_unique_keys' => $this->cacheUniqueKeys,
            'delivery_write_attempts' => $this->deliveryWriteAttempts,
            'hwid_branch' => $this->hwidBranch,
            'publish_result' => $this->publishResult,
            'body_bytes' => $bodyBytes,
            'exception_class' => $exceptionClass,
            'error_category' => $errorCategory,
        ];

        if ($this->shouldEmitLog($appDurationMs, $httpStatus, $effectiveException, $randomUnitInterval)) {
            $channel = (string) config('subscription.timing_log_channel', 'daily');
            try {
                Log::channel($channel)->info('subscription.request', $payload);
            } catch (Throwable) {
                // Never allow logging failures to alter subscription responses or exceptions.
            }
        }

        return $payload;
    }

    /**
     * @param (callable(): float)|null $randomUnitInterval
     */
    private function shouldEmitLog(
        float $appDurationMs,
        ?int $httpStatus,
        ?Throwable $effectiveException,
        ?callable $randomUnitInterval
    ): bool {
        if (!(bool) config('subscription.timing_log_enabled', true)) {
            return false;
        }

        $slowThresholdMs = max(0.0, (float) config('subscription.timing_slow_threshold_ms', 1000));
        if ($appDurationMs >= $slowThresholdMs) {
            return true;
        }

        if ($effectiveException !== null || $this->publishErrorCategory !== null) {
            return true;
        }

        if ($httpStatus === null || $httpStatus < 200 || $httpStatus >= 300) {
            return true;
        }

        $sampleRate = (float) config('subscription.timing_sample_rate', 0.01);
        if ($sampleRate <= 0.0) {
            return false;
        }
        if ($sampleRate >= 1.0) {
            return true;
        }

        $roll = $randomUnitInterval !== null
            ? (float) $randomUnitInterval()
            : (random_int(0, 999_999) / 1_000_000);

        return $roll < $sampleRate;
    }

    private static function classifyOutcome(
        ?int $httpStatus,
        ?Throwable $unhandledException,
        ?Throwable $effectiveException
    ): string {
        if ($httpStatus === null) {
            return 'exception';
        }

        if ($httpStatus >= 200 && $httpStatus < 300 && $unhandledException === null) {
            return 'success';
        }

        return match ($httpStatus) {
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'validation_failed',
            425 => 'prefetch_rejected',
            default => $httpStatus >= 500
                ? 'server_error'
                : ($effectiveException !== null ? 'exception' : 'client_error'),
        };
    }

    public static function classifyException(Throwable $exception): string
    {
        if ($exception instanceof ApiException) {
            return 'api_exception';
        }

        if ($exception instanceof ValidationException) {
            return 'validation_error';
        }

        if ($exception instanceof HttpExceptionInterface) {
            return 'http_exception';
        }

        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return self::classifyDatabaseException($exception);
        }

        $class = strtolower(get_class($exception));
        if (str_contains($class, 'redis') || str_contains($class, 'predis')) {
            return 'redis_error';
        }

        return 'unhandled_exception';
    }

    public static function classifyDatabaseException(Throwable $exception): string
    {
        $sqlState = null;
        $driverCode = null;

        if ($exception instanceof PDOException && is_array($exception->errorInfo)) {
            $sqlState = isset($exception->errorInfo[0]) ? (string) $exception->errorInfo[0] : null;
            $driverCode = isset($exception->errorInfo[1]) && is_numeric($exception->errorInfo[1])
                ? (int) $exception->errorInfo[1]
                : null;
        }

        if ($sqlState === null) {
            $code = (string) $exception->getCode();
            if ($code !== '') {
                $sqlState = $code;
            }
        }

        if ($driverCode !== null) {
            $sqlitePrimary = $driverCode & 0xFF;
            if (($sqlState === 'HY000' || $sqlState === '5' || $sqlState === '6')
                && ($sqlitePrimary === 5 || $sqlitePrimary === 6)
            ) {
                return 'db_busy';
            }

            if (in_array($driverCode, [1205, 1213], true)) {
                return 'db_busy';
            }

            if (in_array($driverCode, [2002, 2006, 2013, 1040, 1053], true)) {
                return 'db_unavailable';
            }
        }

        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return 'db_busy';
        }

        if ($sqlState !== null && str_starts_with($sqlState, '08')) {
            return 'db_unavailable';
        }

        return 'db_error';
    }

    private static function extractSafeCorrelationId(Request $request): ?string
    {
        $headerName = trim((string) config('subscription.timing_correlation_header', 'X-Request-Id'));
        if ($headerName === '') {
            return null;
        }

        $candidate = trim((string) $request->headers->get($headerName, ''));
        if ($candidate === '' || preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $candidate) !== 1) {
            return null;
        }

        $routeToken = (string) ($request->route('token') ?? '');
        $queryToken = is_scalar($request->query('token')) ? (string) $request->query('token') : '';
        $hwid = trim((string) $request->headers->get('x-hwid', ''));

        foreach ([$routeToken, $queryToken, $hwid] as $secret) {
            if ($secret !== '' && strcasecmp($candidate, $secret) === 0) {
                return null;
            }
        }

        return $candidate;
    }
}
