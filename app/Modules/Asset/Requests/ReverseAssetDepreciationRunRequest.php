<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseAssetDepreciationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reversal_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
