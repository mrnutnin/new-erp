<?php

namespace App\Modules\Wms\Requests;

use App\Modules\Platform\Services\ModuleCapability;
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
        if (app(ModuleCapability::class)->isEnabled(ModuleCapability::PRODUCTION)) {
            $this->merge(['can_manufacture' => $this->boolean('can_manufacture'), 'can_receive_production_scrap' => $this->boolean('can_receive_production_scrap')]);
        }
    }

    public function rules(): array
    {
        return ['category_id' => ['required', 'integer', 'exists:wms_item_categories,id'], 'code' => ['required', 'string', 'max:50', Rule::unique('wms_items', 'code')->ignore($this->route('item'))], 'name' => ['required', 'string', 'max:255'], 'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_cover_image' => ['nullable', 'boolean'], 'additional_images' => ['nullable', 'array', 'max:5'], 'additional_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'remove_additional_images' => ['nullable', 'array'], 'remove_additional_images.*' => ['integer', 'min:0'], 'item_type' => ['required', Rule::in(['GOODS', 'SERVICE'])], 'base_uom' => ['required', 'string', 'max:30'], 'base_uom_id' => ['nullable', 'integer', 'exists:wms_uoms,id'], 'is_stock_item' => ['required', 'boolean'], 'can_manufacture' => [Rule::excludeIf(fn () => ! app(ModuleCapability::class)->isEnabled(ModuleCapability::PRODUCTION)), 'boolean'], 'can_receive_production_scrap' => [Rule::excludeIf(fn () => ! app(ModuleCapability::class)->isEnabled(ModuleCapability::PRODUCTION)), 'boolean'], 'is_asset_capitalizable' => ['required', 'boolean'], 'default_asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->where('is_active', true), Rule::requiredIf($this->boolean('is_asset_capitalizable'))], 'inventory_account_id' => ['nullable', 'integer'], 'sales_account_id' => ['required', 'integer'], 'cogs_account_id' => ['nullable', 'integer'], 'is_active' => ['required', 'boolean']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $item = $this->route('item');
            $existing = collect($item instanceof Item ? ($item->additional_images ?? []) : []);
            $removed = collect($this->input('remove_additional_images', []))->map(fn ($index) => (int) $index)->unique();
            $remaining = $existing->reject(fn ($image, $index) => $removed->contains($index))->count();
            $uploaded = count($this->file('additional_images', []));

            if ($this->boolean('can_manufacture') && app(ModuleCapability::class)->isEnabled(ModuleCapability::PRODUCTION)
                && ($this->input('item_type') !== 'GOODS' || ! $this->boolean('is_stock_item'))) {
                $validator->errors()->add('can_manufacture', 'สั่งผลิตได้เฉพาะสินค้าที่ติดตามสต็อก');
            }
            if ($this->boolean('can_receive_production_scrap') && app(ModuleCapability::class)->isEnabled(ModuleCapability::PRODUCTION)
                && ($this->input('item_type') !== 'GOODS' || ! $this->boolean('is_stock_item'))) {
                $validator->errors()->add('can_receive_production_scrap', 'รับเศษผลิตได้เฉพาะสินค้า GOODS ที่ติดตามสต็อก');
            }
            if ($remaining + $uploaded > 5) {
                $validator->errors()->add('additional_images', 'ภาพเพิ่มเติมรวมทั้งหมดต้องไม่เกิน 5 ภาพ');
            }
        });
    }
}
