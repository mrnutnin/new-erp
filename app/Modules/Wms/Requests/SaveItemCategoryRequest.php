<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItemCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:30', Rule::unique('wms_item_categories', 'code')->ignore($this->route('category'))], 'name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']];
    }
}
