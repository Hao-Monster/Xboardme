<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClientCatalogService
{
    public const PLATFORMS = ['android', 'ios', 'windows', 'macos', 'linux'];

    public function catalog(): array
    {
        return collect(config('client_catalog.clients', []))
            ->map(function (array $client): array {
                $downloads = collect($client['downloads'] ?? [])
                    ->filter(fn (array $download, string $platform) => in_array($platform, self::PLATFORMS, true))
                    ->map(fn (array $download, string $platform) => [
                        'platform' => $platform,
                        'source' => isset($download['repo']) ? 'github' : ($download['source'] ?? 'website'),
                        'download_url' => route('client-catalog.download', [
                            'client' => $client['id'],
                            'platform' => $platform,
                        ]),
                    ])->values()->all();

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
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RuntimeException('Client download URL is invalid.');
        }
        return $url;
    }
}
