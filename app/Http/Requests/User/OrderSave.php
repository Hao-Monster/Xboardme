<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('customer_name'))) {
            $this->merge([
                'customer_name' => trim($this->input('customer_name')),
            ]);
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
            'customer_name' => $this->user()?->is_distributor
                ? 'required|string|max:64'
                : 'nullable|string|max:64',
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period'),
            'customer_name.required' => '为了售后方便，请输入备注清楚用户',
            'customer_name.max' => '用户名称不能超过64个字符',
        ];
    }
}
