<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'name' => trim((string) $this->input('name')), 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:20', Rule::unique('wms_uoms', 'code')->ignore($this->route('uom'))], 'name' => ['required', 'string', 'max:100'], 'decimal_places' => ['required', 'integer', 'min:0', 'max:6'], 'is_active' => ['required', 'boolean']];
    }
}
