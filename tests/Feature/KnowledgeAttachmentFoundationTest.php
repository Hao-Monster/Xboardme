<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeAttachmentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attachment_tables_expose_the_required_security_metadata(): void
    {
        $this->assertTrue(Schema::hasColumns('v2_knowledge_attachment', [
            'uuid',
            'knowledge_id',
            'uploader_user_id',
            'draft_token',
            'original_name',
            'storage_path',
            'mime_type',
            'extension',
            'size',
            'sha256',
            'status',
            'deleted_at',
        ]));

        $this->assertTrue(Schema::hasColumns('v2_knowledge_attachment_upload', [
            'uuid',
            'uploader_user_id',
            'draft_token',
            'declared_size',
            'chunk_size',
            'total_chunks',
            'received_chunks',
            'temporary_path',
            'status',
            'expires_at',
        ]));
    }

    public function test_attachment_model_generates_uuid_hides_private_paths_and_soft_deletes(): void
    {
        $knowledge = Knowledge::create([
            'language' => 'zh-CN',
            'category' => 'test',
            'title' => 'Attachment test',
            'body' => 'Body',
            'show' => true,
        ]);

        $attachment = KnowledgeAttachment::create([
            'knowledge_id' => $knowledge->id,
            'uploader_user_id' => 10,
            'draft_token' => str_repeat('a', 64),
            'original_name' => '../unsafe-name.php',
            'storage_path' => 'files/20/26/random.bin',
            'mime_type' => 'application/octet-stream',
            'extension' => 'php',
            'size' => 123,
            'sha256' => str_repeat('b', 64),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);

        $this->assertNotEmpty($attachment->uuid);
        $this->assertSame($knowledge->id, $attachment->knowledge->id);
        $this->assertCount(1, $knowledge->attachments);
        $this->assertArrayNotHasKey('storage_path', $attachment->toArray());
        $this->assertArrayNotHasKey('draft_token', $attachment->toArray());

        $attachment->delete();

        $this->assertTrue($attachment->trashed());
        $this->assertNull(KnowledgeAttachment::find($attachment->id));
        $this->assertNotNull(KnowledgeAttachment::withTrashed()->find($attachment->id));
    }

    public function test_upload_session_generates_uuid_and_hides_temporary_state(): void
    {
        $upload = KnowledgeAttachmentUpload::create([
            'uploader_user_id' => 10,
            'draft_token' => str_repeat('c', 64),
            'original_name' => 'video.mp4',
            'declared_size' => 1000,
            'chunk_size' => 500,
            'total_chunks' => 2,
            'temporary_path' => 'temporary/private-session',
            'status' => KnowledgeAttachmentUpload::STATUS_INITIALIZED,
            'expires_at' => now()->addDay()->timestamp,
        ]);

        $this->assertNotEmpty($upload->uuid);
        $this->assertArrayNotHasKey('temporary_path', $upload->toArray());
        $this->assertArrayNotHasKey('draft_token', $upload->toArray());
    }

    public function test_private_disk_and_safe_default_limits_are_configured(): void
    {
        Storage::fake('knowledge_attachments');

        $this->assertSame('knowledge_attachments', config('knowledge_attachments.disk'));
        $this->assertSame(5 * 1024 * 1024, config('knowledge_attachments.chunk_size_bytes'));
        $this->assertSame(1024 * 1024 * 1024, config('knowledge_attachments.max_file_size_bytes'));
        $this->assertSame(20 * 1024 * 1024 * 1024, config('knowledge_attachments.total_quota_bytes'));
        $this->assertSame('private', config('filesystems.disks.knowledge_attachments.visibility'));

        Storage::disk('knowledge_attachments')->put('temporary/probe', 'private');
        Storage::disk('knowledge_attachments')->assertExists('temporary/probe');
    }
}

