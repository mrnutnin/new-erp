<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePhysicalSaleNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['description' => trim((string) $this->input('description')) ?: null]);
    }

    public function rules(): array
    {
        return ['description' => ['nullable', 'string', 'max:500']];
    }
}
