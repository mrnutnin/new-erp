<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveAdvanceDepositApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'open_item_id' => ['required', 'integer', 'min:1'],
            'application_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'source_id' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_-]*$/'],
        ];
    }
}
