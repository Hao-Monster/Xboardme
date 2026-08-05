<?php

namespace App\Http\Controllers;

use App\Services\KnowledgeAttachmentAccessService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KnowledgeAttachmentController extends Controller
{
    public function __construct(private KnowledgeAttachmentAccessService $accessService)
    {
    }

    public function read(Request $request, string $attachmentUuid): Response
    {
        return $this->accessService->stream($request, $attachmentUuid);
    }
}
