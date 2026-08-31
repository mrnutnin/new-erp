<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StageAccountImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_system' => ['required', 'in:express,winspeed,minterp,other'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ];
    }
}
