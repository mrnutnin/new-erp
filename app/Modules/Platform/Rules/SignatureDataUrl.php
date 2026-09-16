<?php

namespace App\Modules\Platform\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SignatureDataUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_starts_with($value, 'data:image/png;base64,')) {
            $fail('รูปแบบลายเซ็นที่วาดไม่ถูกต้อง');

            return;
        }

        $contents = base64_decode(substr($value, 22), true);
        $image = is_string($contents) ? @getimagesizefromstring($contents) : false;

        if (! is_string($contents) || strlen($contents) > 2 * 1024 * 1024 || ($image['mime'] ?? null) !== 'image/png') {
            $fail('ลายเซ็นที่วาดต้องเป็นภาพ PNG ขนาดไม่เกิน 2 MB');
        }
    }
}
