<?php

namespace App\Modules\Finance\Requests;

use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $tenders = $this->input('tenders');
        $this->merge([
            'party_id' => trim((string) $this->input('party_id')),
            'description' => trim((string) $this->input('description')) ?: null,
            'allocations' => $this->missing('allocations') ? [] : $this->input('allocations'),
            'tenders' => $this->missing('tenders') ? [['bank_account_id' => $this->input('bank_account_id'), 'amount' => $this->input('net_amount'), 'reference' => null]] : $tenders,
            'bank_account_id' => data_get($tenders, '0.bank_account_id', $this->input('bank_account_id')),
        ]);

        $bankAccountId = data_get($tenders, '0.bank_account_id', $this->input('bank_account_id'));
        if ($bankAccountId && $this->attributes->get('selectedBranch')) {
            $warehouse = BankAccount::query()
                ->whereKey($bankAccountId)
                ->where('is_active', true)
                ->whereHas('warehouse', fn ($query) => $query
                    ->where('branch_id', $this->attributes->get('selectedBranch')->id)
                    ->where('is_active', true))
                ->first()?->warehouse;
            if ($warehouse && $this->user()?->warehouses()->whereKey($warehouse->id)->where('is_active', true)->exists()) {
                $this->attributes->set('selectedWarehouse', $warehouse);
            }
        }
    }

    public function rules(): array
    {
        $warehouseId = $this->attributes->get('selectedWarehouse')->id;

        return [
            'document_type' => ['required', Rule::in(['RECEIPT', 'PAYMENT'])],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'settlement_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'party_type' => ['required', Rule::in(['CUSTOMER', 'SUPPLIER', 'OTHER'])],
            'party_id' => ['required', 'string', 'max:100'],
            'bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where(fn ($query) => $query->where('warehouse_id', $warehouseId)->where('is_active', true))],
            'tenders' => ['required', 'array', 'min:1', 'max:20'],
            'tenders.*.bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where(fn ($query) => $query->where('warehouse_id', $warehouseId)->where('is_active', true))],
            'tenders.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('finance_payment_terms', 'id')->where('is_active', true)],
            'gross_amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'tax_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'withholding_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'net_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'allocations' => ['nullable', 'array', 'max:100'],
            'allocations.*.open_item_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (is_array($this->input('allocations')) && $this->input('allocations') !== []
                && in_array($this->input('document_type'), ['RECEIPT', 'PAYMENT'], true)) {
                $expectedParty = $this->input('document_type') === 'RECEIPT' ? 'CUSTOMER' : 'SUPPLIER';
                if ($this->input('party_type') !== $expectedParty) {
                    $validator->errors()->add('party_type', "เอกสาร {$this->input('document_type')} ที่จัดสรรยอดต้องใช้คู่ค้า {$expectedParty}");
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $gross = JournalBalance::totals([['debit' => $this->input('gross_amount'), 'credit' => '0']])['debit'];
            $tax = JournalBalance::totals([['debit' => $this->input('tax_amount'), 'credit' => '0']])['debit'];
            $withholding = JournalBalance::totals([['debit' => $this->input('withholding_amount'), 'credit' => '0']])['debit'];
            $net = JournalBalance::totals([['debit' => $this->input('net_amount'), 'credit' => '0']])['debit'];

            if ($tax > $gross) {
                $validator->errors()->add('tax_amount', 'ยอดภาษีต้องไม่เกินยอดรวมเอกสาร');
            }
            if ($withholding > $gross) {
                $validator->errors()->add('withholding_amount', 'ยอดหัก ณ ที่จ่ายต้องไม่เกินยอดรวมเอกสาร');
            }
            if ($gross - $withholding !== $net) {
                $validator->errors()->add('net_amount', 'ยอดสุทธิต้องเท่ากับยอดรวมเอกสารหักยอดหัก ณ ที่จ่าย');
            }
            $tenderTotal = collect($this->input('tenders', []))->reduce(fn (string $total, array $tender) => JournalBalance::add($total, $tender['amount'] ?? '0'), '0.00');
            $tenderCents = JournalBalance::totals([['debit' => $tenderTotal, 'credit' => '0']])['debit'];
            if ($tenderCents !== $net) {
                $validator->errors()->add('tenders', 'ยอดช่องทางรับ/จ่ายต้องเท่ากับยอดสุทธิหลังหัก ณ ที่จ่าย');
            }
            $allocationTotal = collect($this->input('allocations', []))->reduce(
                fn (string $total, array $allocation) => JournalBalance::add($total, $allocation['amount'] ?? '0'),
                '0.00',
            );
            $allocationCents = JournalBalance::totals([['debit' => $allocationTotal, 'credit' => '0']])['debit'];
            if ($allocationCents > $gross) {
                $validator->errors()->add('allocations', 'ยอดจัดสรรต้องไม่เกินยอดเอกสาร');
            }
            if ($this->input('document_type') === 'PAYMENT' && $allocationCents !== $gross) {
                $validator->errors()->add('allocations', 'เอกสารจ่ายต้องจัดสรรยอดให้ครบตามยอดเอกสาร');
            }
        }];
    }
}
