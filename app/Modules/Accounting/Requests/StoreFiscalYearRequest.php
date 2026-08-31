<?php

namespace App\Modules\Accounting\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim($this->string('code')->toString())),
            'name' => trim($this->string('name')->toString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('fiscal_years')],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['bail', 'required', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail) {
                if (CarbonImmutable::parse($value)->day !== 1) {
                    $fail('วันเริ่มปีบัญชีต้องเป็นวันแรกของเดือน');
                }
            }],
        ];
    }
}
