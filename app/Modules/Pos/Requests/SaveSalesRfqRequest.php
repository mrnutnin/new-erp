<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveSalesRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => trim((string) $this->input('description')) ?: null,
            'lines' => collect($this->input('lines', []))->map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                $line['description'] = trim((string) ($line['description'] ?? '')) ?: null;

                return $line;
            })->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_number' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.item_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.uom_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
        ];
    }
}
