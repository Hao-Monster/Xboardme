<?php

namespace App\Support;

use App\Services\Plugin\HookManager;

abstract class AbstractProtocol
{
    /**
     * @var array 用户信息
     */
    protected $user;

    /**
     * @var array 服务器信息
     */
    protected $servers;

    /**
     * @var string|null 客户端名称
     */
    protected $clientName;

    /**
     * @var string|null 客户端版本
     */
    protected $clientVersion;

    /**
     * @var string|null 原始 User-Agent
     */
    protected $userAgent;

    /**
     * @var array 协议标识
     */
    public $flags = [];

    /**
     * @var array 协议需求配置
     */
    protected $protocolRequirements = [];

    /**
     * @var array 允许的协议类型（白名单） 为空则不进行过滤
     */
    protected $allowedProtocols = [];

    /**
     * 构造函数
     *
     * @param array $user 用户信息
     * @param array $servers 服务器信息
     * @param string|null $clientName 客户端名称
     * @param string|null $clientVersion 客户端版本
     * @param string|null $userAgent 原始 User-Agent
     */
    public function __construct($user, $servers, $clientName = null, $clientVersion = null, $userAgent = null)
    {
        $this->user = $user;
        $this->servers = $servers;
        $this->clientName = $clientName;
        $this->clientVersion = $clientVersion;
        $this->userAgent = $userAgent;
        $this->protocolRequirements = $this->normalizeProtocolRequirements($this->protocolRequirements);
        $this->servers = HookManager::filter('protocol.servers.filtered', $this->filterServersByVersion());
    }

    /**
     * 获取协议标识
     *
     * @return array
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * 处理请求
     *
     * @return mixed
     */
    abstract public function handle();

    /**
     * 根据客户端版本过滤不兼容的服务器
     *
     * @return array
     */
    protected function filterServersByVersion()
    {
        $this->filterByAllowedProtocols();
        $hasGlobalConfig = isset($this->protocolRequirements['*']);
        $hasClientConfig = isset($this->protocolRequirements[$this->clientName]);

        if ((blank($this->clientName) || blank($this->clientVersion)) && !$hasGlobalConfig) {
            return $this->servers;
        }

        if (!$hasGlobalConfig && !$hasClientConfig) {
            return $this->servers;
        }

        return collect($this->servers)
            ->filter(fn($server) => $this->isCompatible($server))
            ->values()
            ->all();
    }

    /**
     * 检查服务器是否与当前客户端兼容
     *
     * @param array $server 服务器信息
     * @return bool
     */
    protected function isCompatible($server)
    {
        $serverType = $server['type'] ?? null;
        if (isset($this->protocolRequirements['*'][$serverType])) {
            $globalRequirements = $this->protocolRequirements['*'][$serverType];
            if (!$this->checkRequirements($globalRequirements, $server)) {
                return false;
            }
        }

        if (!isset($this->protocolRequirements[$this->clientName][$serverType])) {
            return true;
        }

        $requirements = $this->protocolRequirements[$this->clientName][$serverType];
        return $this->checkRequirements($requirements, $server);
    }

