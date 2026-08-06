<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeAttachmentClone extends FormRequest
{
    public function rules(): array
    {
        $maximum = max(1, (int) config('knowledge_attachments.max_attachments_per_article', 100));

        return [
            'source_knowledge_id' => ['required', 'integer', 'min:1', 'exists:v2_knowledge,id'],
            'source_uuids' => ['required', 'array', 'min:1', 'max:' . $maximum],
            'source_uuids.*' => ['required', 'uuid', 'distinct:ignore_case'],
            'draft_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_knowledge_id.required' => '缺少来源知识文章。',
            'source_knowledge_id.exists' => '来源知识文章不存在。',
            'source_uuids.required' => '请选择需要复制的附件。',
            'source_uuids.max' => '一次复制的附件数量过多。',
            'source_uuids.*.uuid' => '来源附件标识格式错误。',
            'source_uuids.*.distinct' => '来源附件不能重复。',
            'draft_token.required' => '缺少目标文章草稿令牌。',
            'draft_token.size' => '目标文章草稿令牌格式错误。',
            'draft_token.regex' => '目标文章草稿令牌格式错误。',
        ];
    }
}
