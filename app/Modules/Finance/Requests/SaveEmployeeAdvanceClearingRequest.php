<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveEmployeeAdvanceClearingRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['employee_advance_id' => ['required', 'integer', Rule::exists('finance_employee_advances', 'id')->whereIn('status', ['POSTED', 'PARTIAL'])], 'document_date' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:1000'], 'is_final' => ['sometimes', 'boolean'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.expense_category_id' => ['required', 'integer', 'exists:finance_other_categories,id'], 'lines.*.description' => ['nullable', 'string', 'max:500'], 'lines.*.receipt_reference' => ['nullable', 'string', 'max:100'], 'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'], 'lines.*.tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'], 'lines.*.withholding_tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id']];
    }
}
