<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SavePartyContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(['name', 'position', 'phone', 'email', 'line_id', 'notes', 'permission_note'])->mapWithKeys(fn (string $field) => [$field => trim((string) $this->input($field)) ?: null])->all());
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'decision_role' => ['nullable', Rule::in(['DECISION_MAKER', 'INFLUENCER', 'USER', 'GATEKEEPER', 'OTHER'])],
            'phone' => ['nullable', 'string', 'max:50', 'required_without_all:email,line_id'],
            'email' => ['nullable', 'email', 'max:255', 'required_without_all:phone,line_id'],
            'line_id' => ['nullable', 'string', 'max:100', 'required_without_all:phone,email'],
            'preferred_channel' => ['nullable', Rule::in(['PHONE', 'EMAIL', 'LINE', 'OTHER'])],
            'contact_permission_status' => ['required', Rule::in(['UNKNOWN', 'ALLOWED', 'BLOCKED'])],
            'lawful_basis' => ['nullable', Rule::requiredIf(fn (): bool => $this->input('contact_permission_status') === 'ALLOWED'), Rule::in(['CONSENT', 'CONTRACT', 'LEGITIMATE_INTEREST', 'LEGAL_OBLIGATION'])],
            'allow_phone' => ['nullable', 'boolean'],
            'allow_email' => ['nullable', 'boolean'],
            'allow_line' => ['nullable', 'boolean'],
            'permission_note' => ['nullable', 'string', 'max:500'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('contact_permission_status') === 'ALLOWED' && ! collect(['allow_phone', 'allow_email', 'allow_line'])->contains(fn (string $field) => $this->boolean($field))) {
                $validator->errors()->add('contact_permission_status', 'กรุณาเลือกช่องทางที่อนุญาตให้ติดต่ออย่างน้อย 1 ช่องทาง');
            }
        });
    }
}
