<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSalesQuotationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $needsReason = $this->routeIs('pos.sales-quotations.reject', 'pos.sales-quotations.cancel');

        return [
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'min:10', 'max:500'],
        ];
    }
}
