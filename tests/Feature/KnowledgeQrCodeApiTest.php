<?php

namespace Tests\Feature;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeQrCodeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_admin_can_generate_a_knowledge_qr_code(): void
    {
        $url = route('admin.knowledge.attachments.qr-code', [], false);

        $this->postJson($url, ['url' => 'https://cloud.thinderbox.com/guide/1/article'])
            ->assertForbidden();

        Sanctum::actingAs($this->user('member-qr@example.com'));
        $this->postJson($url, ['url' => 'https://cloud.thinderbox.com/guide/1/article'])
            ->assertForbidden();

        Sanctum::actingAs($this->user('admin-qr@example.com', true));
        $response = $this->postJson($url, [
            'url' => 'https://cloud.thinderbox.com/guide/1/article?from=knowledge',
        ])->assertOk();

        $svg = $response->json('data.svg');
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringNotContainsString('<script', strtolower($svg));
    }

    public function test_qr_code_rejects_non_http_and_oversized_urls(): void
    {
        Sanctum::actingAs($this->user('admin-qr-validation@example.com', true));
        $url = route('admin.knowledge.attachments.qr-code', [], false);

        foreach (['javascript:alert(1)', '/relative/path', 'data:text/html,boom'] as $invalid) {
            $this->postJson($url, ['url' => $invalid])->assertUnprocessable();
        }

        $this->postJson($url, ['url' => 'https://example.com/' . str_repeat('a', 2049)])
            ->assertUnprocessable();
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $admin,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
        ]);
    }
}
