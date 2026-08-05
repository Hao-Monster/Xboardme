<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeAttachmentUploadChunk extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['upload_uuid' => $this->route('uploadUuid')]);
    }

    public function rules(): array
    {
        return [
            'upload_uuid' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0'],
            'sha256' => [
                'required',
                'string',
                'size:64',
                'regex:/^[a-f0-9]{64}$/i',
            ],
            'file' => ['required', 'file'],
        ];
    }

    public function messages(): array
    {
        return [
            'upload_uuid.uuid' => '上传会话编号格式错误。',
            'index.required' => '缺少分片编号。',
            'index.integer' => '分片编号格式错误。',
            'index.min' => '分片编号格式错误。',
            'sha256.required' => '缺少分片SHA-256。',
            'sha256.size' => '分片SHA-256格式错误。',
            'sha256.regex' => '分片SHA-256格式错误。',
            'file.required' => '缺少分片文件。',
            'file.file' => '上传的分片无效。',
        ];
    }
}
