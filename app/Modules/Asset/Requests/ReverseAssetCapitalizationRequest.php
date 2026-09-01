<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseAssetCapitalizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reversal_date' => ['required', 'date'],
            'reversal_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
