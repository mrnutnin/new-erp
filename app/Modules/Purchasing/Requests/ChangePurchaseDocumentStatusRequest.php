<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePurchaseDocumentStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void { $this->merge(['reason' => trim((string) $this->input('reason'))]); }
    public function rules(): array { $isApprove = $this->routeIs('purchasing.purchase-documents.approve'); return ['reason' => [$isApprove ? 'nullable' : 'required', 'string', $isApprove ? 'max:500' : 'min:10', 'max:500']]; }
}
