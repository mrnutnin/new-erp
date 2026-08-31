<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\WithholdingRealization;
use App\Modules\Finance\Support\WhtRealizationCalculator;
use Illuminate\Support\Facades\DB;

final class WhtRealizationLedgerService
{
    public function __construct(private readonly AccountMappingService $mappings) {}

    public function record(Allocation $allocation, ?Settlement $settlement, User $actor): ?WithholdingRealization
    {
        return DB::transaction(function () use ($allocation, $settlement, $actor) {
            $allocation = Allocation::query()->with(['debitOpenItem', 'creditOpenItem'])->lockForUpdate()->findOrFail($allocation->id);
            $invoice = $allocation->debitOpenItem?->document_type === 'INVOICE' ? $allocation->debitOpenItem : ($allocation->creditOpenItem?->document_type === 'INVOICE' ? $allocation->creditOpenItem : null);
            if (! $invoice || ! $invoice->withholding_tax_code_id || JournalBalance::decimal($invoice->withholding_amount) === '0.00') {
                return null;
            }
            $existing = WithholdingRealization::query()->where('allocation_id', $allocation->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $date = $allocation->allocation_date->format('Y-m-d');
            $realized = WithholdingRealization::query()->where('open_item_id', $invoice->id)->where('settlement_date', '<=', $date)->sum('tax_amount');
            $allocated = Allocation::query()->where(function ($q) use ($invoice) {
                $q->where('debit_open_item_id', $invoice->id)->orWhere('credit_open_item_id', $invoice->id);
            })
                ->where('id', '!=', $allocation->id)->where('allocation_date', '<=', $date)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))->sum('amount');
            $calc = WhtRealizationCalculator::calculate($invoice->original_amount, $invoice->withholding_base, $invoice->withholding_amount, $allocation->amount, (string) $allocated, (string) $realized);
            $direction = $invoice->ledger_type === 'AR' ? 'RECEIVABLE' : 'PAYABLE';
            $key = $direction === 'RECEIVABLE' ? 'WHT_RECEIVABLE' : 'WHT_PAYABLE';

            return WithholdingRealization::query()->create([
                'allocation_id' => $allocation->id, 'settlement_id' => $settlement?->id, 'open_item_id' => $invoice->id,
                'tax_code_id' => $invoice->withholding_tax_code_id, 'account_id' => $this->mappings->resolve($key)->id,
                'direction' => $direction, 'tax_base' => $calc['base'], 'tax_amount' => $calc['tax'], 'settlement_date' => $date, 'created_by' => $actor->id,
            ]);
        }, 3);
    }
}
