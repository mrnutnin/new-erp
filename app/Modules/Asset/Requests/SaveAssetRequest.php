<?php

namespace App\Modules\Asset\Requests;

use App\Models\User;
use App\Modules\Asset\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_id' => $this->attributes->get('selectedBranch')?->id,
            'tag_number' => $this->nullableTrimmed('tag_number'),
            'barcode_value' => $this->nullableTrimmed('barcode_value'),
            'name' => trim($this->string('name')->toString()),
            'description' => $this->nullableTrimmed('description'),
            'brand' => $this->nullableTrimmed('brand'),
            'model' => $this->nullableTrimmed('model'),
            'serial_number' => $this->nullableTrimmed('serial_number'),
            'manufacturer' => $this->nullableTrimmed('manufacturer'),
            'insurance_policy_number' => $this->nullableTrimmed('insurance_policy_number'),
            'warehouse_id' => $this->nullableInteger('warehouse_id'),
            'location_id' => $this->nullableInteger('location_id'),
            'custodian_user_id' => $this->nullableInteger('custodian_user_id'),
            'parent_asset_id' => $this->nullableInteger('parent_asset_id'),
            'supplier_id' => $this->nullableInteger('supplier_id'),
            'is_depreciation_suspended' => $this->boolean('is_depreciation_suspended'),
            'status_reason' => $this->nullableTrimmed('status_reason'),
        ]);
    }

    public function rules(): array
    {
        $asset = $this->asset();
        $branchId = $this->integer('branch_id');

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'registration_date' => ['required', 'date'],
            'asset_category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)->where('branch_id', $branchId))],
            'location_id' => ['nullable', 'integer', Rule::exists('asset_locations', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)->where('branch_id', $branchId))],
            'custodian_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'parent_asset_id' => ['nullable', 'integer', Rule::exists('assets', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('branch_id', $branchId))],
            'tag_number' => ['nullable', 'string', 'max:100', Rule::unique('assets', 'tag_number')->ignore($asset)],
            'barcode_value' => ['nullable', 'string', 'max:100', Rule::unique('assets', 'barcode_value')->ignore($asset)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'acquisition_date' => ['required', 'date'],
            'placed_in_service_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('parties', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'warranty_end_date' => ['nullable', 'date'],
            'insurance_policy_number' => ['nullable', 'string', 'max:255'],
            'insurance_end_date' => ['nullable', 'date'],
            'original_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'],
            'currency_code' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'is_depreciation_suspended' => ['required', 'boolean'],
            'status_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->filled('custodian_user_id') && ! $validator->errors()->has('custodian_user_id')
                && ! User::query()->whereKey($this->integer('custodian_user_id'))->whereHas('warehouses', fn ($query) => $query
                    ->where('warehouses.branch_id', $this->integer('branch_id'))->where('warehouses.is_active', true))->exists()) {
                $validator->errors()->add('custodian_user_id', 'ผู้ดูแลต้องมีสิทธิ์ใช้งานสาขาที่เลือก');
            }

            if (($asset = $this->asset()) && $this->integer('parent_asset_id') === $asset->id) {
                $validator->errors()->add('parent_asset_id', 'ไม่สามารถเลือกสินทรัพย์เดียวกันเป็นสินทรัพย์หลักได้');
            }
        }];
    }

    private function asset(): ?Asset
    {
        $asset = $this->route('asset');

        return $asset instanceof Asset ? $asset : null;
    }

    private function nullableInteger(string $key): ?int
    {
        return $this->filled($key) ? $this->integer($key) : null;
    }

    private function nullableTrimmed(string $key): ?string
    {
        return $this->filled($key) ? trim($this->string($key)->toString()) : null;
    }
}
