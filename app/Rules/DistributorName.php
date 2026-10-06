<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DistributorName implements ValidationRule
{
    public const MAX_LENGTH = 16;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/\p{C}/u', $value)) {
            $fail('分销商名称不能包含控制字符');
            return;
        }

        // Match browser maxlength and Dart String.length, including supplementary characters.
        if (strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2 > self::MAX_LENGTH) {
            $fail('分销商名称不能超过16个字符');
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $fail('分销商名称请填写商户名称，不要使用邮箱');
        }
    }
}
