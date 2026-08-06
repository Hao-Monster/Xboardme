<?php

namespace App\Http\Controllers;

use App\Services\PublicKnowledgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

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

        return response()->view('knowledge.public', [
            'article' => $article,
            'body' => $this->knowledgeService->render($article),
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
}
