<?php

namespace App\Modules\Asset\Requests;

use App\Modules\Asset\Models\AssetLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAssetLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $branch = $this->attributes->get('selectedBranch');

        $this->merge([
            // Locations always belong to the branch context, never to a client-provided branch id.
            'branch_id' => $branch?->id,
            'code' => strtoupper(trim($this->string('code')->toString())),
            'name' => trim($this->string('name')->toString()),
            'address' => $this->filled('address') ? trim($this->string('address')->toString()) : null,
            'parent_id' => $this->filled('parent_id') ? $this->integer('parent_id') : null,
            'warehouse_id' => $this->filled('warehouse_id') ? $this->integer('warehouse_id') : null,
            'location_type' => strtoupper(trim($this->string('location_type')->toString() ?: 'OTHER')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $location = $this->location();
        $branchId = $this->integer('branch_id');

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))],
            'parent_id' => ['nullable', 'integer', Rule::exists('asset_locations', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('branch_id', $branchId))],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->where('is_active', true)->where('branch_id', $branchId))],
            'code' => ['required', 'string', 'max:30', Rule::unique('asset_locations', 'code')->where('branch_id', $branchId)->ignore($location)],
            'name' => ['required', 'string', 'max:255'],
            'location_type' => ['required', 'in:BRANCH,WAREHOUSE,BUILDING,FLOOR,ROOM,SITE,OTHER'],
            'address' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $location = $this->location();
            if ($location && ! $validator->errors()->has('parent_id') && $location->wouldCreateCycle($this->input('parent_id'))) {
                $validator->errors()->add('parent_id', 'ไม่สามารถเลือกสถานที่ย่อยของตนเองเป็นสถานที่แม่');
            }
        }];
    }

    private function location(): ?AssetLocation
    {
        $location = $this->route('assetLocation') ?? $this->route('location');

        return $location instanceof AssetLocation ? $location : null;
    }
}
