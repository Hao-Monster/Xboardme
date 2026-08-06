<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Models\User;
use App\Services\KnowledgeAttachmentAccessService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeAttachmentReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('knowledge_attachments');
        config([
            'app.url' => 'http://localhost',
            'knowledge_attachments.disk' => 'knowledge_attachments',
            'knowledge_attachments.signed_url_ttl_minutes' => 120,
        ]);
    }

    public function test_signed_inline_image_is_private_and_has_safe_headers(): void
    {
        $attachment = $this->makeAttachment('photo.png', 'image/png', 'image-content');
        $url = app(KnowledgeAttachmentAccessService::class)->signedUrl($attachment);

        $this->get((string) parse_url($url, PHP_URL_PATH))->assertForbidden();

        $response = $this->get($url)->assertOk();
        $this->assertSame('image-content', $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-site')
            ->assertHeader('ETag', '"' . hash('sha256', 'image-content') . '"');
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));

        $expired = URL::temporarySignedRoute(
            'knowledge.attachments.read',
            now()->subMinute(),
            ['attachmentUuid' => $attachment->uuid, 'disposition' => 'inline']
        );
        $this->get($expired)->assertForbidden();
    }

    public function test_unsafe_mime_is_always_downloaded_with_a_sanitized_filename(): void
    {
        $attachment = $this->makeAttachment("../恶意\r\nimage.svg", 'image/svg+xml', '<svg></svg>');
        $access = app(KnowledgeAttachmentAccessService::class);
        $url = $access->signedUrl($attachment, 'inline');

        $response = $this->get($url)->assertOk();
        $this->assertSame('<svg></svg>', $response->streamedContent());
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $response->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");
    }

    public function test_range_requests_support_bounded_open_and_suffix_ranges(): void
    {
        $attachment = $this->makeAttachment('video.mp4', 'video/mp4', '0123456789');
        $url = app(KnowledgeAttachmentAccessService::class)->signedUrl($attachment);

        $bounded = $this->withHeader('Range', 'bytes=2-5')->get($url)->assertStatus(206);
        $this->assertSame('2345', $bounded->streamedContent());
        $bounded->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');

        $open = $this->withHeader('Range', 'bytes=7-')->get($url)->assertStatus(206);
        $this->assertSame('789', $open->streamedContent());
        $open->assertHeader('Content-Range', 'bytes 7-9/10');

        $suffix = $this->withHeader('Range', 'bytes=-3')->get($url)->assertStatus(206);
        $this->assertSame('789', $suffix->streamedContent());

        $this->withHeader('Range', 'bytes=99-100')->get($url)
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
        $this->withHeader('Range', 'bytes=0-1,3-4')->get($url)->assertStatus(416);

        $this->call('HEAD', $url)->assertOk()->assertHeader('Content-Length', '10');
    }

    public function test_protected_sections_are_removed_before_attachment_urls_are_issued(): void
    {
        $knowledge = Knowledge::create([
            'language' => 'zh-CN',
            'category' => 'Guide',
            'title' => 'Permission-aware attachments',
            'body' => 'placeholder',
            'sort' => 1,
            'show' => true,
        ]);
        $public = $this->makeAttachment('public.pdf', 'application/pdf', 'public', $knowledge->id);
        $protected = $this->makeAttachment('private.pdf', 'application/pdf', 'private', $knowledge->id);
        $knowledge->update([
            'body' => '[Public](' . $public->placeholder() . ')' . "\n\n"
                . '<!--access start-->[Private](' . $protected->placeholder() . ')<!--access end-->',
        ]);

        $inactive = $this->makeUser('inactive@example.com', false);
        Sanctum::actingAs($inactive);
        $inactiveResponse = $this->getJson('/api/v1/user/knowledge/fetch?id=' . $knowledge->id)
            ->assertOk();
        $inactiveBody = (string) $inactiveResponse->json('data.body');
        $this->assertStringContainsString($public->uuid, $inactiveBody);
        $this->assertStringNotContainsString($protected->uuid, $inactiveBody);
        $this->assertStringNotContainsString(KnowledgeAttachment::URI_PREFIX, $inactiveBody);

        $active = $this->makeUser('active@example.com', true);
        Sanctum::actingAs($active);
        $activeResponse = $this->getJson('/api/v1/user/knowledge/fetch?id=' . $knowledge->id)
            ->assertOk();
        $activeBody = (string) $activeResponse->json('data.body');
        $this->assertStringContainsString($public->uuid, $activeBody);
        $this->assertStringContainsString($protected->uuid, $activeBody);
        $this->assertStringNotContainsString(KnowledgeAttachment::URI_PREFIX, $activeBody);

        preg_match('~https?://[^\s)]+~', $activeBody, $matches);
        $this->assertNotEmpty($matches[0] ?? null);
        $this->get($matches[0])->assertOk();
    }

    public function test_legacy_double_wrapped_download_link_is_repaired_before_rendering(): void
    {
        $knowledge = Knowledge::create([
            'language' => 'zh-CN',
            'category' => 'Guide',
            'title' => 'Legacy attachment link',
            'body' => 'placeholder',
            'sort' => 1,
            'show' => true,
        ]);
        $attachment = $this->makeAttachment(
            'karing.zip',
            'application/zip',
            'archive-content',
            $knowledge->id
        );
        $knowledge->update([
            'body' => '[karing.zip]([karing.zip](' . $attachment->placeholder() . '))',
        ]);

        Sanctum::actingAs($this->makeUser('legacy-link@example.com', true));
        $response = $this->getJson('/api/v1/user/knowledge/fetch?id=' . $knowledge->id . '&render=html')
            ->assertOk();
        $html = (string) $response->json('data.body');

        $this->assertStringContainsString('href="http://localhost/knowledge-attachments/', $html);
        $this->assertStringNotContainsString('href="[karing.zip](', $html);
        $this->assertStringNotContainsString('%5Bkaring.zip%5D', $html);
    }

    public function test_attachment_markup_normalizer_is_scoped_to_private_attachment_uris(): void
    {
        $placeholder = 'knowledge-attachment://11111111-1111-4111-8111-111111111111';
        $service = app(\App\Services\KnowledgeAttachmentBindingService::class);

        $this->assertSame(
            '[client.zip](' . $placeholder . ')',
            $service->normalizeMarkup('[client.zip]([client.zip](' . $placeholder . '))')
        );
        $ordinary = '[outer]([inner](https://example.test/file.zip))';
        $this->assertSame($ordinary, $service->normalizeMarkup($ordinary));
    }

    public function test_missing_soft_deleted_or_size_mismatched_files_cannot_be_read(): void
    {
        $missing = $this->makeAttachment('missing.bin', 'application/octet-stream', 'data');
        $missingUrl = app(KnowledgeAttachmentAccessService::class)->signedUrl($missing);
        Storage::disk('knowledge_attachments')->delete($missing->storage_path);
        $this->get($missingUrl)->assertNotFound();

        $mismatch = $this->makeAttachment('mismatch.bin', 'application/octet-stream', 'data');
        $mismatchUrl = app(KnowledgeAttachmentAccessService::class)->signedUrl($mismatch);
        Storage::disk('knowledge_attachments')->put($mismatch->storage_path, 'different-size');
        $this->get($mismatchUrl)->assertStatus(410);

        $deleted = $this->makeAttachment('deleted.bin', 'application/octet-stream', 'data');
        $deletedUrl = app(KnowledgeAttachmentAccessService::class)->signedUrl($deleted);
        $deleted->delete();
        $this->get($deletedUrl)->assertNotFound();
    }

    private function makeAttachment(
        string $name,
        string $mimeType,
        string $content,
        ?int $knowledgeId = null
    ): KnowledgeAttachment {
        $attachment = KnowledgeAttachment::create([
            'knowledge_id' => $knowledgeId,
            'uploader_user_id' => 1,
            'draft_token' => $knowledgeId ? null : str_repeat('a', 64),
            'original_name' => $name,
            'storage_path' => 'files/2026/08/' . Helper::guid(true),
            'mime_type' => $mimeType,
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
        Storage::disk('knowledge_attachments')->put($attachment->storage_path, $content);

        return $attachment;
    }

    private function makeUser(string $email, bool $active): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'transfer_enable' => $active ? 1024 : 0,
            'expired_at' => $active ? time() + 3600 : 0,
            'is_admin' => false,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
        ]);
    }
}
