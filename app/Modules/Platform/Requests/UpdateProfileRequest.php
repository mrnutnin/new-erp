<?php

namespace App\Modules\Platform\Requests;

use App\Modules\Platform\Rules\SignatureDataUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($this->user())],
            'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_profile_image' => ['nullable', 'boolean'],
            'signature_image' => ['nullable', 'prohibited_with:signature_data', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'signature_data' => ['nullable', 'prohibited_with:signature_image', 'max:2800000', new SignatureDataUrl],
            'remove_signature' => ['nullable', 'boolean'],
        ];
    }
}
