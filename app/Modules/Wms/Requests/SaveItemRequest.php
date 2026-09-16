<?php

namespace App\Modules\Wms\Requests;

use App\Modules\Wms\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code'))), 'name' => trim((string) $this->input('name')), 'base_uom' => strtoupper(trim((string) $this->input('base_uom'))), 'is_stock_item' => $this->boolean('is_stock_item'), 'is_asset_capitalizable' => $this->boolean('is_asset_capitalizable'), 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        return ['category_id' => ['required', 'integer', 'exists:wms_item_categories,id'], 'code' => ['required', 'string', 'max:50', Rule::unique('wms_items', 'code')->ignore($this->route('item'))], 'name' => ['required', 'string', 'max:255'], 'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_cover_image' => ['nullable', 'boolean'], 'additional_images' => ['nullable', 'array', 'max:5'], 'additional_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_additional_images' => ['nullable', 'array'], 'remove_additional_images.*' => ['integer', 'min:0'], 'item_type' => ['required', Rule::in(['GOODS', 'SERVICE'])], 'base_uom' => ['required', 'string', 'max:30'], 'base_uom_id' => ['nullable', 'integer', 'exists:wms_uoms,id'], 'is_stock_item' => ['required', 'boolean'], 'is_asset_capitalizable' => ['required', 'boolean'], 'default_asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->where('is_active', true), Rule::requiredIf($this->boolean('is_asset_capitalizable'))], 'inventory_account_id' => ['nullable', 'integer'], 'sales_account_id' => ['required', 'integer'], 'cogs_account_id' => ['nullable', 'integer'], 'is_active' => ['required', 'boolean']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $item = $this->route('item');
            $existing = collect($item instanceof Item ? ($item->additional_images ?? []) : []);
            $removed = collect($this->input('remove_additional_images', []))->map(fn ($index) => (int) $index)->unique();
            $remaining = $existing->reject(fn ($image, $index) => $removed->contains($index))->count();
            $uploaded = count($this->file('additional_images', []));

            if ($remaining + $uploaded > 5) {
                $validator->errors()->add('additional_images', 'ภาพเพิ่มเติมรวมทั้งหมดต้องไม่เกิน 5 ภาพ');
            }
        });
    }
}
