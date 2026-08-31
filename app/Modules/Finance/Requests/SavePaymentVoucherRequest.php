<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commission_request_id' => ['nullable', 'integer', Rule::exists('finance_commission_payment_requests', 'id')],
            'voucher_type' => ['required', Rule::in(['PRE_PAYMENT', 'PAYMENT'])],
            'document_date' => ['required', 'date'],
            'party_id' => ['required_if:voucher_type,PAYMENT', 'nullable', 'integer', Rule::exists('parties', 'id')->where('is_active', true)],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('finance_bank_accounts', 'id')->where(fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('warehouse_id', $this->user()->warehouses()->where('is_active', true)
                    ->where('branch_id', $this->attributes->get('selectedBranch')->id)->pluck('warehouses.id')))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.open_item_id' => ['nullable', 'integer', 'distinct'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