    /**
     * 检查版本要求
     *
     * @param array $requirements 要求配置
     * @param array $server 服务器信息
     * @return bool
     */
    private function checkRequirements(array $requirements, array $server): bool
    {
        foreach ($requirements as $field => $filterRule) {
            if (in_array($field, ['base_version', 'incompatible'])) {
                continue;
            }

            $actualValue = data_get($server, $field);

            if (is_array($filterRule) && isset($filterRule['whitelist'])) {
                $allowedValues = $filterRule['whitelist'];
                $strict = $filterRule['strict'] ?? false;
                // Normalize flat array ['tcp', 'ws'] to ['tcp' => '0.0.0', 'ws' => '0.0.0']
                if (!empty($allowedValues) && is_int(array_key_first($allowedValues))) {
                    $allowedValues = array_fill_keys($allowedValues, '0.0.0');
                }
                if ($strict) {
                    if ($actualValue === null) {
                        return false;
                    }
                    if (!is_string($actualValue) && !is_int($actualValue)) {
                        return false;
                    }
                    if (!isset($allowedValues[$actualValue])) {
                        return false;
                    }
                    $requiredVersion = $allowedValues[$actualValue];
                    if ($requiredVersion !== '0.0.0' && version_compare($this->clientVersion, $requiredVersion, '<')) {
                        return false;
                    }
                    continue;
                }
            } else {
                $allowedValues = $filterRule;
                $strict = false;
            }

            if ($actualValue === null) {
                continue;
            }
            if (!is_string($actualValue) && !is_int($actualValue)) {
                continue;
            }
            if (!isset($allowedValues[$actualValue])) {
                continue;
            }
            $requiredVersion = $allowedValues[$actualValue];
            if ($requiredVersion !== '0.0.0' && version_compare($this->clientVersion, $requiredVersion, '<')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查当前客户端是否支持特定功能
     *
     * @param string $clientName 客户端名称
     * @param string $minVersion 最低版本要求
     * @param array $additionalConditions 额外条件检查
     * @return bool
     */
    protected function supportsFeature(string $clientName, string $minVersion, array $additionalConditions = []): bool
    {
        // 检查客户端名称
        if ($this->clientName !== $clientName) {
            return false;
        }

        // 检查版本号
        if (empty($this->clientVersion) || version_compare($this->clientVersion, $minVersion, '<')) {
            return false;
        }

        // 检查额外条件
        foreach ($additionalConditions as $condition) {
            if (!$condition) {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据白名单过滤服务器
     *
     * @return void
     */
    protected function filterByAllowedProtocols(): void
    {
        if (!empty($this->allowedProtocols)) {
            $this->servers = collect($this->servers)
                ->filter(fn($server) => in_array($server['type'], $this->allowedProtocols))
                ->values()
                ->all();
        }
    }

    /**
     * 将平铺的协议需求转换为树形结构
     *
     * @param array $flat 平铺的协议需求
     * @return array 树形结构的协议需求
     */
    protected function normalizeProtocolRequirements(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            if (!str_contains($key, '.')) {
                $result[$key] = $value;
                continue;
            }
            $segments = explode('.', $key, 3);
            if (count($segments) < 3) {
                $result[$segments[0]][$segments[1] ?? '*'][''] = $value;
                continue;
            }
            [$client, $type, $field] = $segments;
            $result[$client][$type][$field] = $value;
        }
        return $result;
    }

    /**
     * Extract a normalized multi-label domain from a URL or Host header value.
     */
    protected function extractSubscriptionDomain(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (str_contains($trimmed, '://')) {
            $parsedHost = parse_url($trimmed, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        } else {
            $withoutPath = explode('/', $trimmed, 2)[0];
            $host = (string) preg_replace('/:\d+$/', '', $withoutPath);
        }

        $host = strtolower(trim($host, ". \t\n\r\0\x0B[]"));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
            return null;
        }

        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    /**
     * Return ordered, deduplicated subscription gateway and origin domains
     * that must always bypass proxy outbounds for reliable profile self-updates.
     *
     * @return list<string>
     */
    protected function subscriptionDirectDomains(): array
    {
        $candidates = [];

        $subscribeUrlSetting = (string) admin_setting('subscribe_url', '');
        if ($subscribeUrlSetting !== '') {
            foreach (explode(',', $subscribeUrlSetting) as $entry) {
                $candidates[] = $entry;
            }
        }

        $candidates[] = (string) admin_setting('app_url', '');

        if (app()->bound('request')) {
            $candidates[] = (string) request()->getHost();
            $candidates[] = (string) request()->header('Host', '');
        }

        $domains = [];
        foreach ($candidates as $candidate) {
            $domain = $this->extractSubscriptionDomain($candidate);
            if ($domain !== null && !in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Return the primary domain used for subscription self-update links.
     */
    protected function primarySubscriptionDomain(): ?string
    {
        $domains = $this->subscriptionDirectDomains();

        return $domains[0] ?? null;
    }

    /**
     * Prepend DIRECT rules for all subscription gateway/origin domains on Clash-family configs.
     */
    protected function prependClashDirectSubscriptionDomains(array $config): array
    {
        $rules = isset($config['rules']) && is_array($config['rules']) ? $config['rules'] : [];
        $domains = $this->subscriptionDirectDomains();

        if (empty($domains) && app()->bound('request')) {
            $rawHost = trim((string) request()->header('Host', ''));
            $fallbackHost = (string) preg_replace('/:\d+$/', '', $rawHost);
            if ($fallbackHost !== '' && filter_var($fallbackHost, FILTER_VALIDATE_IP) === false) {
                $domains = [$fallbackHost];
            }
        }

        $prepend = [];
        foreach ($domains as $domain) {
            $rule = "DOMAIN,{$domain},DIRECT";
            if (!in_array($rule, $rules, true) && !in_array($rule, $prepend, true)) {
                $prepend[] = $rule;
            }
        }

        $config['rules'] = array_merge($prepend, $rules);

        return $config;
    }

    /**
     * Strip bare Mainland-blocked DoH/DoT/DNS endpoints from Clash fake-ip bootstrap config
     * while preserving explicit Mihomo proxy-detoured resolvers (containing '#').
     */
    public static function sanitizeClashFakeIpBootstrapDns(array $config): array
    {
        if (!isset($config['dns']) || !is_array($config['dns'])) {
            return $config;
        }

        $enhancedMode = strtolower(trim((string) ($config['dns']['enhanced-mode'] ?? '')));
        if ($enhancedMode !== 'fake-ip') {
            return $config;
        }

        if (isset($config['dns']['nameserver-policy']) && is_array($config['dns']['nameserver-policy'])) {
            foreach ($config['dns']['nameserver-policy'] as $pattern => $resolver) {
                if (is_string($resolver)) {
                    if (self::isMainlandBlockedBareDnsEndpoint($resolver)) {
                        unset($config['dns']['nameserver-policy'][$pattern]);
                    }
                    continue;
                }

                if (is_array($resolver)) {
                    $filtered = array_values(array_filter(
                        $resolver,
                        fn($item) => !self::isMainlandBlockedBareDnsEndpoint($item)
                    ));
                    if (empty($filtered)) {
                        unset($config['dns']['nameserver-policy'][$pattern]);
                    } else {
                        $config['dns']['nameserver-policy'][$pattern] = $filtered;
                    }
                }
            }

            if (empty($config['dns']['nameserver-policy'])) {
                unset($config['dns']['nameserver-policy']);
            }
        }

        if (isset($config['dns']['fallback']) && is_array($config['dns']['fallback'])) {
            $filteredFallback = array_values(array_filter(
                $config['dns']['fallback'],
                fn($item) => !self::isMainlandBlockedBareDnsEndpoint($item)
            ));
            if (empty($filteredFallback)) {
                unset($config['dns']['fallback'], $config['dns']['fallback-filter']);
            } else {
                $config['dns']['fallback'] = $filteredFallback;
            }
        }

        return $config;
    }

    public static function isMainlandBlockedBareDnsEndpoint(mixed $endpoint): bool
    {
        if (!is_string($endpoint)) {
            return false;
        }

        $trimmed = trim($endpoint);
        if ($trimmed === '' || str_contains($trimmed, '#')) {
            return false;
        }

        return preg_match(
            '#(?:^|//)(?:dns\.cloudflare\.com|cloudflare-dns\.com|one\.one\.one\.one|dns\.google(?:\.com)?|dns\.quad9\.net|1\.1\.1\.1|1\.0\.0\.1|8\.8\.8\.8|8\.8\.4\.4|9\.9\.9\.9)(?:[:/]|$)#i',
            $trimmed
        ) === 1;
    }
}
