<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelAssetDepreciationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['cancellation_reason' => ['required', 'string', 'min:10', 'max:500']];
    }
}
