<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'expired_at' => 'nullable|integer',
            'plan_id' => 'nullable|integer',
            'email_prefix' => 'nullable',
            'email_suffix' => 'required',
            'password' => 'nullable',
            'is_distributor' => 'sometimes|boolean',
            'distributor_name' => 'nullable|string|max:100|required_if:is_distributor,1,true'
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.max' => '生成数量最大为500个',
            'distributor_name.required_if' => '启用分销商时必须填写分销商名称',
            'distributor_name.max' => '分销商名称不能超过100个字符'
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('distributor_name')) {
            $this->merge([
                'distributor_name' => trim((string) $this->input('distributor_name')),
            ]);
        }
    }
}
