<?php

namespace App\Modules\Finance\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\SettlementPostingService as JournalSettlementPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\TaxRealization;
use App\Modules\Finance\Support\SettlementState;
use App\Modules\Finance\Support\VatRealizationCalculator;
use App\Modules\Finance\Support\VatRealizationJournalLines;
use App\Modules\Finance\Support\WhtRealizationCalculator;
use App\Modules\Pos\Services\CommissionCalculationService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettlementPostingService
{
    public function __construct(
        private readonly JournalSettlementPostingService $journalPosting,
        private readonly OpenItemService $openItems,
        private readonly VatRealizationLedgerService $vatRealizations,
        private readonly WhtRealizationLedgerService $whtRealizations,
        private readonly AccountMappingService $mappings,
        private readonly CommissionCalculationService $commissions,
    ) {}

    public function post(Settlement $settlement, Warehouse $warehouse, User $actor, Request $request): Settlement
    {
        return DB::transaction(function () use ($settlement, $warehouse, $actor) {
            $settlement = Settlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            $contract = $settlement->document_type === 'RECEIPT'
                ? ['event' => 'customer_payment', 'ledger' => 'AR', 'party_type' => 'CUSTOMER', 'target_side' => 'DEBIT', 'payment_side' => 'CREDIT', 'bank_debit' => true]
                : ['event' => 'supplier_payment', 'ledger' => 'AP', 'party_type' => 'SUPPLIER', 'target_side' => 'CREDIT', 'payment_side' => 'DEBIT', 'bank_debit' => false];

            if ($settlement->status === 'POSTED') {
                $settlement = $this->verifyRetry($settlement, $warehouse, $contract);
                if ($settlement->document_type === 'RECEIPT') {
                    $this->commissions->calculateCollectedReceipt($settlement);
                }

                return $settlement;
            }
            try {
                SettlementState::post($settlement->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }

            $gross = JournalBalance::decimal($settlement->gross_amount);
            $net = JournalBalance::decimal($settlement->net_amount);
            if (JournalBalance::decimal($settlement->tax_amount) !== '0.00' || $gross === '0.00') {
                throw ValidationException::withMessages(['gross_amount' => 'MVP รองรับเอกสาร NONE VAT และ VAT realization จาก Open Item เท่านั้น']);
            }

            $tenders = $settlement->tenders()->with('bankAccount.account')->orderBy('line_number')->lockForUpdate()->get();
            if ($tenders->isEmpty()) {
                $tenders = collect([(object) ['amount' => $net, 'bankAccount' => BankAccount::query()->with('account')->whereKey($settlement->bank_account_id)->where('warehouse_id', $warehouse->id)->where('is_active', true)->lockForUpdate()->first()]]);
            }
            $party = Party::query()->whereKey($settlement->party_id)->where('is_active', true)->sharedLock()->first();
            $role = $party ? PartyRole::query()->where('party_id', $party->id)->where('role', $contract['party_type'])->where('is_active', true)->sharedLock()->first() : null;
            foreach ($tenders as $tender) {
                $bank = $tender->bankAccount;
                if (! $bank || $bank->warehouse_id !== $warehouse->id || ! $bank->is_active || ! $bank->account || ! $bank->account->is_active || ! $bank->account->is_postable
                    || $bank->currency_code !== 'THB' || $bank->account->control_account_type !== $bank->type) {
                    throw ValidationException::withMessages(['tenders' => 'บัญชีเงินทุกช่องทางต้องเปิดใช้งาน เป็น THB และผูกกับบัญชีคุมที่ตรงประเภท']);
                }
            }
            if (! $party || ! $role || $settlement->party_type !== $contract['party_type']) {
                throw ValidationException::withMessages(['party_id' => 'คู่ค้าและบทบาทต้องเปิดใช้งาน']);
            }

            $intents = $settlement->allocationIntents()->with('openItem.account')->orderBy('line_number')->lockForUpdate()->get();
            if ($intents->isEmpty()) {
                throw ValidationException::withMessages(['allocations' => 'ต้องมีรายการจัดสรรก่อนลงบัญชี']);
            }
            $intentTotal = $intents->reduce(fn (string $total, $intent) => JournalBalance::add($total, $intent->amount), '0.00');
            if ($intentTotal > $gross) {
                throw ValidationException::withMessages(['allocations' => 'ยอดจัดสรรต้องไม่เกินยอดเอกสาร']);
            }
            $advanceAmount = JournalBalance::subtract($gross, $intentTotal);
            if ($advanceAmount !== '0.00' && $settlement->document_type !== 'RECEIPT') {
                throw ValidationException::withMessages(['allocations' => 'ยอดจ่ายต้องจัดสรรให้ครบตามยอดเอกสาร']);
            }

            $groups = $intents->groupBy(fn ($intent) => (int) $intent->openItem->account_id)->map(fn ($rows) => [
                'account_id' => (int) $rows->first()->openItem->account_id,
                'amount' => $rows->reduce(fn (string $total, $intent) => JournalBalance::add($total, $intent->amount), '0.00'),
                'intents' => $rows,
            ])->sortKeys()->values();
            foreach ($intents as $intent) {
                $item = $intent->openItem;
                if (! $item || $item->warehouse_id !== $warehouse->id || $item->ledger_type !== $contract['ledger']
                    || $item->party_type !== $contract['party_type'] || (int) $item->party_id !== (int) $party->id
                    || $item->balance_side !== $contract['target_side'] || $item->posting_date->format('Y-m-d') > $settlement->settlement_date->format('Y-m-d')
                    || ! $item->account || ! $item->account->is_active || ! $item->account->is_postable
                    || $item->account->control_account_type !== $contract['ledger']) {
                    throw ValidationException::withMessages(['allocations' => 'รายการ Open Item ไม่ตรงกับคลัง คู่ค้า หรือบัญชีคุม']);
                }
            }
            $settlementDate = $settlement->settlement_date->format('Y-m-d');
            $whtCalculations = $this->whtCalculations($intents, $settlementDate);
            $expectedWithholding = collect($whtCalculations)->reduce(fn (string $total, array $row) => JournalBalance::add($total, $row['calc']['tax']), '0.00');
            if (JournalBalance::decimal($settlement->withholding_amount) !== $expectedWithholding
                || JournalBalance::decimal($settlement->net_amount) !== JournalBalance::subtract($gross, $expectedWithholding)
                || $net !== JournalBalance::subtract($gross, $expectedWithholding)) {
                throw ValidationException::withMessages(['withholding_amount' => 'ยอด WHT และยอดสุทธิต้องตรงกับ WHT ของ Open Item ที่จัดสรร']);
            }
            $tenderTotal = $tenders->reduce(fn (string $total, $tender) => JournalBalance::add($total, $tender->amount), '0.00');
            if ($tenderTotal !== $net) {
                throw ValidationException::withMessages(['tenders' => 'ยอดช่องทางรับ/จ่ายต้องเท่ากับยอดสุทธิหลังหัก ณ ที่จ่าย']);
            }

            $lines = $tenders->map(function ($tender) use ($settlement, $contract): array {
                $bank = $tender->bankAccount;

                return ['account_id' => (int) $bank->account_id, 'subledger_type' => strtoupper($bank->type), 'subledger_id' => (string) $bank->id, 'description' => $settlement->description ?: $settlement->document_number, 'debit' => $contract['bank_debit'] ? $tender->amount : '0.00', 'credit' => $contract['bank_debit'] ? '0.00' : $tender->amount, 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            })->all();
            foreach ($groups as $group) {
                $lines[] = [
                    'account_id' => $group['account_id'],
                    'subledger_type' => $contract['party_type'],
                    'subledger_id' => (string) $party->id,
                    'description' => $settlement->document_number,
                    'debit' => $contract['bank_debit'] ? '0.00' : $group['amount'],
                    'credit' => $contract['bank_debit'] ? $group['amount'] : '0.00',
                    'tax_base' => '0.00', 'tax_amount' => '0.00',
                ];
            }
            if ($advanceAmount !== '0.00') {
                $advanceAccount = $this->mappings->resolve('CUSTOMER_ADVANCE');
                $lines[] = [
                    'account_id' => (int) $advanceAccount->id,
                    'subledger_type' => null,
                    'subledger_id' => null,
                    'description' => "เงินรับล่วงหน้า {$settlement->document_number}",
                    'debit' => '0.00',
                    'credit' => $advanceAmount,
                    'tax_base' => '0.00',
                    'tax_amount' => '0.00',
                ];
            }
            $lines = [...$lines, ...$this->vatJournalLines($intents, $settlementDate), ...$this->whtJournalLines($whtCalculations, $contract['bank_debit'])];

            $entry = $this->journalPosting->post([
                'source_type' => 'FINANCE', 'source_id' => (string) $settlement->id,
                'source_reference' => $settlement->document_number, 'event_code' => $contract['event'],
                'entry_date' => $settlement->settlement_date->format('Y-m-d'),
                'document_date' => $settlement->document_date->format('Y-m-d'),
                'description' => $settlement->description ?: $settlement->document_number,
                'lines' => $lines,
            ], $warehouse, $actor);

            $paymentItems = [];
            foreach ($groups as $index => $group) {
                $line = $entry->lines()->where('account_id', $group['account_id'])
                    ->where('subledger_type', $contract['party_type'])->where('subledger_id', (string) $party->id)
                    ->where($contract['bank_debit'] ? 'credit' : 'debit', $group['amount'])->first();
                if (! $line) {
                    throw ValidationException::withMessages(['journal_entry_id' => 'ไม่พบบรรทัด AR/AP ที่ตรงกับยอดที่ Post']);
                }
                $paymentItems[$group['account_id']] = $this->openItems->recordFromJournalLine($line, [
                    'document_type' => $settlement->document_type === 'RECEIPT' ? 'RECEIPT' : 'PAYMENT',
                    'document_number' => $settlement->document_number,
                ]);
            }
            foreach ($intents as $intent) {
                $payment = $paymentItems[(int) $intent->openItem->account_id];
                $allocation = $this->openItems->allocate([
                    'debit_open_item_id' => $contract['bank_debit'] ? $intent->open_item_id : $payment->id,
                    'credit_open_item_id' => $contract['bank_debit'] ? $payment->id : $intent->open_item_id,
                    'allocation_date' => $settlement->settlement_date->format('Y-m-d'),
                    'amount' => $intent->amount, 'source_type' => 'FINANCE',
                    'source_id' => "settlement:{$settlement->id}:intent:{$intent->id}",
                ], $actor);
                $this->vatRealizations->record($allocation, $settlement, $actor);
                $this->whtRealizations->record($allocation, $settlement, $actor);
                $intent->update(['allocation_id' => $allocation->id]);
            }
            if ($advanceAmount !== '0.00') {
                AdvanceDeposit::query()->create([
                    'warehouse_id' => $warehouse->id,
                    'party_id' => $party->id,
                    'party_type' => 'CUSTOMER',
                    'direction' => 'RECEIPT',
                    'instrument_type' => 'ADVANCE',
                    'source_settlement_id' => $settlement->id,
                    'document_number' => 'ADV-ADVANCE-'.$settlement->document_number,
                    'document_date' => $settlement->document_date,
                    'posting_date' => $settlement->settlement_date,
                    'original_amount' => $advanceAmount,
                    'applied_amount' => '0.00',
                    'status' => 'POSTED',
                    'journal_entry_id' => $entry->id,
                    'idempotency_key' => hash('sha256', "finance-mixed-receipt-advance|{$settlement->id}"),
                    'created_by' => $actor->id,
                    'posted_by' => $actor->id,
                    'posted_at' => now(),
                    'description' => "เงินรับเกินจาก {$settlement->document_number}",
                ]);
            }

            $settlement->update(['status' => 'POSTED', 'journal_entry_id' => $entry->id, 'posted_by' => $actor->id, 'posted_at' => now()]);
            if ($settlement->document_type === 'RECEIPT') {
                $this->commissions->calculateCollectedReceipt($settlement);
            }

            return $settlement->fresh();
        }, 3);
    }

    private function vatJournalLines($intents, string $settlementDate): array
    {
        $groups = [];
        $virtual = [];
        foreach ($intents as $intent) {
            $invoice = $intent->openItem;
            if (! $invoice || ! in_array($invoice->tax_kind, ['VAT_IN', 'VAT_OUT'], true) || JournalBalance::decimal($invoice->tax_amount) === '0.00') {
                continue;
            }
            $allocatedBefore = Allocation::query()->where(function ($query) use ($invoice) {
                $query->where('debit_open_item_id', $invoice->id)->orWhere('credit_open_item_id', $invoice->id);
            })->where('allocation_date', '<=', $settlementDate)->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $settlementDate))->sum('amount');
            $taxBefore = TaxRealization::query()->where('open_item_id', $invoice->id)
                ->where('settlement_date', '<=', $settlementDate)
                ->sum('tax_amount');
            $offset = $virtual[$invoice->id] ?? ['allocated' => '0.00', 'tax' => '0.00'];
            $calculated = VatRealizationCalculator::calculate($invoice->original_amount, $invoice->tax_amount, $intent->amount,
                JournalBalance::add((string) $allocatedBefore, $offset['allocated']), JournalBalance::add((string) $taxBefore, $offset['tax']));
            $virtual[$invoice->id]['allocated'] = JournalBalance::add($offset['allocated'], $intent->amount);
            $virtual[$invoice->id]['tax'] = JournalBalance::add($offset['tax'], $calculated['tax']);
            if ($calculated['tax'] === '0.00') {
                continue;
            }
            $deferred = $this->mappings->resolve($invoice->tax_kind === 'VAT_IN' ? 'DEFERRED_INPUT_VAT' : 'DEFERRED_OUTPUT_VAT');
            $actual = $this->mappings->resolve($invoice->tax_kind === 'VAT_IN' ? 'INPUT_VAT' : 'OUTPUT_VAT');
            $key = $invoice->tax_kind.':'.$invoice->tax_code_id;
            $built = VatRealizationJournalLines::build($invoice->tax_kind, $deferred->id, $actual->id, $calculated['base'], $calculated['tax'], (int) $invoice->tax_code_id,
                $invoice->tax_point_date?->format('Y-m-d') ?? $invoice->document_date->format('Y-m-d'), $settlementDate);
            foreach ($built as $line) {
                $groupKey = $key.':'.$line['account_id'].':'.$line['debit'].':'.$line['credit'];
                if (! isset($groups[$groupKey])) {
                    $groups[$groupKey] = $line;

                    continue;
                }
                $groups[$groupKey]['tax_base'] = JournalBalance::add($groups[$groupKey]['tax_base'], $line['tax_base']);
                $groups[$groupKey]['tax_amount'] = JournalBalance::add($groups[$groupKey]['tax_amount'], $line['tax_amount']);
                $groups[$groupKey]['debit'] = JournalBalance::add($groups[$groupKey]['debit'], $line['debit']);
                $groups[$groupKey]['credit'] = JournalBalance::add($groups[$groupKey]['credit'], $line['credit']);
            }
        }

        return array_values($groups);
    }

    private function whtCalculations($intents, string $date): array
    {
        $virtual = [];
        $result = [];
        foreach ($intents as $intent) {
            $invoice = $intent->openItem;
            if (! $invoice || ! $invoice->withholding_tax_code_id || JournalBalance::decimal($invoice->withholding_amount) === '0.00') {
                continue;
            }
            $allocated = Allocation::query()->where(fn ($q) => $q->where('debit_open_item_id', $invoice->id)->orWhere('credit_open_item_id', $invoice->id))->where('allocation_date', '<=', $date)->where(fn ($q) => $q->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))->sum('amount');
            $realized = DB::table('finance_withholding_realizations')->where('open_item_id', $invoice->id)->where('settlement_date', '<=', $date)->sum('tax_amount');
            $offset = $virtual[$invoice->id] ?? ['allocated' => '0.00', 'realized' => '0.00'];
            $calc = WhtRealizationCalculator::calculate($invoice->original_amount, $invoice->withholding_base, $invoice->withholding_amount, $intent->amount, JournalBalance::add((string) $allocated, $offset['allocated']), JournalBalance::add((string) $realized, $offset['realized']));
            $virtual[$invoice->id] = ['allocated' => JournalBalance::add($offset['allocated'], $intent->amount), 'realized' => JournalBalance::add($offset['realized'], $calc['tax'])];
            $direction = $invoice->ledger_type === 'AR' ? 'RECEIVABLE' : 'PAYABLE';
            $result[$intent->id] = ['calc' => $calc, 'account' => $this->mappings->resolve($direction === 'RECEIVABLE' ? 'WHT_RECEIVABLE' : 'WHT_PAYABLE'), 'direction' => $direction];
        }

        return $result;
    }

    private function whtJournalLines(array $calculations, bool $receipt): array
    {
        $groups = [];
        foreach ($calculations as $row) {
            $key = $row['account']->id.':'.$row['direction'];
            $amount = $row['calc']['tax'];
            if (! isset($groups[$key])) {
                $groups[$key] = ['account_id' => $row['account']->id, 'subledger_type' => 'TAX', 'subledger_id' => (string) $row['account']->id, 'description' => 'WHT realization', 'debit' => '0.00', 'credit' => '0.00', 'tax_base' => '0.00', 'tax_amount' => '0.00'];
            }
            if ($row['direction'] === 'RECEIVABLE' && $receipt) {
                $groups[$key]['debit'] = JournalBalance::add($groups[$key]['debit'], $amount);
            } else {
                $groups[$key]['credit'] = JournalBalance::add($groups[$key]['credit'], $amount);
            }
        }

        return array_values(array_filter($groups, fn ($line) => $line['debit'] !== '0.00' || $line['credit'] !== '0.00'));
    }

    private function verifyRetry(Settlement $settlement, Warehouse $warehouse, array $contract): Settlement
    {
        if (! $settlement->journal_entry_id || ! $settlement->posted_at || $settlement->allocationIntents()->whereNull('allocation_id')->exists()) {
            throw ValidationException::withMessages(['status' => 'เอกสารสถานะ POSTED แต่ข้อมูลการลงบัญชีไม่ครบ']);
        }
        $entry = JournalEntry::query()->whereKey($settlement->journal_entry_id)->where('status', 'POSTED')->first();
        if (! $entry || (int) $entry->warehouse_id !== (int) $warehouse->id || $entry->source_type !== 'FINANCE'
            || $entry->source_event !== $contract['event'] || (string) $entry->source_id !== (string) $settlement->id
            || $entry->source_reference !== $settlement->document_number
            || $entry->entry_date->format('Y-m-d') !== $settlement->settlement_date->format('Y-m-d')
            || $entry->document_date->format('Y-m-d') !== $settlement->document_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['journal_entry_id' => 'ข้อมูล Journal ของเอกสารไม่ตรงกัน']);
        }
        $intents = $settlement->allocationIntents()->with('openItem')->get();
        foreach ($intents as $intent) {
            $allocation = Allocation::query()->whereKey($intent->allocation_id)->first();
            if (! $allocation || $allocation->source_type !== 'FINANCE'
                || $allocation->source_id !== "settlement:{$settlement->id}:intent:{$intent->id}"
                || JournalBalance::decimal($allocation->amount) !== JournalBalance::decimal($intent->amount)
                || $allocation->allocation_date->format('Y-m-d') !== $settlement->settlement_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['status' => 'Allocation ของเอกสารที่ Post แล้วไม่ตรงกับรายการจัดสรร']);
            }
            $linkedOpenItem = $contract['bank_debit'] ? $allocation->debit_open_item_id : $allocation->credit_open_item_id;
            if ((int) $linkedOpenItem !== (int) $intent->open_item_id) {
                throw ValidationException::withMessages(['status' => 'Allocation ไม่ได้เชื่อมกับ Open Item ต้นทางของเอกสาร']);
            }
        }
        $taxIntentIds = $settlement->allocationIntents()->whereHas('openItem', fn ($query) => $query->whereIn('tax_kind', ['VAT_IN', 'VAT_OUT'])->where('tax_amount', '>', 0))->pluck('allocation_id');
        if (TaxRealization::query()->whereIn('allocation_id', $taxIntentIds)->count() !== $taxIntentIds->count()) {
            throw ValidationException::withMessages(['status' => 'Tax Realization ของเอกสารที่ Post แล้วไม่ครบ']);
        }
        $whtIntentIds = $settlement->allocationIntents()->whereHas('openItem', fn ($query) => $query->where('withholding_amount', '>', 0))->pluck('allocation_id');
        if (DB::table('finance_withholding_realizations')->whereIn('allocation_id', $whtIntentIds)->count() !== $whtIntentIds->count()) {
            throw ValidationException::withMessages(['status' => 'WHT Realization ของเอกสารที่ Post แล้วไม่ครบ']);
        }
        $intentTotal = $intents->reduce(fn (string $total, $intent) => JournalBalance::add($total, $intent->amount), '0.00');
        $advanceAmount = JournalBalance::subtract($settlement->gross_amount, $intentTotal);
        if ($advanceAmount !== '0.00') {
            $advance = AdvanceDeposit::query()->where('source_settlement_id', $settlement->id)->first();
            if (! $advance || $advance->journal_entry_id !== $settlement->journal_entry_id
                || JournalBalance::decimal($advance->original_amount) !== $advanceAmount
                || ! in_array($advance->status, ['POSTED', 'PARTIAL', 'APPLIED'], true)) {
                throw ValidationException::withMessages(['status' => 'เงินรับล่วงหน้าของเอกสารที่ Post แล้วไม่ครบ']);
            }
        }

        return $settlement;
    }
}
