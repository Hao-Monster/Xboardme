<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $merged = [];
        if (is_string($this->input('customer_name'))) {
            $merged['customer_name'] = trim($this->input('customer_name'));
        }
        if (is_string($this->input('idempotency_key'))) {
            $trimmedKey = trim($this->input('idempotency_key'));
            $merged['idempotency_key'] = $trimmedKey !== '' ? $trimmedKey : null;
        }
        if ($merged !== []) {
            $this->merge($merged);
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
            'plan_id' => 'required',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price',
            'customer_name' => 'nullable|string|max:64',
            'quantity' => 'nullable|integer|min:1|max:' . \App\Services\DistributorOrderService::MAX_BATCH_QUANTITY,
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period'),
            'customer_name.max' => '用户名称不能超过64个字符',
            'quantity.integer' => '下单数量必须是整数',
            'quantity.min' => '下单数量至少为1个',
            'quantity.max' => '单次下单数量最多不能超过' . \App\Services\DistributorOrderService::MAX_BATCH_QUANTITY . '个',
            'idempotency_key.max' => '幂等标识长度不能超过64个字符',
            'idempotency_key.regex' => '幂等标识格式不正确',
        ];
    }
}
