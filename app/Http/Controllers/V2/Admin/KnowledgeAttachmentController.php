<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeAttachmentUploadChunk;
use App\Http\Requests\Admin\KnowledgeAttachmentUploadInit;
use App\Services\KnowledgeAttachmentUploadService;
use Illuminate\Http\Request;

class KnowledgeAttachmentController extends Controller
{
    public function __construct(private KnowledgeAttachmentUploadService $uploadService)
    {
    }

    public function initialize(KnowledgeAttachmentUploadInit $request)
    {
        $data = $request->validated();
        $upload = $this->uploadService->initialize(
            (int) $request->user()->id,
            $data['original_name'],
            (int) $data['size'],
            $data['draft_token'],
            $data['sha256'] ?? null
        );

        return $this->success($this->uploadService->uploadPayload($upload));
    }

    public function chunk(KnowledgeAttachmentUploadChunk $request, string $uploadUuid)
    {
        $data = $request->validated();

        return $this->success($this->uploadService->storeChunk(
            (int) $request->user()->id,
            $uploadUuid,
            (int) $data['index'],
            $data['sha256'],
            $request->file('file')
        ));
    }

    public function status(Request $request, string $uploadUuid)
    {
        return $this->success($this->uploadService->status(
            (int) $request->user()->id,
            $uploadUuid
        ));
    }

    public function complete(Request $request, string $uploadUuid)
    {
        $attachment = $this->uploadService->complete(
            (int) $request->user()->id,
            $uploadUuid
        );

        return $this->success($this->uploadService->attachmentPayload($attachment));
    }

    public function cancel(Request $request, string $uploadUuid)
    {
        $data = $request->validate([
            'draft_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $this->uploadService->cancel(
            (int) $request->user()->id,
            $uploadUuid,
            $data['draft_token']
        );

        return $this->success(true);
    }

    public function fetch(Request $request)
    {
        $filters = $request->validate([
            'knowledge_id' => ['nullable', 'integer', 'min:1'],
            'draft_token' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->success($this->uploadService->list($filters));
    }

    public function drop(Request $request)
    {
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'draft_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $this->uploadService->discardDraft(
            (int) $request->user()->id,
            $data['uuid'],
            $data['draft_token']
        );

        return $this->success(true);
    }
}
