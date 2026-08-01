<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DistributorOrderEntitlementUpdate extends FormRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|min:1',
            'transfer_enable' => 'required|integer|min:0',
            'expired_at' => 'present|nullable|integer',
            'speed_limit' => 'present|nullable|integer|min:0',
            'device_limit' => 'present|nullable|integer|min:0',
        ];
    }
}
