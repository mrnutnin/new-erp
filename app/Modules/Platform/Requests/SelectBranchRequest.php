<?php

namespace App\Modules\Platform\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)]];
    }
}
