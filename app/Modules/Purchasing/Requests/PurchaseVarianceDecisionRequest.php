<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseVarianceDecisionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void { $this->merge(['reason' => trim((string) $this->input('reason'))]); }
    public function rules(): array { return ['reason' => ['required', 'string', 'min:10', 'max:500']]; }
}
