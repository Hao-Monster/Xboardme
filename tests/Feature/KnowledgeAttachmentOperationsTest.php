<?php

namespace Tests\Feature;

use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeAttachmentUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeAttachmentOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_command_reports_stored_reserved_and_available_capacity(): void
    {
        Storage::fake('knowledge_attachments');
        config([
            'knowledge_attachments.disk' => 'knowledge_attachments',
            'knowledge_attachments.total_quota_bytes' => 1000,
        ]);

        KnowledgeAttachment::create([
            'uploader_user_id' => 1,
            'original_name' => 'guide.pdf',
            'storage_path' => 'files/guide',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 300,
            'sha256' => str_repeat('a', 64),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
        KnowledgeAttachmentUpload::create([
            'uploader_user_id' => 1,
            'draft_token' => str_repeat('b', 64),
            'original_name' => 'video.mp4',
            'declared_size' => 200,
            'chunk_size' => 100,
            'total_chunks' => 2,
            'temporary_path' => 'temporary/upload',
            'status' => KnowledgeAttachmentUpload::STATUS_UPLOADING,
            'expires_at' => time() + 3600,
        ]);

        $this->assertSame(0, Artisan::call('knowledge-attachments:status', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringContainsString('"healthy":true', $output);
        $this->assertStringContainsString('"used_bytes":300', $output);
        $this->assertStringContainsString('"reserved_bytes":200', $output);
        $this->assertStringContainsString('"quota_available_bytes":500', $output);
    }

    public function test_status_command_fails_when_application_quota_is_exceeded(): void
    {
        Storage::fake('knowledge_attachments');
        config([
            'knowledge_attachments.disk' => 'knowledge_attachments',
            'knowledge_attachments.total_quota_bytes' => 100,
        ]);

        KnowledgeAttachment::create([
            'uploader_user_id' => 1,
            'original_name' => 'oversize.bin',
            'storage_path' => 'files/oversize',
            'mime_type' => 'application/octet-stream',
            'extension' => 'bin',
            'size' => 101,
            'sha256' => str_repeat('c', 64),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);

        $this->assertSame(1, Artisan::call('knowledge-attachments:status', ['--json' => true]));
        $this->assertStringContainsString('"healthy":false', Artisan::output());
    }

    public function test_docker_templates_and_deployment_keep_private_attachments_persistent(): void
    {
        foreach ([
            'compose.sample.yaml',
            'compose.host.sample.yaml',
            'compose.1panel.sample.yaml',
            'compose.split.sample.yaml',
        ] as $composeFile) {
            $compose = file_get_contents(base_path($composeFile));
            $this->assertStringContainsString(
                './storage/knowledge-attachments:/www/storage/app/knowledge-attachments',
                $compose,
                $composeFile
            );
        }

        $entrypoint = file_get_contents(base_path('.docker/entrypoint.sh'));
        $this->assertStringContainsString('KNOWLEDGE_ATTACHMENT_ROOT', $entrypoint);
        $this->assertStringContainsString('su-exec www test -w', $entrypoint);

        $deployment = file_get_contents(base_path('.github/scripts/deploy-xboard-compose.sh'));
        $this->assertStringContainsString('ATTACHMENT_DEST=/www/storage/app/knowledge-attachments', $deployment);
        $this->assertStringContainsString('Migrating knowledge attachments from the current container', $deployment);

        $phpIni = file_get_contents(base_path('.docker/php/zz-xboard.ini'));
        $this->assertMatchesRegularExpression('/upload_max_filesize\s*=\s*16M/', $phpIni);
        $this->assertMatchesRegularExpression('/post_max_size\s*=\s*18M/', $phpIni);

        $this->assertStringContainsString(
            '/storage/knowledge-attachments/',
            file_get_contents(base_path('.gitignore'))
        );
        $this->assertStringContainsString(
            '/storage/knowledge-attachments/',
            file_get_contents(base_path('.dockerignore'))
        );
    }
}
