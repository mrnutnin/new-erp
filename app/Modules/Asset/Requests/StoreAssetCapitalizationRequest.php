<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetCapitalizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_id' => $this->attributes->get('selectedBranch')?->id,
            'description' => $this->filled('description') ? trim($this->string('description')->toString()) : null,
            'is_manual_exception' => $this->boolean('is_manual_exception'),
            'manual_exception_reason' => $this->filled('manual_exception_reason') ? trim($this->string('manual_exception_reason')->toString()) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'document_date' => ['required', 'date'],
            'source_type' => ['required', Rule::in(['PURCHASE_DOCUMENT', 'MANUAL_RECLASS'])],
            'source_id' => ['nullable', 'integer'],
            'is_manual_exception' => ['required', 'boolean'],
            'manual_exception_reason' => ['nullable', 'string', 'min:10', 'max:500', Rule::requiredIf($this->boolean('is_manual_exception'))],
            'description' => ['nullable', 'string', 'min:10', 'max:500', Rule::requiredIf($this->input('source_type') === 'MANUAL_RECLASS')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->whereNull('deleted_at')],
            'lines.*.capitalized_cost' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999999.99'],
            'lines.*.clearing_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)->where('is_postable', true)->whereNull('control_account_type')],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.source_type' => ['nullable', 'string', 'max:30'],
            'lines.*.source_id' => ['nullable', 'integer'],
            'lines.*.source_line_id' => ['nullable', 'integer'],
        ];
    }
}
