<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'name' => trim((string) $this->input('name')), 'base_uom' => strtoupper(trim((string) $this->input('base_uom'))), 'is_stock_item' => $this->boolean('is_stock_item'), 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        return ['category_id' => ['required', 'integer', 'exists:wms_item_categories,id'], 'code' => ['required', 'string', 'max:50', Rule::unique('wms_items', 'code')->ignore($this->route('item'))], 'name' => ['required', 'string', 'max:255'], 'item_type' => ['required', Rule::in(['GOODS', 'SERVICE'])], 'base_uom' => ['required', 'string', 'max:30'], 'base_uom_id' => ['nullable', 'integer', 'exists:wms_uoms,id'], 'is_stock_item' => ['required', 'boolean'], 'inventory_account_id' => ['nullable', 'integer'], 'sales_account_id' => ['required', 'integer'], 'cogs_account_id' => ['nullable', 'integer'], 'is_active' => ['required', 'boolean']];
    }
}
