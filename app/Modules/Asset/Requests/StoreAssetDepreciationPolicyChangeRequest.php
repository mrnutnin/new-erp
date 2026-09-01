<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetDepreciationPolicyChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'book_type' => ['required', Rule::in(['BOOK', 'TAX'])],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer', 'distinct', 'exists:assets,id'],
            'method' => ['required', Rule::in(['STRAIGHT_LINE'])],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'residual_value' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
