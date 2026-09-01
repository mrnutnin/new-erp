<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetDepreciationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_id' => $this->attributes->get('selectedBranch')?->id,
        ]);
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'fiscal_period_id' => ['required', 'integer', 'exists:fiscal_periods,id'],
            'book_type' => ['required', Rule::in(['BOOK', 'TAX'])],
            'run_through_date' => ['required', 'date_format:Y-m-d'],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'distinct', 'exists:assets,id'],
            'exclusion_reasons' => ['nullable', 'array'],
            'exclusion_reasons.*' => ['nullable', 'string', 'max:500'],
        ];
    }
}
