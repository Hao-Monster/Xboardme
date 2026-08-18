<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminAssetIntegrityTest extends TestCase
{
    public function test_admin_manifest_references_existing_non_empty_assets(): void
    {
        $adminRoot = public_path('assets/admin');
        $manifestPath = $adminRoot . DIRECTORY_SEPARATOR . 'manifest.json';

        $this->assertFileExists($manifestPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('index.html', $manifest);
        $this->assertTrue($manifest['index.html']['isEntry'] ?? false);

        foreach ($manifest as $chunkName => $chunk) {
            $this->assertIsArray($chunk, "Manifest chunk {$chunkName} must be an object.");

            foreach (['file', 'css', 'assets'] as $field) {
                if (!array_key_exists($field, $chunk)) {
                    continue;
                }

                $assets = $field === 'file' ? [$chunk[$field]] : $chunk[$field];
                $this->assertIsArray($assets, "Manifest field {$chunkName}.{$field} must be an array.");

                foreach ($assets as $asset) {
                    $this->assertIsString($asset);
                    $path = $adminRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
                    $this->assertFileExists($path, "Manifest asset {$asset} is missing.");
                    $this->assertGreaterThan(0, filesize($path), "Manifest asset {$asset} is empty.");
                }
            }
        }

        foreach (['en-US.js', 'zh-CN.js'] as $locale) {
            $path = $adminRoot . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR . $locale;
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));
        }
    }

    public function test_admin_template_uses_the_manifest_entry_instead_of_missing_legacy_assets(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('assets/admin/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $html = view('admin', [
            'title' => 'XBoard',
            'version' => 'test-version',
            'logo' => null,
            'secure_path' => 'test-admin',
        ])->render();

        $this->assertStringContainsString('/assets/admin/' . $manifest['index.html']['file'], $html);
        foreach ($manifest['index.html']['css'] as $stylesheet) {
            $this->assertStringContainsString('/assets/admin/' . $stylesheet, $html);
        }
        $this->assertStringNotContainsString('/assets/admin/assets/index.js', $html);
        $this->assertStringNotContainsString('/assets/admin/assets/vendor.css', $html);
    }
}
