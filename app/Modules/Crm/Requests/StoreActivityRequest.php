<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge(['subject' => trim((string) $this->input('subject')), 'details' => trim((string) $this->input('details')) ?: null]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['CALL', 'MEETING', 'TASK', 'NOTE'])],
            'subject' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }
}
