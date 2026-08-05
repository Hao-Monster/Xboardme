<?php

namespace App\Http\Controllers;

use App\Models\Knowledge;
use App\Services\BookStackService;

class PublicKnowledgeShareController extends Controller
{
    public function show(string $token, BookStackService $bookStack)
    {
        $knowledge = Knowledge::where('share_token', $token)->where('show', true)->firstOrFail();
        $html = $bookStack->pageHtml($knowledge);

        return response()->view('knowledge-share', compact('knowledge', 'html'))
            ->header('Referrer-Policy', 'same-origin')
            ->header('X-Frame-Options', 'DENY');
    }
}
