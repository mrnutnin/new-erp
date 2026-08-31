<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSettlementStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim($this->string('reason')->toString())]);
    }

    public function rules(): array
    {
        // Approval needs only a deliberate confirmation. Require a reason for
        // void because it is the destructive transition retained in history.
        return ['reason' => [$this->routeIs('finance.settlements.void') ? 'required' : 'nullable', 'string', 'min:10', 'max:500']];
    }
}
