<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PettyCashActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
            'reversal_date' => [str_ends_with((string) $this->route()?->getName(), '.reverse') ? 'required' : 'nullable', 'date'],
        ];
    }
}
