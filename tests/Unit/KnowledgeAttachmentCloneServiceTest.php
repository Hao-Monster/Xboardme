<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Services\KnowledgeAttachmentCloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeAttachmentCloneServiceTest extends TestCase
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

    public function test_it_creates_independent_verified_draft_copies(): void
    {
        $source = $this->knowledge();
        $attachment = $this->attachment($source, 'manual.exe', 'binary-data');
        $token = str_repeat('a', 64);

        $result = app(KnowledgeAttachmentCloneService::class)->cloneForDraft(
            99,
            $source->id,
            [$attachment->uuid],
            $token
        );

        $clone = $result->first()['attachment'];
        $this->assertNotSame($attachment->uuid, $clone->uuid);
        $this->assertNotSame($attachment->storage_path, $clone->storage_path);
        $this->assertNull($clone->knowledge_id);
        $this->assertSame(99, $clone->uploader_user_id);
        $this->assertSame($token, $clone->draft_token);
        $this->assertSame($attachment->sha256, $clone->sha256);
        Storage::disk('knowledge_attachments')->assertExists($clone->storage_path);
        $this->assertSame('binary-data', Storage::disk('knowledge_attachments')->get($clone->storage_path));
    }

    public function test_it_rejects_soft_deleted_or_foreign_source_attachments(): void
    {
        $source = $this->knowledge();
        $other = $this->knowledge();
        $deleted = $this->attachment($source, 'deleted.zip', 'deleted');
        $deleted->delete();
        $foreign = $this->attachment($other, 'foreign.zip', 'foreign');

        foreach ([$deleted, $foreign] as $attachment) {
            try {
                app(KnowledgeAttachmentCloneService::class)->cloneForDraft(
                    99,
                    $source->id,
                    [$attachment->uuid],
                    str_repeat('b', 64)
                );
                $this->fail('An unavailable source attachment was cloned.');
            } catch (ApiException $exception) {
                $this->assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_it_rejects_quota_overflow_without_leaving_files_or_rows(): void
    {
        config(['knowledge_attachments.total_quota_bytes' => 12]);
        $source = $this->knowledge();
        $attachment = $this->attachment($source, 'large.bin', '12345678');

        try {
            app(KnowledgeAttachmentCloneService::class)->cloneForDraft(
                99,
                $source->id,
                [$attachment->uuid],
                str_repeat('c', 64)
            );
            $this->fail('Quota overflow was accepted.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $this->assertSame(1, KnowledgeAttachment::count());
        $this->assertCount(1, Storage::disk('knowledge_attachments')->allFiles('files'));
    }

    private function knowledge(): Knowledge
    {
        return Knowledge::create([
            'category' => 'Guide',
            'language' => 'zh-CN',
            'title' => 'Article ' . uniqid('', true),
            'body' => 'Body',
            'show' => true,
        ]);
    }

    private function attachment(Knowledge $knowledge, string $name, string $content): KnowledgeAttachment
    {
        $path = 'files/2026/08/' . uniqid('', true);
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
}
