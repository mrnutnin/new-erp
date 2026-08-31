<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSalesQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['lines' => collect($this->input('lines', []))->map(fn ($line) => is_array($line) ? array_merge($line, ['description' => trim((string) ($line['description'] ?? ''))]) : $line)->all()]);
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
        ];
    }
}
