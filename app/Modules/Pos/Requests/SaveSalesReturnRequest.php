<?php

namespace App\Modules\Pos\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;

class SaveSalesReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason')) ?: null]);
    }

    public function rules(): array
    {
        return ['physical_sale_id' => ['required', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.physical_sale_line_id' => ['required', 'integer', 'min:1'], 'lines.*.quantity' => [...WmsDecimal::rule(), 'gt:0']];
    }
}
