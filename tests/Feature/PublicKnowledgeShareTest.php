<?php

namespace Tests\Feature;

use App\Models\Knowledge;
use App\Models\KnowledgeAttachment;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PublicKnowledgeShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('knowledge_attachments');
        config([
            'app.url' => 'https://example.test',
            'knowledge_attachments.disk' => 'knowledge_attachments',
        ]);
        URL::forceRootUrl('https://example.test');
    }

    public function test_published_article_is_public_and_uses_a_canonical_permanent_url(): void
    {
        $article = $this->article('公开教程', true, '# 使用方法');

        $canonical = 'http://example.test/guide/' . $article->id . '/article';
        $this->get('/guide/' . $article->id)->assertRedirect($canonical);
        $response = $this->get('/guide/' . $article->id . '/old-title')->assertRedirect($canonical);
        $this->followRedirects($response)->assertOk()->assertSee('公开教程');

        $page = $this->get('/guide/' . $article->id . '/article')->assertOk();
        $page->assertSee('使用方法')
            ->assertSee('复制分享链接')
            ->assertSee('/#/login', false)
            ->assertSee('/#/register', false)
            ->assertSee('public-auth-card', false);
        $page->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $oldUrl = '/guide/' . $article->id . '/article';
        $article->update(['title' => 'Renamed Guide']);
        $this->get($oldUrl)->assertRedirect('http://example.test/guide/' . $article->id . '/renamed-guide');
        $this->get('/guide/' . $article->id . '/renamed-guide')->assertOk()->assertSee('Renamed Guide');
    }

    public function test_hidden_or_deleted_articles_do_not_leak_content(): void
    {
        $hidden = $this->article('Hidden', false, 'SECRET-HIDDEN-CONTENT');
        $this->get('/guide/' . $hidden->id . '/hidden')->assertNotFound()->assertDontSee('SECRET-HIDDEN-CONTENT');

        $published = $this->article('Deleted', true, 'SECRET-DELETED-CONTENT');
        $id = $published->id;
        $published->delete();
        $this->get('/guide/' . $id . '/deleted')->assertNotFound()->assertDontSee('SECRET-DELETED-CONTENT');
    }

    public function test_public_reader_exposes_article_navigation_toc_and_safe_content_api(): void
    {
        $current = $this->article('Current Guide', true, "# Intro\n\n## Install\n\n### First Step");
        $other = $this->article('Other Guide', true, 'Other body');
        $hidden = $this->article('Hidden Navigation Guide', false, 'Hidden body');

        $page = $this->get('/guide/' . $current->id . '/current-guide')->assertOk();
        $page->assertSee('public-knowledge-articles', false)
            ->assertSee('public-knowledge-toc', false)
            ->assertSee('Current Guide')
            ->assertSee('Other Guide')
            ->assertDontSee('Hidden Navigation Guide')
            ->assertSee('data-content-url="http://example.test/guide/' . $other->id . '/content"', false)
            ->assertSee('href="#install"', false)
            ->assertSee('id="install"', false);

        $content = $this->getJson('/guide/' . $other->id . '/content')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('id', $other->id)
            ->assertJsonPath('title', 'Other Guide')
            ->assertJsonPath('share_url', 'http://example.test/guide/' . $other->id . '/other-guide');
        $this->assertIsArray($content->json('toc'));
        $this->getJson('/guide/' . $hidden->id . '/content')->assertNotFound();
    }

    public function test_public_html_is_sanitized_and_unsafe_protocols_are_removed(): void
    {
        $article = $this->article(
            'Security',
            true,
            '<script>alert(1)</script><img src="https://example.test/a.png" onerror="alert(2)">'
                . '<a href="javascript:alert(3)" onclick="alert(4)">bad</a>'
        );

        $response = $this->get('/guide/' . $article->id . '/security')->assertOk();
        $response->assertDontSee('<script>alert', false)
            ->assertDontSee('onerror=', false)
            ->assertDontSee('onclick=', false)
            ->assertDontSee('javascript:', false)
            ->assertSee('loading="lazy"', false);

        $json = $this->getJson('/guide/' . $article->id . '/content')->assertOk();
        $body = (string) $json->json('body');
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('onerror=', $body);
    }

    public function test_only_referenced_attachments_of_published_articles_are_public(): void
    {
        $article = $this->article('Files', true, 'placeholder');
        $referenced = $this->attachment($article, 'guide.png', 'image/png', 'image-bytes');
        $unreferenced = $this->attachment($article, 'private.zip', 'application/zip', 'private-bytes');
        $article->update(['body' => '![guide](' . $referenced->placeholder() . ')']);

        $page = $this->get('/guide/' . $article->id . '/files')->assertOk();
        $page->assertSee('/guide-attachments/' . $referenced->uuid, false);

        $publicFile = $this->get('/guide-attachments/' . $referenced->uuid)->assertOk();
        $this->assertSame('image-bytes', $publicFile->streamedContent());
        $publicFile->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/guide-attachments/' . $unreferenced->uuid)->assertNotFound();

        $article->update(['show' => false]);
        $this->get('/guide-attachments/' . $referenced->uuid)->assertNotFound();
    }

    public function test_public_article_repairs_legacy_double_wrapped_attachment_links(): void
    {
        $article = $this->article('Legacy download', true, 'placeholder');
        $attachment = $this->attachment($article, 'client.zip', 'application/zip', 'archive');
        $article->update([
            'body' => '[client.zip]([client.zip](' . $attachment->placeholder() . '))',
        ]);

        $page = $this->get('/guide/' . $article->id . '/legacy-download')->assertOk();
        $page->assertSee('href="http://example.test/guide-attachments/' . $attachment->uuid . '"', false)
            ->assertDontSee('href="[client.zip](', false)
            ->assertDontSee('%5Bclient.zip%5D', false);
    }

    public function test_user_knowledge_api_exposes_the_same_share_url(): void
    {
        $article = $this->article('API Guide', true, 'Body');
        $user = \App\Models\User::create([
            'email' => 'reader@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'transfer_enable' => 0,
            'expired_at' => 0,
            'is_admin' => false,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/v1/user/knowledge/fetch?id=' . $article->id)
            ->assertOk()
            ->assertJsonPath('data.share_url', 'http://example.test/guide/' . $article->id . '/api-guide');
    }

    private function article(string $title, bool $show, string $body): Knowledge
    {
        return Knowledge::create([
            'language' => 'zh-CN',
            'category' => 'Guide',
            'title' => $title,
            'body' => $body,
            'sort' => 1,
            'show' => $show,
        ]);
    }

    private function attachment(Knowledge $knowledge, string $name, string $mime, string $content): KnowledgeAttachment
    {
        $path = 'files/2026/08/' . Helper::guid(true);
        Storage::disk('knowledge_attachments')->put($path, $content);
        return KnowledgeAttachment::create([
            'knowledge_id' => $knowledge->id,
            'uploader_user_id' => 1,
            'draft_token' => null,
            'original_name' => $name,
            'storage_path' => $path,
            'mime_type' => $mime,
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'status' => KnowledgeAttachment::STATUS_READY,
        ]);
    }
}
