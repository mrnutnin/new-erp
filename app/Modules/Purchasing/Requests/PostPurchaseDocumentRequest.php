<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostPurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    protected function prepareForValidation(): void { $this->merge(['posting_date' => trim((string) $this->input('posting_date'))]); }
    public function rules(): array { $document = $this->route('purchaseDocument'); return ['posting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$document->document_date->format('Y-m-d')]]; }
}
