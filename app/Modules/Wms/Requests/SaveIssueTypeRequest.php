<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveIssueTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->route('issueType');

        return ['code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('wms_issue_types', 'code')->where('warehouse_id', $this->attributes->get('selectedWarehouse')?->id)->ignore($type)], 'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['nullable', 'boolean']];
    }
}
