<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\TaxRealization;
use App\Modules\Finance\Support\VatRealizationCalculator;
use Illuminate\Support\Facades\DB;

final class VatRealizationLedgerService
{
    public function __construct(private readonly AccountMappingService $mappings) {}

    public function record(Allocation $allocation, Settlement $settlement, User $actor): ?TaxRealization
    {
        return DB::transaction(function () use ($allocation, $settlement, $actor) {
            $allocation = Allocation::query()->with(['debitOpenItem', 'creditOpenItem'])->lockForUpdate()->findOrFail($allocation->id);
            $invoice = $allocation->debitOpenItem?->document_type === 'INVOICE' ? $allocation->debitOpenItem : ($allocation->creditOpenItem?->document_type === 'INVOICE' ? $allocation->creditOpenItem : null);
            if (! $invoice || ! in_array($invoice->tax_kind, ['VAT_IN', 'VAT_OUT'], true) || ! $invoice->tax_amount || JournalBalance::decimal($invoice->tax_amount) === '0.00') {
                return null;
            }
            $existing = TaxRealization::query()->where('allocation_id', $allocation->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $asOf = $allocation->allocation_date->format('Y-m-d');
            $taxBefore = TaxRealization::query()->where('open_item_id', $invoice->id)
                ->where('settlement_date', '<=', $asOf)->sum('tax_amount');
            $allocatedBefore = Allocation::query()->where(function ($query) use ($invoice) {
                $query->where('debit_open_item_id', $invoice->id)->orWhere('credit_open_item_id', $invoice->id);
            })->where('id', '!=', $allocation->id)->where('allocation_date', '<=', $asOf)
                ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $asOf))->sum('amount');
            $calculated = VatRealizationCalculator::calculate($invoice->original_amount, $invoice->tax_amount, $allocation->amount, $allocatedBefore, $taxBefore);
            $deferred = $this->sourceDeferredVatAccount($invoice);
            $actual = $invoice->tax_kind === 'VAT_OUT' && $settlement->document_type === 'RECEIPT'
                ? $this->mappings->resolveForEvent('customer_payment', 'OUTPUT_VAT')['account']
                : ($invoice->tax_kind === 'VAT_IN' && $settlement->document_type === 'PAYMENT'
                    ? $this->mappings->resolveForEvent('supplier_payment', 'INPUT_VAT')['account']
                    : throw new \RuntimeException('Settlement ไม่ตรงกับทิศทาง VAT ของ Open Item'));
            $realization = TaxRealization::query()->create([
                'allocation_id' => $allocation->id, 'settlement_id' => $settlement?->id, 'open_item_id' => $invoice->id,
                'tax_kind' => $invoice->tax_kind, 'tax_code_id' => $invoice->tax_code_id,
                'deferred_account_id' => $deferred->id, 'actual_account_id' => $actual->id,
                'tax_base' => $calculated['base'], 'tax_amount' => $calculated['tax'], 'tax_point_date' => $invoice->tax_point_date ?? $invoice->document_date,
                'settlement_date' => $allocation->allocation_date, 'created_by' => $actor->id,
            ]);

            return $realization;
        }, 3);
    }

    private function sourceDeferredVatAccount($invoice)
    {
        $journalEntryId = JournalEntryLine::query()->whereKey($invoice->journal_entry_line_id)->value('journal_entry_id');
        $accounts = $journalEntryId ? JournalEntryLine::query()->with('account.type')
            ->where('journal_entry_id', $journalEntryId)->where('tax_code_id', $invoice->tax_code_id)->where('subledger_type', 'TAX')
            ->get()->pluck('account')->filter()->unique('id')->values() : collect();
        if ($accounts->count() !== 1) {
            throw new \RuntimeException('ต้องพบบัญชีภาษีพักรอรับรู้จาก Journal ใบกำกับต้นทางเพียงหนึ่งบัญชี');
        }
        $account = $accounts->sole();
        $this->mappings->assertCompatible($invoice->tax_kind === 'VAT_IN' ? 'DEFERRED_INPUT_VAT' : 'DEFERRED_OUTPUT_VAT', $account);

        return $account;
    }
}
