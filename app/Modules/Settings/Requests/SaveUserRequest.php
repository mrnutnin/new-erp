<?php

namespace App\Modules\Settings\Requests;

use App\Modules\Platform\Rules\SignatureDataUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->filled('email') ? strtolower(trim($this->string('email')->toString())) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users')->ignore($user)],
            'employee_code' => ['nullable', 'string', 'max:100', Rule::unique('users')->ignore($user)],
            'position' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_profile_image' => ['nullable', 'boolean'],
            'signature_image' => ['nullable', 'prohibited_with:signature_data', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'signature_data' => ['nullable', 'prohibited_with:signature_image', 'max:2800000', new SignatureDataUrl],
            'remove_signature' => ['nullable', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'primary_branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['integer', 'exists:programs,id'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => [
                'integer',
                Rule::exists('roles', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
        ];
    }
}
