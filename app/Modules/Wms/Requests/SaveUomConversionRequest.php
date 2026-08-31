<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveUomConversionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['from_uom_id' => ['required', 'integer', 'exists:wms_uoms,id'], 'to_uom_id' => ['required', 'integer', 'different:from_uom_id', 'exists:wms_uoms,id'], 'factor' => ['required', 'numeric', 'gt:0', 'decimal:0,8'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from']];
    }
}
