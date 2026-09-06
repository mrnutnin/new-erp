<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveBillingNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'description' => ['nullable', 'string', 'max:500'],
            'billing_source_keys' => ['required', 'array', 'min:1', 'max:100'],
            'billing_source_keys.*' => ['required', 'string', 'distinct', 'regex:/^(SALES_DOCUMENT|PHYSICAL_SALE):[1-9][0-9]*$/'],
        ];
    }
}
