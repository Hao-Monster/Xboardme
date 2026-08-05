<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSave extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('draft_token')) {
            $this->merge(['draft_token' => strtolower((string) $this->input('draft_token'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'nullable|integer|min:1',
            'category' => 'required',
            'language' => 'required',
            'title' => 'required',
            'body' => 'nullable|string',
            'show' => 'nullable|boolean',
            'draft_token' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'category.required' => '分类不能为空',
            'body.required' => '内容不能为空',
            'language.required' => '语言不能为空',
            'show.boolean' => '显示状态必须为布尔值',
            'draft_token.size' => '知识库草稿令牌格式错误',
            'draft_token.regex' => '知识库草稿令牌格式错误',
        ];
    }
}
