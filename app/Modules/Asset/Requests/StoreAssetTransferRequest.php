<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_date' => ['required', 'date_format:Y-m-d'],
            'destination_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_location_id' => ['nullable', 'integer', 'exists:asset_locations,id'],
            'destination_custodian_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['required', 'integer', 'distinct', 'exists:assets,id'],
            'include_components' => ['nullable', 'boolean'],
        ];
    }
}
