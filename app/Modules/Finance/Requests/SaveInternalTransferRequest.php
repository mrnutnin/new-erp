<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInternalTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $warehouseId = $this->attributes->get('selectedWarehouse')?->id;

        return [
            'document_date' => ['required', 'date_format:Y-m-d'],
            'source_bank_account_id' => ['required', 'integer', 'different:destination_bank_account_id', Rule::exists('finance_bank_accounts', 'id')->where(fn ($query) => $query->where('warehouse_id', $warehouseId)->where('is_active', true))],
            'destination_bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where(fn ($query) => $query->where('warehouse_id', $warehouseId)->where('is_active', true))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
