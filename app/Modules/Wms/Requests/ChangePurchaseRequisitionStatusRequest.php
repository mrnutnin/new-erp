<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePurchaseRequisitionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
