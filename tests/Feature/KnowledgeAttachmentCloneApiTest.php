<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeAttachmentCloneApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('knowledge_attachments');
        config([
            'knowledge_attachments.disk' => 'knowledge_attachments',
            'knowledge_attachments.total_quota_bytes' => 1024,
            'knowledge_attachments.max_attachments_per_article' => 10,
        ]);
    }

    public function test_admin_can_clone_source_article_attachments_into_an_independent_draft(): void
    {
        $admin = $this->user('clone-admin@example.com', true);
        $source = $this->knowledge();
        $attachment = $this->attachment($source, 'setup.exe', 'executable');
        $draftToken = str_repeat('a', 64);
        Sanctum::actingAs($admin);

        $response = $this->postJson(route('admin.knowledge.attachments.clone', [], false), [
            'source_knowledge_id' => $source->id,
            'source_uuids' => [$attachment->uuid],
            'draft_token' => strtoupper($draftToken),
        ])->assertOk()
            ->assertJsonPath('data.items.0.source_uuid', $attachment->uuid)
            ->assertJsonPath('data.items.0.attachment.original_name', 'setup.exe')
            ->assertJsonPath('data.items.0.attachment.knowledge_id', null);

        $cloneUuid = $response->json('data.items.0.attachment.uuid');
        $this->assertNotSame($attachment->uuid, $cloneUuid);
        $clone = KnowledgeAttachment::where('uuid', $cloneUuid)->firstOrFail();
        $this->assertSame($draftToken, $clone->draft_token);
        $this->assertNotSame($attachment->storage_path, $clone->storage_path);
        $this->assertSame('executable', Storage::disk('knowledge_attachments')->get($clone->storage_path));
    }

    public function test_clone_api_requires_admin_and_rejects_unrelated_attachment(): void
    {
        $source = $this->knowledge();
        $other = $this->knowledge();
        $foreign = $this->attachment($other, 'foreign.zip', 'foreign');
        $payload = [
            'source_knowledge_id' => $source->id,
            'source_uuids' => [$foreign->uuid],
            'draft_token' => str_repeat('b', 64),
        ];

        $this->postJson(route('admin.knowledge.attachments.clone', [], false), $payload)
            ->assertForbidden();
        Sanctum::actingAs($this->user('member@example.com', false));
        $this->postJson(route('admin.knowledge.attachments.clone', [], false), $payload)
            ->assertForbidden();
        Sanctum::actingAs($this->user('other-admin@example.com', true));
        $this->postJson(route('admin.knowledge.attachments.clone', [], false), $payload)
            ->assertUnprocessable();
        $this->assertSame(1, KnowledgeAttachment::count());
    }

    public function test_clone_route_is_rate_limited(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.knowledge.attachments.clone');
        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    private function knowledge(): Knowledge
    {
        return Knowledge::create([
            'category' => 'Guide',
            'language' => 'zh-CN',
            'title' => 'Clone article ' . Helper::guid(),
            'body' => 'Body',
            'show' => true,
        ]);
    }

    private function attachment(Knowledge $knowledge, string $name, string $content): KnowledgeAttachment
    {
        $path = 'files/2026/08/' . Helper::guid(true);
        Storage::disk('knowledge_attachments')->put($path, $content);

        return KnowledgeAttachment::create([
            'knowledge_id' => $knowledge->id,
            'uploader_user_id' => 1,
            'draft_token' => null,
            'original_name' => $name,
            'storage_path' => $path,
            'mime_type' => 'application/octet-stream',
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
    }

    private function user(string $email, bool $admin): User
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
