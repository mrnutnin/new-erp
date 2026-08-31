<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSalesCommissionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $assignments = collect($this->input('assignments', []))->map(function (array $assignment): array {
            $assignment['branch_id'] = $this->attributes->get('selectedBranch')?->id;

            return $assignment;
        })->values()->all();

        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'basis' => mb_strtoupper(trim((string) $this->input('basis'))),
            'is_active' => (bool) $this->input('is_active', true),
            'assignments' => $assignments,
        ]);
    }

    public function rules(): array
    {
        $plan = $this->route('salesCommissionPlan');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('pos_sales_commission_plans', 'code')->ignore($plan)],
            'name' => ['required', 'string', 'max:255'],
            'basis' => ['required', Rule::in(['POSTED_SALE', 'COLLECTED_RECEIPT', 'GROSS_PROFIT'])],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'assignments.*.branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $seen = [];
            foreach ((array) $this->input('assignments', []) as $index => $assignment) {
                $key = ($assignment['user_id'] ?? '').'|'.($assignment['branch_id'] ?? 'all');
                if (isset($seen[$key])) {
                    $validator->errors()->add("assignments.$index.user_id", 'ผู้รับคอมมิชชั่นและสาขาซ้ำกันในแผนเดียวกัน');
                }
                $seen[$key] = true;
            }
        });
    }
}
