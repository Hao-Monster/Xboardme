<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeSort;
use App\Models\Knowledge;
use App\Services\KnowledgeAttachmentBindingService;
use App\Services\PublicKnowledgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Throwable;

class KnowledgeController extends Controller
{
    public function __construct(
        private KnowledgeAttachmentBindingService $attachmentBindingService,
        private PublicKnowledgeService $publicKnowledgeService
    )
    {
    }

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge)
                return $this->fail([400202, '知识不存在']);
            $data = $knowledge->toArray();
            $data['body'] = $this->attachmentBindingService->normalizeMarkup((string) $data['body']);
            return $this->success($this->withShareUrl($data));
        }
        $data = Knowledge::select(['title', 'id', 'updated_at', 'category', 'show'])
            ->orderBy('sort', 'ASC')
            ->get();
        return $this->success($data->map(
            fn(Knowledge $knowledge) => $this->withShareUrl($knowledge->toArray())
        ));
    }

    public function getCategory(Request $request)
    {
        return $this->success(array_keys(Knowledge::get()->groupBy('category')->toArray()));
    }

    public function save(KnowledgeSave $request)
    {
        $params = $request->validated();
        $knowledgeId = isset($params['id']) ? (int) $params['id'] : null;
        $draftToken = $params['draft_token'] ?? null;
        $knowledgeData = Arr::except($params, ['id', 'draft_token']);
        $knowledgeData['body'] = $this->attachmentBindingService->normalizeMarkup(
            (string) ($knowledgeData['body'] ?? '')
        );

        try {
            DB::transaction(function () use (
                $knowledgeId,
                $knowledgeData,
                $draftToken,
                $request
            ): void {
                if ($knowledgeId) {
                    $knowledge = Knowledge::where('id', $knowledgeId)->lockForUpdate()->first();
                    if (!$knowledge) {
                        throw new ApiException('知识不存在。', 404);
                    }
                    $knowledge->fill($knowledgeData);
                    $knowledge->saveOrFail();
                } else {
                    $knowledge = Knowledge::create($knowledgeData);
                }

                $this->attachmentBindingService->sync(
                    $knowledge,
                    $knowledge->body,
                    (int) $request->user()->id,
                    $draftToken
                );
            }, 3);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Knowledge save with attachments failed', [
                'knowledge_id' => $knowledgeId,
                'admin_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);
            throw new ApiException('知识文章保存失败，请稍后重试。', 500);
        }

        return $this->success(true);
    }

    public function show(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            throw new ApiException('知识不存在');
        }
        $knowledge->show = !$knowledge->show;
        if (!$knowledge->save()) {
            throw new ApiException('保存失败');
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $request->validate([
            'ids' => 'required|array'
        ], [
            'ids.required' => '参数有误',
            'ids.array' => '参数有误'
        ]);
        try {
            DB::beginTransaction();
            foreach ($request->input('ids') as $k => $v) {
                $knowledge = Knowledge::find($v);
                $knowledge->timestamps = false;
                $knowledge->update(['sort' => $k + 1]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiException('保存失败');
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '知识库ID不能为空'
        ]);
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            return $this->fail([400202, '知识不存在']);
        }
        try {
            DB::transaction(function () use ($knowledge): void {
                $lockedKnowledge = Knowledge::where('id', $knowledge->id)->lockForUpdate()->firstOrFail();
                $this->attachmentBindingService->trashForKnowledge($lockedKnowledge);
                $lockedKnowledge->delete();
            }, 3);
        } catch (Throwable $exception) {
            Log::error('Knowledge delete with attachments failed', [
                'knowledge_id' => $knowledge->id,
                'error' => $exception->getMessage(),
            ]);
            throw new ApiException('知识文章删除失败，请稍后重试。', 500);
        }

        return $this->success(true);
    }

    private function withShareUrl(array $knowledge): array
    {
        $knowledge['share_url'] = $this->publicKnowledgeService->shareUrlFor(
            (int) $knowledge['id'],
            (string) $knowledge['title']
        );
        return $knowledge;
    }
}
