<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeAttachmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('knowledge_attachments');
        config([
            'knowledge_attachments.disk' => 'knowledge_attachments',
            'knowledge_attachments.draft_ttl_hours' => 24,
            'knowledge_attachments.trash_retention_days' => 7,
            'knowledge_attachments.max_attachments_per_article' => 3,
        ]);
    }

    public function test_new_article_atomically_binds_only_owned_draft_attachments(): void
    {
        $admin = $this->makeAdmin('binding-admin@example.com');
        Sanctum::actingAs($admin);
        $draftToken = str_repeat('a', 64);
        $attachment = $this->makeAttachment($admin, $draftToken, 'manual.pdf');
        $unused = $this->makeAttachment($admin, $draftToken, 'unused.zip');
        $body = '# Guide' . "\n\n" . '[Download](' . $attachment->placeholder() . ')';

        $this->postJson($this->endpoint('knowledge/save'), [
            'category' => 'Guide',
            'language' => 'zh-CN',
            'title' => 'Attachment article',
            'body' => $body,
            'show' => true,
            'draft_token' => strtoupper($draftToken),
        ])->assertOk()->assertJsonPath('data.id', 1);

        $knowledge = Knowledge::where('title', 'Attachment article')->firstOrFail();
        $this->assertSame($body, $knowledge->body);
        $this->assertSame($knowledge->id, $attachment->fresh()->knowledge_id);
        $this->assertNull($attachment->fresh()->draft_token);
        $this->assertNull($unused->fresh()->knowledge_id);
        $this->assertSame($draftToken, $unused->fresh()->draft_token);
    }

    public function test_edit_synchronizes_references_and_can_restore_a_recently_removed_attachment(): void
    {
        $admin = $this->makeAdmin('edit-admin@example.com');
        Sanctum::actingAs($admin);
        $token = str_repeat('b', 64);
        $first = $this->makeAttachment($admin, $token, 'first.png');
        $knowledge = $this->createKnowledge($first->placeholder());
        $first->update(['knowledge_id' => $knowledge->id, 'draft_token' => null]);
        $second = $this->makeAttachment($admin, $token, 'second.mp4');

        $this->postJson($this->endpoint('knowledge/save'), [
            'id' => $knowledge->id,
            'category' => $knowledge->category,
            'language' => $knowledge->language,
            'title' => $knowledge->title,
            'body' => '![](' . $second->placeholder() . ')',
            'show' => true,
            'draft_token' => $token,
        ])->assertOk();

        $this->assertTrue(KnowledgeAttachment::withTrashed()->findOrFail($first->id)->trashed());
        $this->assertSame($knowledge->id, $second->fresh()->knowledge_id);

        $this->postJson($this->endpoint('knowledge/save'), [
            'id' => $knowledge->id,
            'category' => $knowledge->category,
            'language' => $knowledge->language,
            'title' => $knowledge->title,
            'body' => '![](' . $first->placeholder() . ')',
            'show' => true,
        ])->assertOk();

        $this->assertFalse(KnowledgeAttachment::withTrashed()->findOrFail($first->id)->trashed());
        $this->assertTrue(KnowledgeAttachment::withTrashed()->findOrFail($second->id)->trashed());
    }

    public function test_invalid_unknown_or_foreign_placeholders_roll_back_article_save(): void
    {
        $admin = $this->makeAdmin('owner-admin@example.com');
        $other = $this->makeAdmin('foreign-admin@example.com');
        $token = str_repeat('c', 64);
        $foreign = $this->makeAttachment($other, $token, 'foreign.bin');
        Sanctum::actingAs($admin);

        $base = [
            'category' => 'Guide',
            'language' => 'zh-CN',
            'title' => 'Rejected article',
            'show' => true,
            'draft_token' => $token,
        ];

        $this->postJson($this->endpoint('knowledge/save'), array_merge($base, [
            'body' => '[file](' . $foreign->placeholder() . ')',
        ]))->assertUnprocessable();

        $this->postJson($this->endpoint('knowledge/save'), array_merge($base, [
            'body' => '[file](knowledge-attachment://not-a-uuid)',
        ]))->assertUnprocessable();

        $this->postJson($this->endpoint('knowledge/save'), array_merge($base, [
            'body' => '[file](knowledge-attachment://123e4567-e89b-42d3-a456-426614174000)',
        ]))->assertUnprocessable();

        $this->assertSame(0, Knowledge::where('title', 'Rejected article')->count());
        $this->assertNull($foreign->fresh()->knowledge_id);
    }

    public function test_article_delete_soft_deletes_attachments_for_the_recycle_period(): void
    {
        $admin = $this->makeAdmin('delete-admin@example.com');
        Sanctum::actingAs($admin);
        $attachment = $this->makeAttachment($admin, str_repeat('d', 64), 'delete.pdf');
        $knowledge = $this->createKnowledge($attachment->placeholder());
        $attachment->update(['knowledge_id' => $knowledge->id, 'draft_token' => null]);

        $this->postJson($this->endpoint('knowledge/drop'), ['id' => $knowledge->id])
            ->assertOk();

        $this->assertNull(Knowledge::find($knowledge->id));
        $this->assertTrue(KnowledgeAttachment::withTrashed()->findOrFail($attachment->id)->trashed());
        Storage::disk('knowledge_attachments')->assertExists($attachment->storage_path);
    }

    public function test_cleanup_expires_sessions_trashes_stale_drafts_and_purges_old_trash(): void
    {
        $admin = $this->makeAdmin('cleanup-admin@example.com');
        $disk = Storage::disk('knowledge_attachments');
        $now = time();

        $session = KnowledgeAttachmentUpload::create([
            'uploader_user_id' => $admin->id,
            'draft_token' => str_repeat('e', 64),
            'original_name' => 'expired.bin',
            'declared_size' => 4,
            'chunk_size' => 4,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'temporary_path' => 'temporary/' . $admin->id . '/expired-session',
            'status' => KnowledgeAttachmentUpload::STATUS_UPLOADING,
            'expires_at' => $now - 1,
        ]);
        $disk->put($session->temporary_path . '/chunks/0.part', 'data');

        $staleDraft = $this->makeAttachment($admin, str_repeat('f', 64), 'stale.zip');
        KnowledgeAttachment::whereKey($staleDraft->id)->update(['created_at' => $now - 90000]);
        $recentDraft = $this->makeAttachment($admin, str_repeat('1', 64), 'recent.zip');

        $purge = $this->makeAttachment($admin, str_repeat('2', 64), 'purge.zip');
        $purge->delete();
        KnowledgeAttachment::withTrashed()->whereKey($purge->id)->update(['deleted_at' => $now - (8 * 86400)]);

        $this->artisan('knowledge-attachments:cleanup')
            ->expectsOutputToContain('Knowledge attachment cleanup:')
            ->assertExitCode(0);

        $this->assertNull(KnowledgeAttachmentUpload::find($session->id));
        $disk->assertMissing($session->temporary_path . '/chunks/0.part');
        $this->assertTrue(KnowledgeAttachment::withTrashed()->findOrFail($staleDraft->id)->trashed());
        $this->assertFalse($recentDraft->fresh()->trashed());
        $this->assertNull(KnowledgeAttachment::withTrashed()->find($purge->id));
        $disk->assertMissing($purge->storage_path);
    }

    private function endpoint(string $path): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        return '/api/v2/' . $securePath . '/' . ltrim($path, '/');
    }

    private function makeAttachment(
        User $admin,
        string $draftToken,
        string $originalName
    ): KnowledgeAttachment {
        $attachment = KnowledgeAttachment::create([
            'uploader_user_id' => $admin->id,
            'draft_token' => $draftToken,
            'original_name' => $originalName,
            'storage_path' => 'files/2026/08/' . Helper::guid(true),
            'mime_type' => 'application/octet-stream',
            'extension' => pathinfo($originalName, PATHINFO_EXTENSION),
            'size' => 4,
            'sha256' => hash('sha256', $originalName),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
        Storage::disk('knowledge_attachments')->put($attachment->storage_path, 'data');

        return $attachment;
    }

    private function createKnowledge(string $body): Knowledge
    {
        return Knowledge::create([
            'category' => 'Guide',
            'language' => 'zh-CN',
            'title' => 'Lifecycle article ' . Helper::guid(),
            'body' => $body,
            'show' => true,
        ]);
    }

    private function makeAdmin(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => true,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
        ]);
    }
}
