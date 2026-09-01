<?php

namespace App\Modules\Asset\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['freeze_date' => ['required', 'date_format:Y-m-d'], 'location_id' => ['nullable', 'integer', 'exists:asset_locations,id'], 'asset_category_id' => ['nullable', 'integer', 'exists:asset_categories,id']];
    }
}
