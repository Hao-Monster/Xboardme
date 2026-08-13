<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ClientCatalogService
{
    public const PLATFORMS = ['android', 'ios', 'windows', 'macos', 'linux'];
    public const ACTIONS = ['direct', 'qr', 'cloud', 'tutorial'];
    public const SETTING_KEY = 'client_catalog_links';

    public function catalog(): array
    {
        $overrides = $this->overrides();

        return collect(config('client_catalog.clients', []))
            ->map(function (array $client) use ($overrides): array {
                $downloads = collect($client['downloads'] ?? [])
                    ->filter(fn (array $download, string $platform) => in_array($platform, self::PLATFORMS, true))
                    ->map(function (array $download, string $platform) use ($client, $overrides): array {
                        $links = $overrides[$client['id']][$platform] ?? [];
                        $params = ['client' => $client['id'], 'platform' => $platform];

                        return [
                            'platform' => $platform,
                            'source' => isset($download['repo']) ? 'github' : ($download['source'] ?? 'website'),
                            'download_url' => route('client-catalog.download', $params),
                            'cloud_url' => !empty($links['cloud'])
                                ? route('client-catalog.link', $params + ['action' => 'cloud'])
                                : null,
                            'tutorial_url' => !empty($links['tutorial'])
                                ? route('client-catalog.link', $params + ['action' => 'tutorial'])
                                : null,
                        ];
                    })->values()->all();

                return [
                    'id' => $client['id'],
                    'name' => $client['name'],
                    'core' => $client['core'],
                    'featured' => (bool) ($client['featured'] ?? false),
                    'hwid' => true,
                    'description' => $client['description'],
                    'downloads' => $downloads,
                ];
            })
            ->values()
            ->all();
    }

    public function resolve(string $clientId, string $platform): string
    {
        $client = $this->find($clientId);
        $download = $client['downloads'][$platform] ?? null;
        if (!$download || !in_array($platform, self::PLATFORMS, true)) {
            throw new RuntimeException('Client or platform is not available.');
        }

        $directOverride = $this->overrides()[$clientId][$platform]['direct'] ?? null;
        if ($directOverride) {
            return $this->validateConfiguredUrl($directOverride, 'direct');
        }

        if (!isset($download['repo'])) {
            return $this->validateExternalUrl($download['url'] ?? '');
        }

        $cacheKey = sprintf('client-catalog:%s:%s', $clientId, $platform);
        try {
            return Cache::remember($cacheKey, (int) config('client_catalog.cache_ttl', 21600), function () use ($download): string {
                $response = Http::acceptJson()
                    ->withUserAgent('XBoard-Client-Catalog/1.0')
                    ->timeout(12)
                    ->get(sprintf('https://api.github.com/repos/%s/releases/latest', $download['repo']));

                if (!$response->successful()) {
                    throw new RuntimeException('Unable to query the latest client release.');
                }

                $assets = $response->json('assets', []);
                foreach ($download['patterns'] ?? [] as $pattern) {
                    foreach ($assets as $asset) {
                        $name = (string) ($asset['name'] ?? '');
                        if ($name !== '' && @preg_match($pattern, $name) === 1) {
                            return $this->validateGitHubAssetUrl((string) ($asset['browser_download_url'] ?? ''), $download['repo']);
                        }
                    }
                }

                throw new RuntimeException('No matching install package was found in the latest release.');
            });
        } catch (\Throwable $error) {
            if (!empty($download['fallback_url'])) {
                return $this->validateExternalUrl($download['fallback_url']);
            }
            throw $error;
        }
    }

    public function resolveAction(string $clientId, string $platform, string $action): string
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Client action is not available.');
        }

        $client = $this->find($clientId);
        if (!isset($client['downloads'][$platform]) || !in_array($platform, self::PLATFORMS, true)) {
            throw new RuntimeException('Client or platform is not available.');
        }

        $configured = $this->overrides()[$clientId][$platform][$action] ?? null;
        if ($configured) {
            return $this->validateConfiguredUrl($configured, $action);
        }

        if ($action === 'direct' || $action === 'qr') {
            return $this->resolve($clientId, $platform);
        }

        throw new RuntimeException('Client action is not configured.');
    }

    public function adminCatalog(): array
    {
        $overrides = $this->overrides();

        return collect(config('client_catalog.clients', []))->map(function (array $client) use ($overrides): array {
            return [
                'id' => $client['id'],
                'name' => $client['name'],
                'core' => $client['core'],
                'platforms' => collect($client['downloads'] ?? [])
                    ->filter(fn (array $download, string $platform) => in_array($platform, self::PLATFORMS, true))
                    ->map(function (array $download, string $platform) use ($client, $overrides): array {
                        $links = $overrides[$client['id']][$platform] ?? [];

                        return [
                            'platform' => $platform,
                            'links' => [
                                'direct' => $links['direct'] ?? '',
                                'qr' => $links['qr'] ?? '',
                                'cloud' => $links['cloud'] ?? '',
                                'tutorial' => $links['tutorial'] ?? '',
                            ],
                        ];
                    })->values()->all(),
            ];
        })->values()->all();
    }

    public function saveOverrides(array $input): array
    {
        $knownClients = collect(config('client_catalog.clients', []))->keyBy('id');
        $normalized = [];

        foreach ($input as $clientId => $platforms) {
            $client = $knownClients->get($clientId);
            if (!$client || !is_array($platforms)) {
                throw ValidationException::withMessages(['links' => '包含未知客户端或无效配置。']);
            }

            foreach ($platforms as $platform => $links) {
                if (!in_array($platform, self::PLATFORMS, true)
                    || !isset($client['downloads'][$platform])
                    || !is_array($links)) {
                    throw ValidationException::withMessages(['links' => '包含该客户端不支持的系统。']);
                }

                foreach ($links as $action => $url) {
                    if (!in_array($action, self::ACTIONS, true) || (!is_string($url) && $url !== null)) {
                        throw ValidationException::withMessages(['links' => '包含未知按钮或无效链接。']);
                    }

                    $url = trim((string) $url);
                    if ($url === '') {
                        continue;
                    }
                    if (strlen($url) > 2048) {
                        throw ValidationException::withMessages(["links.{$clientId}.{$platform}.{$action}" => '链接不能超过 2048 个字符。']);
                    }

                    try {
                        $normalized[$clientId][$platform][$action] = $this->validateConfiguredUrl($url, $action);
                    } catch (RuntimeException $error) {
                        throw ValidationException::withMessages([
                            "links.{$clientId}.{$platform}.{$action}" => $error->getMessage(),
                        ]);
                    }
                }
            }
        }

        admin_setting([self::SETTING_KEY => $normalized]);

        return $normalized;
    }

    public function find(string $clientId): array
    {
        foreach (config('client_catalog.clients', []) as $client) {
            if (hash_equals((string) ($client['id'] ?? ''), $clientId)) {
                return $client;
            }
        }
        throw new RuntimeException('Client or platform is not available.');
    }

    private function validateGitHubAssetUrl(string $url, string $repo): string
    {
        $url = $this->validateExternalUrl($url);
        $expectedPath = '/' . strtolower($repo) . '/releases/download/';
        $parts = parse_url($url);
        if (strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || !str_starts_with(strtolower((string) ($parts['path'] ?? '')), $expectedPath)) {
            throw new RuntimeException('GitHub returned an invalid install package URL.');
        }
        return $url;
    }

    private function validateExternalUrl(string $url): string
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Client download URL is invalid.');
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Client download URL is invalid.');
        }
        return $url;
    }

    private function overrides(): array
    {
        $overrides = admin_setting(self::SETTING_KEY, []);
        return is_array($overrides) ? $overrides : [];
    }

    private function validateConfiguredUrl(string $url, string $action): string
    {
        $url = trim($url);
        if ($action === 'tutorial'
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !str_contains($url, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1) {
            return $url;
        }

        try {
            return $this->validateExternalUrl($url);
        } catch (RuntimeException) {
            throw new RuntimeException($action === 'tutorial'
                ? '教程链接必须是 HTTPS 地址或以 / 开头的站内地址。'
                : '按钮链接必须是有效的 HTTPS 地址。');
        }
    }
}
