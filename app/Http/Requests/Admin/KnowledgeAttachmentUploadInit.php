<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class KnowledgeAttachmentUploadInit extends FormRequest
{
    public function rules(): array
    {
        return [
            'original_name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $name = trim((string) $value);
                    if (
                        $name === '' ||
                        $name === '.' ||
                        $name === '..' ||
                        preg_match('/[\x00-\x1F\x7F]/u', $name) === 1 ||
                        str_contains($name, '/') ||
                        str_contains($name, '\\')
                    ) {
                        $fail('文件名包含不安全字符。');
                    }
                },
            ],
            'size' => [
                'required',
                'integer',
                'min:1',
                'max:' . config('knowledge_attachments.max_file_size_bytes'),
            ],
            'draft_token' => [
                'required',
                'string',
                'size:64',
                'regex:/^[a-f0-9]{64}$/i',
            ],
            'sha256' => [
                'nullable',
                'string',
                'size:64',
                'regex:/^[a-f0-9]{64}$/i',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'original_name.required' => '请输入文件名。',
            'original_name.max' => '文件名不能超过255个字符。',
            'size.required' => '缺少文件大小。',
            'size.integer' => '文件大小格式错误。',
            'size.min' => '不能上传空文件。',
            'size.max' => '文件超过单文件大小限制。',
            'draft_token.required' => '缺少知识库草稿令牌。',
            'draft_token.size' => '知识库草稿令牌格式错误。',
            'draft_token.regex' => '知识库草稿令牌格式错误。',
            'sha256.size' => '文件SHA-256格式错误。',
            'sha256.regex' => '文件SHA-256格式错误。',
        ];
    }
}
