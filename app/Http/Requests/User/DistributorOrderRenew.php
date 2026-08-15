<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class DistributorOrderRenew extends FormRequest
{
    public function rules(): array
    {
        return [
            'trade_no' => 'required|string|max:64',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price',
            'idempotency_key' => 'required|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'trade_no.required' => '请选择需要续费的订阅',
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => '该套餐周期不支持续费',
            'idempotency_key.required' => '续费请求标识不能为空',
            'idempotency_key.uuid' => '续费请求标识格式不正确',
        ];
    }
}
