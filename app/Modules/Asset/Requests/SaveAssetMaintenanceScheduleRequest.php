<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveAssetMaintenanceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['asset_id' => ['required', 'integer', 'exists:assets,id'], 'title' => ['required', 'string', 'max:255'], 'interval_days' => ['nullable', 'integer', 'min:1', 'required_without:interval_months'], 'interval_months' => ['nullable', 'integer', 'min:1', 'required_without:interval_days'], 'next_due_date' => ['required', 'date_format:Y-m-d'], 'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'], 'default_priority' => ['required', 'in:LOW,NORMAL,HIGH,CRITICAL'], 'is_active' => ['nullable', 'boolean']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('interval_days') && $this->filled('interval_months')) {
                $validator->errors()->add('interval_days', 'เลือกกำหนดรอบเป็นวันหรือเดือนเพียงอย่างเดียว');
            }
        });
    }
}
