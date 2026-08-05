<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeAttachmentUploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('knowledge_attachments');
        config([
            'knowledge_attachments.disk' => 'knowledge_attachments',
            'knowledge_attachments.chunk_size_bytes' => 4,
            'knowledge_attachments.max_file_size_bytes' => 64,
            'knowledge_attachments.total_quota_bytes' => 256,
            'knowledge_attachments.draft_ttl_hours' => 24,
        ]);
    }

    public function test_only_an_admin_can_initialize_an_upload_and_unsafe_names_are_rejected(): void
    {
        $payload = $this->initializePayload('manual.zip', 'content');

        $this->postJson($this->endpoint('admin.knowledge.attachments.upload.initialize'), $payload)
            ->assertForbidden();

        Sanctum::actingAs($this->makeUser('member@example.com'));
        $this->postJson($this->endpoint('admin.knowledge.attachments.upload.initialize'), $payload)
            ->assertForbidden();

        Sanctum::actingAs($this->makeAdmin('admin@example.com'));
        $this->postJson(
            $this->endpoint('admin.knowledge.attachments.upload.initialize'),
            $this->initializePayload('../manual.zip', 'content')
        )->assertUnprocessable();

        $response = $this->postJson(
            $this->endpoint('admin.knowledge.attachments.upload.initialize'),
            $payload
        )->assertOk();

        $response->assertJsonPath('data.original_name', 'manual.zip')
            ->assertJsonPath('data.declared_size', 7)
            ->assertJsonPath('data.chunk_size', 4)
            ->assertJsonPath('data.total_chunks', 2)
            ->assertJsonPath('data.status', KnowledgeAttachmentUpload::STATUS_INITIALIZED);

        $data = $response->json('data');
        $this->assertArrayNotHasKey('temporary_path', $data);
        $this->assertArrayNotHasKey('draft_token', $data);
        $this->assertArrayNotHasKey('expected_sha256', $data);
    }

    public function test_chunks_are_validated_resumable_idempotent_and_owned_by_the_uploader(): void
    {
        $admin = $this->makeAdmin('upload-admin@example.com');
        Sanctum::actingAs($admin);
        $upload = $this->initializeUpload('archive.bin', 'abcdefgh');

        $this->uploadChunk($upload['upload_uuid'], 0, 'abc', hash('sha256', 'abc'))
            ->assertUnprocessable();

        $this->uploadChunk($upload['upload_uuid'], 0, 'abcd', str_repeat('0', 64))
            ->assertUnprocessable();

        $this->uploadChunk($upload['upload_uuid'], 2, 'abcd', hash('sha256', 'abcd'))
            ->assertUnprocessable();

        $this->uploadChunk($upload['upload_uuid'], 0, 'abcd')
            ->assertOk()
            ->assertJsonPath('data.accepted_index', 0)
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.received_chunks', 1);

        $this->uploadChunk($upload['upload_uuid'], 0, 'abcd')
            ->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.received_chunks', 1);

        $this->uploadChunk($upload['upload_uuid'], 0, 'wxyz')
            ->assertStatus(409);

        $this->getJson($this->endpoint(
            'admin.knowledge.attachments.upload.status',
            ['uploadUuid' => $upload['upload_uuid']]
        ))->assertOk()
            ->assertJsonPath('data.uploaded_chunks.0', 0)
            ->assertJsonPath('data.received_chunks', 1);

        Sanctum::actingAs($this->makeAdmin('other-admin@example.com'));
        $this->getJson($this->endpoint(
            'admin.knowledge.attachments.upload.status',
            ['uploadUuid' => $upload['upload_uuid']]
        ))->assertNotFound();
    }

    public function test_complete_merges_chunks_verifies_the_file_and_is_idempotent(): void
    {
        Sanctum::actingAs($this->makeAdmin('complete-admin@example.com'));
        $content = "plain attachment\n";
        $upload = $this->initializeUpload('guide.txt', $content);
        $chunks = str_split($content, 4);

        foreach ($chunks as $index => $chunk) {
            $this->uploadChunk($upload['upload_uuid'], $index, $chunk)->assertOk();
        }

        $completeUrl = $this->endpoint(
            'admin.knowledge.attachments.upload.complete',
            ['uploadUuid' => $upload['upload_uuid']]
        );
        $response = $this->postJson($completeUrl)->assertOk();
        $response->assertJsonPath('data.uuid', $upload['upload_uuid'])
            ->assertJsonPath('data.original_name', 'guide.txt')
            ->assertJsonPath('data.size', strlen($content))
            ->assertJsonPath('data.sha256', hash('sha256', $content))
            ->assertJsonPath('data.status', KnowledgeAttachment::STATUS_READY)
            ->assertJsonPath('data.disposition', 'attachment');
        $this->assertStringContainsString('/knowledge-attachments/' . $upload['upload_uuid'], $response->json('data.url'));

        $attachment = KnowledgeAttachment::where('uuid', $upload['upload_uuid'])->firstOrFail();
        $this->assertSame('txt', $attachment->extension);
        $this->assertNotSame('application/x-client-value', $attachment->mime_type);
        $this->assertSame($content, Storage::disk('knowledge_attachments')->get($attachment->storage_path));
        $this->assertSame($attachment->uuid, basename($attachment->storage_path));

        $session = KnowledgeAttachmentUpload::where('uuid', $upload['upload_uuid'])->firstOrFail();
        $this->assertSame(KnowledgeAttachmentUpload::STATUS_COMPLETED, $session->status);
        Storage::disk('knowledge_attachments')->assertMissing($session->temporary_path);

        $this->postJson($completeUrl)->assertOk()
            ->assertJsonPath('data.uuid', $attachment->uuid)
            ->assertJsonPath('data.sha256', $attachment->sha256);

        $this->assertSame(1, KnowledgeAttachment::where('uuid', $upload['upload_uuid'])->count());
    }

    public function test_incomplete_and_corrupt_uploads_remain_recoverable(): void
    {
        Sanctum::actingAs($this->makeAdmin('recovery-admin@example.com'));
        $upload = $this->initializeUpload('recover.bin', 'abcdefgh');
        $this->uploadChunk($upload['upload_uuid'], 0, 'abcd')->assertOk();

        $completeUrl = $this->endpoint(
            'admin.knowledge.attachments.upload.complete',
            ['uploadUuid' => $upload['upload_uuid']]
        );
        $this->postJson($completeUrl)->assertStatus(409);
        $this->assertSame(
            KnowledgeAttachmentUpload::STATUS_UPLOADING,
            KnowledgeAttachmentUpload::where('uuid', $upload['upload_uuid'])->value('status')
        );

        $this->uploadChunk($upload['upload_uuid'], 1, 'efgh')->assertOk();
        $this->postJson($completeUrl)->assertOk();

        $bad = $this->initializeUpload('bad.bin', 'abcdefgh', str_repeat('f', 64));
        $this->uploadChunk($bad['upload_uuid'], 0, 'abcd')->assertOk();
        $this->uploadChunk($bad['upload_uuid'], 1, 'efgh')->assertOk();
        $this->postJson($this->endpoint(
            'admin.knowledge.attachments.upload.complete',
            ['uploadUuid' => $bad['upload_uuid']]
        ))->assertUnprocessable();

        $badSession = KnowledgeAttachmentUpload::where('uuid', $bad['upload_uuid'])->firstOrFail();
        $this->assertSame(KnowledgeAttachmentUpload::STATUS_INITIALIZED, $badSession->status);
        $this->assertSame(0, $badSession->received_chunks);
        $this->assertSame([], $this->getJson($this->endpoint(
            'admin.knowledge.attachments.upload.status',
            ['uploadUuid' => $bad['upload_uuid']]
        ))->assertOk()->json('data.uploaded_chunks'));
    }

    public function test_expired_upload_status_cannot_revert_to_uploading(): void
    {
        $admin = $this->makeAdmin('expired-admin@example.com');
        Sanctum::actingAs($admin);

        $session = KnowledgeAttachmentUpload::create([
            'uploader_user_id' => $admin->id,
            'draft_token' => str_repeat('a', 64),
            'original_name' => 'expired.bin',
            'declared_size' => 4,
            'chunk_size' => 4,
            'total_chunks' => 1,
            'received_chunks' => 0,
            'temporary_path' => 'temporary/expired/chunks',
            'status' => KnowledgeAttachmentUpload::STATUS_INITIALIZED,
            'expires_at' => time() - 1,
        ]);

        Storage::disk('knowledge_attachments')->put($session->temporary_path . '/chunks/0.part', 'data');

        $this->getJson($this->endpoint(
            'admin.knowledge.attachments.upload.status',
            ['uploadUuid' => $session->uuid]
        ))->assertOk()
            ->assertJsonPath('data.status', KnowledgeAttachmentUpload::STATUS_EXPIRED);

        $this->assertSame(
            KnowledgeAttachmentUpload::STATUS_EXPIRED,
            $session->fresh()->status
        );
    }

    public function test_list_never_exposes_private_paths_and_drop_is_a_safe_soft_delete(): void
    {
        $admin = $this->makeAdmin('list-admin@example.com');
        Sanctum::actingAs($admin);
        $draftToken = str_repeat('b', 64);

        $attachment = KnowledgeAttachment::create([
            'uploader_user_id' => $admin->id,
            'draft_token' => $draftToken,
            'original_name' => 'manual.zip',
            'storage_path' => 'files/2026/08/private-object',
            'mime_type' => 'application/zip',
            'extension' => 'zip',
            'size' => 12,
            'sha256' => str_repeat('c', 64),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
        Storage::disk('knowledge_attachments')->put($attachment->storage_path, 'file-content');

        $response = $this->getJson($this->endpoint('admin.knowledge.attachments.fetch') . '?' . http_build_query([
            'draft_token' => $draftToken,
        ]))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.uuid', $attachment->uuid);

        $item = $response->json('data.items.0');
        $this->assertArrayNotHasKey('storage_path', $item);
        $this->assertArrayNotHasKey('draft_token', $item);

        $this->postJson($this->endpoint('admin.knowledge.attachments.drop'), [
            'uuid' => $attachment->uuid,
        ])->assertOk();

        $this->assertTrue(KnowledgeAttachment::withTrashed()->where('uuid', $attachment->uuid)->firstOrFail()->trashed());
        Storage::disk('knowledge_attachments')->assertExists($attachment->storage_path);

        $knowledge = Knowledge::create([
            'language' => 'zh-CN',
            'category' => 'test',
            'title' => 'Bound attachment',
            'body' => 'Body',
            'show' => true,
        ]);
        $bound = KnowledgeAttachment::create([
            'knowledge_id' => $knowledge->id,
            'uploader_user_id' => $admin->id,
            'draft_token' => null,
            'original_name' => 'bound.pdf',
            'storage_path' => 'files/2026/08/bound-object',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 20,
            'sha256' => str_repeat('d', 64),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);

        $this->postJson($this->endpoint('admin.knowledge.attachments.drop'), [
            'uuid' => $bound->uuid,
        ])->assertStatus(409);
        $this->assertFalse($bound->fresh()->trashed());
    }

    public function test_quota_includes_active_reservations_and_soft_deleted_files(): void
    {
        $admin = $this->makeAdmin('quota-admin@example.com');
        Sanctum::actingAs($admin);
        config(['knowledge_attachments.total_quota_bytes' => 10]);

        $first = $this->postJson(
            $this->endpoint('admin.knowledge.attachments.upload.initialize'),
            $this->initializePayload('first.bin', '123456')
        )->assertOk();

        $this->postJson(
            $this->endpoint('admin.knowledge.attachments.upload.initialize'),
            $this->initializePayload('second.bin', '12345')
        )->assertUnprocessable();

        KnowledgeAttachmentUpload::where('uuid', $first->json('data.upload_uuid'))->update([
            'status' => KnowledgeAttachmentUpload::STATUS_EXPIRED,
        ]);

        $deleted = KnowledgeAttachment::create([
            'uploader_user_id' => $admin->id,
            'draft_token' => str_repeat('e', 64),
            'original_name' => 'deleted.bin',
            'storage_path' => 'files/deleted-object',
            'mime_type' => 'application/octet-stream',
            'extension' => 'bin',
            'size' => 7,
            'sha256' => str_repeat('f', 64),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
        $deleted->delete();

        $this->postJson(
            $this->endpoint('admin.knowledge.attachments.upload.initialize'),
            $this->initializePayload('third.bin', '1234')
        )->assertUnprocessable();
    }

    private function initializeUpload(string $name, string $content, ?string $sha256 = null): array
    {
        return $this->postJson(
            $this->endpoint('admin.knowledge.attachments.upload.initialize'),
            $this->initializePayload($name, $content, $sha256)
        )->assertOk()->json('data');
    }

    private function initializePayload(string $name, string $content, ?string $sha256 = null): array
    {
        return [
            'original_name' => $name,
            'size' => strlen($content),
            'draft_token' => str_repeat('1', 64),
            'sha256' => $sha256 ?? hash('sha256', $content),
        ];
    }

    private function uploadChunk(
        string $uploadUuid,
        int $index,
        string $content,
        ?string $sha256 = null
    ) {
        return $this->call(
            'POST',
            $this->endpoint('admin.knowledge.attachments.upload.chunk', ['uploadUuid' => $uploadUuid]),
            ['index' => $index, 'sha256' => $sha256 ?? hash('sha256', $content)],
            [],
            ['file' => UploadedFile::fake()->createWithContent($index . '.part', $content)],
            ['HTTP_ACCEPT' => 'application/json']
        );
    }

    private function endpoint(string $name, array $parameters = []): string
    {
        return route($name, $parameters, false);
    }

    private function makeAdmin(string $email): User
    {
        return $this->makeUser($email, ['is_admin' => true]);
    }

    private function makeUser(string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => false,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
        ], $attributes));
    }
}
