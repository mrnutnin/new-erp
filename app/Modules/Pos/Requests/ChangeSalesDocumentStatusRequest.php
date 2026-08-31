<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSalesDocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Approval is a normal workflow transition. A reason is required only
        // when a user voids a document so the recovery/audit trail explains
        // the destructive action.
        return ['reason' => [$this->routeIs('pos.sales-documents.void') ? 'required' : 'nullable', 'string', 'min:10', 'max:500']];
    }
}
