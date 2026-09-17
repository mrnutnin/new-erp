<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Models\Opportunity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveOpportunityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'contact_name', 'phone', 'email', 'source', 'notes', 'lost_reason'] as $field) {
            $this->merge([$field => trim((string) $this->input($field)) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'owner_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'stage' => ['required', Rule::in(array_keys(Opportunity::STAGES))],
            'expected_value' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'probability' => ['required', 'integer', 'between:0,100'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
            'next_action_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lost_reason' => [Rule::requiredIf(fn (): bool => $this->input('stage') === 'LOST'), 'nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
