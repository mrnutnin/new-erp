<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Accounting\Support\PostingIdentity;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\AdvanceDepositApplication;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Support\AdvanceDepositContract;
use App\Modules\Finance\Support\AdvanceDepositPostingContract;
use App\Modules\Pos\Models\PhysicalSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pure advance/deposit application ledger boundary.
 *
 * It does not create Journal rows or finance_allocations. The advance row is
 * locked before the Open Item on both apply and reverse paths so concurrent
 * applications cannot overdraw the subledger or deadlock in mixed order.
 */
final class AdvanceDepositApplicationService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
    ) {}

    public function apply(AdvanceDeposit $advance, OpenItem $openItem, array $attributes, ?User $actor = null): AdvanceDepositApplication
    {
        $values = Validator::make($attributes, [
            'application_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'source_type' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'source_id' => ['required', 'string', 'max:100'],
        ])->validate();
        $payload = [
            'advance_deposit_id' => (int) $advance->id,
            'open_item_id' => (int) $openItem->id,
            'application_date' => $values['application_date'],
            'amount' => JournalBalance::decimal($values['amount']),
            'source_type' => strtoupper($values['source_type']),
            'source_id' => trim($values['source_id']),
        ];
        $key = PostingIdentity::key('FINANCE', 'advance.application', implode(':', [
            $payload['advance_deposit_id'], $payload['source_type'], $payload['source_id'],
        ]));
        $hash = PostingIdentity::fingerprint($payload);

        return DB::transaction(function () use ($payload, $key, $hash, $actor): AdvanceDepositApplication {
            $lockedAdvance = AdvanceDeposit::query()->whereKey($payload['advance_deposit_id'])->lockForUpdate()->firstOrFail();
            $lockedItem = OpenItem::query()->whereKey($payload['open_item_id'])->lockForUpdate()->firstOrFail();

            $existing = AdvanceDepositApplication::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->application_hash, $hash)) {
                    throw ValidationException::withMessages(['source_id' => 'การตัดเงินล่วงหน้านี้เคยบันทึกด้วยข้อมูลคนละชุด']);
                }

                return $existing;
            }
            if (! in_array($lockedAdvance->status, ['POSTED', 'PARTIAL'], true)) {
                throw ValidationException::withMessages(['advance_deposit_id' => 'ตัดได้เฉพาะ Advance/Deposit ที่ลงบัญชีแล้ว']);
            }
            if (! $lockedAdvance->journal_entry_id) {
                throw ValidationException::withMessages(['advance_deposit_id' => 'Advance/Deposit ยังไม่มี Journal ที่ลงบัญชีแล้ว จึงตัดรายการไม่ได้']);
            }

            try {
                AdvanceDepositContract::assertApplicationScope(
                    $lockedAdvance->warehouse_id, $lockedAdvance->party_type, $lockedAdvance->party_id,
                    $lockedItem->ledger_type, $lockedItem->party_type, $lockedItem->party_id,
                    $lockedItem->warehouse_id, $lockedItem->balance_side,
                );
                AdvanceDepositContract::assertApplicationAmount($lockedAdvance->original_amount, $lockedAdvance->applied_amount, $payload['amount']);
                $directForeignKey = $lockedItem->balance_side === 'DEBIT' ? 'debit_open_item_id' : 'credit_open_item_id';
                $directAllocated = DB::table('finance_allocations')->where($directForeignKey, $lockedItem->id)->whereNull('reversal_date')->sum('amount');
                $advanceApplied = DB::table('finance_advance_deposit_applications')->where('open_item_id', $lockedItem->id)->whereNull('reversed_at')->sum('amount');
                $available = JournalBalance::subtract($lockedItem->original_amount, JournalBalance::add((string) $directAllocated, (string) $advanceApplied));
                if (JournalBalance::decimal($payload['amount']) > $available) {
                    throw new \InvalidArgumentException('จำนวนเงินที่ตัดเกินยอดคงเหลือของ Open Item');
                }
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
            }

            $application = AdvanceDepositApplication::query()->create([
                ...$payload,
                'idempotency_key' => $key,
                'application_hash' => $hash,
                'created_by' => $actor?->id,
            ]);
            $event = AdvanceDepositPostingContract::event($lockedAdvance->party_type, 'APPLY');
            $advanceResolution = $this->mappings->resolveForEvent(
                $event,
                strtoupper($lockedAdvance->party_type) === 'CUSTOMER' ? 'CUSTOMER_ADVANCE' : 'SUPPLIER_ADVANCE',
            );
            $advanceAccount = $advanceResolution['account'];
            $journal = $this->journals->post([
                'source_type' => 'FINANCE_ADVANCE_APP', 'source_id' => (string) $application->id,
                'source_reference' => $payload['source_id'], 'event_code' => $event,
                'entry_date' => $payload['application_date'], 'document_date' => $payload['application_date'],
                'description' => 'Advance/Deposit application '.$payload['source_id'],
                'posting_metadata' => ['contract_version' => 1, 'event_code' => $event, 'accounts' => array_values(array_filter([
                    $advanceResolution['provenance'],
                    ['event_code' => $event, 'account_role' => strtoupper($lockedAdvance->party_type) === 'CUSTOMER' ? 'ACCOUNTS_RECEIVABLE' : 'ACCOUNTS_PAYABLE', 'account_id' => (int) $lockedItem->account_id, 'source' => 'ORIGINAL', 'source_type' => 'OPEN_ITEM', 'source_id' => (string) $lockedItem->id, 'mapping_id' => null, 'mapping_version' => null],
                ]))],
                'lines' => AdvanceDepositPostingContract::applicationLines(
                    $lockedAdvance->party_type, (int) $advanceAccount->id, (int) $lockedItem->account_id,
                    (int) $lockedItem->party_id, $payload['amount'], $payload['source_id'],
                ),
            ], $lockedAdvance->warehouse, $actor);
            $application->update(['journal_entry_id' => $journal->id]);
            $newApplied = JournalBalance::add($lockedAdvance->applied_amount, $payload['amount']);
            $lockedAdvance->update(['applied_amount' => $newApplied, 'status' => AdvanceDepositContract::status($lockedAdvance->original_amount, $newApplied)]);

            return $application;
        }, 3);
    }

    /** @param list<array{advance_deposit_id:int,amount:string|int|float}> $allocations @return list<array{account_id:int,amount:string,provenance:array<string,mixed>}> */
    public function applyToPhysicalSale(PhysicalSale $sale, array $allocations, string $date, ?User $actor = null): array
    {
        if ($allocations === []) {
            return [];
        }

        return DB::transaction(function () use ($sale, $allocations, $date, $actor): array {
            $sale = PhysicalSale::query()->lockForUpdate()->findOrFail($sale->id);
            if ($sale->status !== 'DRAFT' || $sale->document_type !== 'HS') {
                throw ValidationException::withMessages(['physical_sale' => 'ตัด AI ได้เฉพาะ HS ร่างที่กำลัง Post']);
            }
            $requested = collect($allocations)->map(fn (array $row): array => ['id' => (int) ($row['advance_deposit_id'] ?? 0), 'amount' => JournalBalance::decimal($row['amount'] ?? '0')]);
            if ($requested->contains(fn (array $row): bool => $row['id'] < 1 || $row['amount'] === '0.00') || $requested->pluck('id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['advance_allocations' => 'AI แต่ละรายการต้องระบุครั้งเดียวและยอดต้องมากกว่า 0']);
            }
            $advances = AdvanceDeposit::query()->with('journalEntry.lines')->whereKey($requested->pluck('id')->sort()->values())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($advances->count() !== $requested->count()) {
                throw ValidationException::withMessages(['advance_allocations' => 'ไม่พบ AI ที่เลือก']);
            }
            $result = [];
            foreach ($requested->sortBy('id') as $row) {
                $advance = $advances->get($row['id']);
                if (! in_array($advance->status, ['POSTED', 'PARTIAL'], true) || $advance->party_type !== 'CUSTOMER' || $advance->direction !== 'RECEIPT'
                    || (int) $advance->warehouse_id !== (int) $sale->warehouse_id || (int) $advance->party_id !== (int) $sale->party_id
                    || $advance->tax_treatment !== $sale->tax_treatment || (bool) $advance->prices_include_vat !== (bool) $sale->prices_include_vat) {
                    throw ValidationException::withMessages(['advance_allocations' => 'AI ต้องเป็นเงินรับล่วงหน้าของลูกค้า คลัง และ tax treatment เดียวกับ HS']);
                }
                AdvanceDepositContract::assertApplicationAmount($advance->original_amount, $advance->applied_amount, $row['amount']);
                $payload = ['advance_deposit_id' => $advance->id, 'physical_sale_id' => $sale->id, 'application_date' => $date,
                    'amount' => $row['amount'], 'source_type' => 'POS', 'source_id' => "physical-sale-ai:{$sale->id}"];
                $key = PostingIdentity::key('POS', 'advance.physical-sale', "{$advance->id}:{$sale->id}");
                $hash = PostingIdentity::fingerprint($payload);
                $application = AdvanceDepositApplication::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($application) {
                    if (! hash_equals((string) $application->application_hash, $hash)) {
                        throw ValidationException::withMessages(['advance_allocations' => 'AI นี้เคยตัด HS ด้วยข้อมูลคนละชุด']);
                    }
                } else {
                    $application = AdvanceDepositApplication::query()->create([...$payload, 'idempotency_key' => $key, 'application_hash' => $hash, 'created_by' => $actor?->id]);
                    $advance->update(['applied_amount' => JournalBalance::add($advance->applied_amount, $row['amount']), 'status' => AdvanceDepositContract::status($advance->original_amount, JournalBalance::add($advance->applied_amount, $row['amount']))]);
                }
                $snapshot = collect(data_get($advance->journalEntry?->posting_metadata, 'accounts', []))
                    ->firstWhere('account_role', 'CUSTOMER_ADVANCE');
                $accountId = (int) data_get($snapshot, 'account_id', 0);
                if ($accountId < 1) {
                    $accountId = (int) $this->mappings->resolveForEvent('customer_advance', 'CUSTOMER_ADVANCE')['account']->id;
                }
                $provenance = $snapshot ?: [
                    'event_code' => 'sales_invoice',
                    'account_role' => 'CUSTOMER_ADVANCE_ORIGINAL_'.(int) $advance->id,
                    'account_id' => $accountId,
                    'source' => 'ORIGINAL',
                    'source_type' => 'ADVANCE_DEPOSIT',
                    'source_id' => (string) $advance->id,
                    'mapping_id' => null,
                    'mapping_version' => null,
                ];
                $provenance['event_code'] = 'sales_invoice';
                $provenance['account_role'] = 'CUSTOMER_ADVANCE_ORIGINAL_'.(int) $advance->id;
                $provenance['source'] = 'ORIGINAL';
                $provenance['source_type'] = 'ADVANCE_DEPOSIT';
                $provenance['source_id'] = (string) $advance->id;
                $result[] = ['account_id' => $accountId, 'amount' => $application->amount, 'provenance' => $provenance];
            }

            return $result;
        }, 3);
    }

    public function reverse(AdvanceDepositApplication $application, string $reversalDate, string $reason, ?User $actor = null): AdvanceDepositApplication
    {
        Validator::make(['reversal_date' => $reversalDate], [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
        ])->validate();
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'เหตุผลการย้อนรายการต้องมีอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($application, $reversalDate, $reason, $actor): AdvanceDepositApplication {
            // Read the FK first, then acquire the advance lock before the
            // application lock. This is the same order used by apply().
            $reference = AdvanceDepositApplication::query()->whereKey($application->id)->firstOrFail();
            $advance = AdvanceDeposit::query()->whereKey($reference->advance_deposit_id)->lockForUpdate()->firstOrFail();
            $locked = AdvanceDepositApplication::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $key = hash('sha256', implode('|', ['finance-advance-application-reversal', $locked->id, $reversalDate]));
            if ($locked->reversed_at) {
                if ($locked->reversal_key !== $key || $locked->reversal_reason !== $reason) {
                    throw ValidationException::withMessages(['reason' => 'รายการนี้ถูกย้อนด้วยข้อมูลคนละชุดแล้ว']);
                }

                return $locked;
            }
            if ($advance->status === 'REVERSED' || $advance->status === 'VOID') {
                throw ValidationException::withMessages(['advance_deposit_id' => 'ไม่สามารถย้อนรายการของเอกสารที่ปิดหรือยกเลิกแล้ว']);
            }
            if (! $locked->journal_entry_id) {
                throw ValidationException::withMessages(['application' => 'Application ยังไม่มี Journal ที่ลงบัญชีแล้ว จึงย้อนรายการไม่ได้']);
            }
            $reversal = $this->journals->reverse($locked->journalEntry, [
                'source_type' => 'FINANCE_ADVANCE_APP', 'source_id' => (string) $locked->id,
                'reversal_date' => $reversalDate, 'reason' => $reason,
            ], $actor);

            $remaining = JournalBalance::subtract($advance->applied_amount, $locked->amount);
            $locked->update([
                'reversed_by' => $actor?->id, 'reversed_at' => now(), 'reversal_date' => $reversalDate,
                'reversal_reason' => $reason, 'reversal_key' => $key, 'reversal_journal_entry_id' => $reversal->id,
            ]);
            $advance->update(['applied_amount' => $remaining, 'status' => AdvanceDepositContract::status($advance->original_amount, $remaining)]);

            return $locked->fresh();
        }, 3);
    }

    /**
     * HS applications are posted inside the sale revenue journal, unlike a
     * standalone advance application. Its journal is therefore reversed by
     * the sale cancellation; this only restores the advance balance and trail.
     *
     * @return list<AdvanceDepositApplication>
     */
    public function reversePhysicalSaleApplications(PhysicalSale $sale, JournalEntry $reversalJournal, string $reversalDate, string $reason, ?User $actor = null): array
    {
        return DB::transaction(function () use ($sale, $reversalJournal, $reversalDate, $reason, $actor): array {
            $references = AdvanceDepositApplication::query()->where('physical_sale_id', $sale->id)->orderBy('id')->get(['id', 'advance_deposit_id']);
            if ($references->isEmpty()) {
                return [];
            }

            $advances = AdvanceDeposit::query()->whereKey($references->pluck('advance_deposit_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $applications = AdvanceDepositApplication::query()->whereKey($references->pluck('id'))->orderBy('id')->lockForUpdate()->get();

            foreach ($applications as $application) {
                if ($application->reversed_at) {
                    continue;
                }
                if ((int) $application->journal_entry_id !== (int) $sale->journal_entry_id) {
                    throw ValidationException::withMessages(['advance_deposit' => 'รายการใช้เงินรับล่วงหน้าไม่ผูกกับ Journal ของ HS ต้นทาง']);
                }

                $advance = $advances->get($application->advance_deposit_id);
                $remaining = JournalBalance::subtract($advance->applied_amount, $application->amount);
                $application->update([
                    'reversed_by' => $actor?->id, 'reversed_at' => now(), 'reversal_date' => $reversalDate,
                    'reversal_reason' => $reason, 'reversal_key' => "physical-sale-cancel:{$sale->id}:{$application->id}",
                    'reversal_journal_entry_id' => $reversalJournal->id,
                ]);
                $advance->update(['applied_amount' => $remaining, 'status' => AdvanceDepositContract::status($advance->original_amount, $remaining)]);
            }

            return $applications->all();
        }, 3);
    }
}
