<?php

namespace App\Http\Controllers;

use App\Services\PublicKnowledgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class PublicKnowledgeController extends Controller
{
    public function __construct(private PublicKnowledgeService $knowledgeService)
    {
    }

    public function show(int $knowledge, ?string $slug = null): Response|RedirectResponse
    {
        $article = $this->knowledgeService->findPublished($knowledge);
        $canonicalUrl = $this->knowledgeService->shareUrl($article);
        $canonicalSlug = $this->knowledgeService->slug($article->title);

        if ($slug !== $canonicalSlug) {
            return redirect()->to($canonicalUrl);
        }

        $document = $this->knowledgeService->renderDocument($article);

        return response()->view('knowledge.public', [
            'article' => $article,
            'body' => $document['html'],
            'toc' => $document['toc'],
            'articles' => $this->knowledgeService->publishedNavigation(),
            'canonicalUrl' => $canonicalUrl,
            'appName' => admin_setting('app_name', 'XBoard'),
            'logo' => admin_setting('logo'),
        ])->withHeaders([
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    public function content(int $knowledge): JsonResponse
    {
        $article = $this->knowledgeService->findPublished($knowledge);
        $document = $this->knowledgeService->renderDocument($article);

        return response()->json([
            'id' => (int) $article->id,
            'title' => (string) $article->title,
            'category' => (string) $article->category,
            'updated_at' => date('Y-m-d H:i', (int) $article->updated_at),
            'body' => $document['html'],
            'toc' => $document['toc'],
            'share_url' => $this->knowledgeService->shareUrl($article),
            'page_title' => $article->title . ' - ' . admin_setting('app_name', 'XBoard'),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
