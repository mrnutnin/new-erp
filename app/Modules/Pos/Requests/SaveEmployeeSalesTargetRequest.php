<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeSalesTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'period_start' => (string) $this->input('period_start'),
            'period_end' => (string) $this->input('period_end'),
            'sales_target' => $this->input('sales_target', 0),
            'gross_profit_target' => $this->input('gross_profit_target', 0),
        ]);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'sales_target' => ['required', 'numeric', 'gte:0'],
            'gross_profit_target' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
