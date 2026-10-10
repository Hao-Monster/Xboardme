<?php

namespace App\Http\Requests\Admin;

use App\Rules\DistributorName;
use Illuminate\Foundation\Http\FormRequest;

class DistributorRename extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'integer', 'min:1'],
            'distributor_name' => ['required', 'string', new DistributorName()],
            // A missing precondition must not silently become the legacy null revision.
            'expected_distributor_revision' => ['present', 'nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'distributor_name.required' => '请输入分销商名称',
            'expected_distributor_revision.present' => '缺少分销商版本，请重新读取当前名称',
            'expected_distributor_revision.uuid' => '分销商版本无效，请重新读取当前名称',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('distributor_name'))) {
            $this->merge(['distributor_name' => trim($this->input('distributor_name'))]);
        }
    }
}
