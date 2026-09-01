<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['required', 'integer', 'exists:assets,id'], 'reported_date' => ['required', 'date_format:Y-m-d'],
            'maintenance_type' => ['required', 'in:CORRECTIVE,PREVENTIVE,INSPECTION'], 'priority' => ['required', 'in:LOW,NORMAL,HIGH,CRITICAL'],
            'issue' => ['required', 'string', 'min:10', 'max:1000'], 'vendor_party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'is_under_warranty' => ['nullable', 'boolean'], 'planned_date' => ['nullable', 'date_format:Y-m-d'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'takes_asset_out_of_service' => ['nullable', 'boolean'],
        ];
    }
}
