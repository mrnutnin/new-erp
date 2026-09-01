<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAssetDepreciationPolicyChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'policy_change_ids' => ['required', 'array', 'min:1'],
            'policy_change_ids.*' => ['integer', 'distinct', 'exists:asset_depreciation_policy_changes,id'],
        ];
    }
}
