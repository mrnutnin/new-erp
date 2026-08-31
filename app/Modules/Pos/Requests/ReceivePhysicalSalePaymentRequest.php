<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReceivePhysicalSalePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:500'],
            'allocation_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tenders' => ['required', 'array', 'min:1', 'max:20'],
            'tenders.*.bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'tenders.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            // The sale/Open Item snapshot is authoritative; this POS form never accepts client WHT or allocation totals.
            'gross_amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'withholding_amount' => ['prohibited'],
            'net_amount' => ['prohibited'],
            'allocations' => ['prohibited'],
        ];
    }
}
