<?php

namespace App\Modules\Finance\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Accounting\Support\PostingIdentity;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Support\OpenItemBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class OpenItemService
{
    public function remainingAt(OpenItem $openItem, string $date): string
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $foreignKey = $openItem->balance_side === 'DEBIT' ? 'debit_open_item_id' : 'credit_open_item_id';
        $allocated = Allocation::query()
            ->where($foreignKey, $openItem->id)
            ->where('allocation_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))
            ->get(['amount'])
            ->reduce(fn (string $total, Allocation $allocation) => JournalBalance::add($total, $allocation->amount), '0.00');
        $advanceApplied = DB::table('finance_advance_deposit_applications')
            ->where('open_item_id', $openItem->id)
            ->where('application_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))
            ->sum('amount');

        try {
            return OpenItemBalance::remaining($openItem->original_amount, JournalBalance::add($allocated, $advanceApplied));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['open_item_id' => 'ยอดจัดสรรของ Open Item ไม่ถูกต้อง']);
        }
    }

    public function assertAmountAvailable(OpenItem $openItem, string $date, mixed $amount, string $field = 'open_item_id'): void
    {
        $foreignKey = $openItem->balance_side === 'DEBIT' ? 'debit_open_item_id' : 'credit_open_item_id';
        $this->assertAvailable($openItem, $foreignKey, $date, JournalBalance::decimal($amount), $field);
    }

    public function recordFromJournalLine(JournalEntryLine $journalEntryLine, array $attributes): OpenItem
    {
        $attributes['document_type'] = strtoupper(trim((string) ($attributes['document_type'] ?? '')));
        $attributes['document_number'] = trim((string) ($attributes['document_number'] ?? ''));
        $attributes['withholding_rate'] = (string) ($attributes['withholding_rate'] ?? '0') ?: '0';
        $attributes['withholding_base'] = (string) ($attributes['withholding_base'] ?? '0') ?: '0';
        $attributes['withholding_amount'] = (string) ($attributes['withholding_amount'] ?? '0') ?: '0';
        $metadata = Validator::make($attributes, [
            'document_type' => ['required', Rule::in(['INVOICE', 'CREDIT_NOTE', 'RECEIPT', 'PAYMENT'])],
            'document_number' => ['required', 'string', 'max:100'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'withholding_tax_code_id' => ['nullable', 'integer', 'min:1'],
            'withholding_rate' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'withholding_base' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'withholding_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        ])->validate();
        $metadata['withholding_rate'] = BigDecimal::of($metadata['withholding_rate'])->toScale(4, RoundingMode::HALF_UP)->__toString();
        $metadata['withholding_base'] = BigDecimal::of($metadata['withholding_base'])->toScale(2, RoundingMode::HALF_UP)->__toString();
        $metadata['withholding_amount'] = BigDecimal::of($metadata['withholding_amount'])->toScale(2, RoundingMode::HALF_UP)->__toString();

        if ($metadata['document_type'] === 'INVOICE' && empty($metadata['due_date'])) {
            throw ValidationException::withMessages(['due_date' => 'ใบแจ้งหนี้ต้องมีวันครบกำหนด']);
        }

        return DB::transaction(function () use ($journalEntryLine, $metadata) {
            $line = JournalEntryLine::query()->with('entry')->lockForUpdate()->findOrFail($journalEntryLine->id);
            $account = Account::query()->withTrashed()->findOrFail($line->account_id);

            if ($line->entry->status !== 'POSTED') {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'สร้าง Open Item ได้จาก Journal ที่ Post แล้วเท่านั้น']);
            }
            $sourceReference = trim((string) $line->entry->source_reference);
            if ($sourceReference === '') {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'Journal ของ Open Item ต้องมีเอกสารอ้างอิง']);
            }
            if ($metadata['document_number'] !== $sourceReference) {
                throw ValidationException::withMessages(['document_number' => 'เลขที่เอกสารต้องตรงกับเอกสารอ้างอิงของ Journal']);
            }

            $contract = match ($line->entry->source_event) {
                'sales_invoice' => ['document_type' => 'INVOICE', 'ledger_type' => 'AR', 'party_type' => 'CUSTOMER', 'balance_side' => 'DEBIT'],
                'sales_credit_note' => ['document_type' => 'CREDIT_NOTE', 'ledger_type' => 'AR', 'party_type' => 'CUSTOMER', 'balance_side' => 'CREDIT'],
                'customer_payment' => ['document_type' => 'RECEIPT', 'ledger_type' => 'AR', 'party_type' => 'CUSTOMER', 'balance_side' => 'CREDIT'],
                'supplier_invoice.inventory', 'supplier_invoice.expense' => ['document_type' => 'INVOICE', 'ledger_type' => 'AP', 'party_type' => 'SUPPLIER', 'balance_side' => 'CREDIT'],
                'purchase_credit_note' => ['document_type' => 'CREDIT_NOTE', 'ledger_type' => 'AP', 'party_type' => 'SUPPLIER', 'balance_side' => 'DEBIT'],
                'supplier_payment' => ['document_type' => 'PAYMENT', 'ledger_type' => 'AP', 'party_type' => 'SUPPLIER', 'balance_side' => 'DEBIT'],
                default => throw ValidationException::withMessages(['journal_entry_line_id' => 'Accounting event นี้ยังไม่มี Open Item contract']),
            };
            if ($metadata['document_type'] !== $contract['document_type']) {
                throw ValidationException::withMessages(['document_type' => 'ประเภทเอกสารไม่ตรงกับ Accounting event']);
            }
            if ($account->trashed() || ! $account->is_active || ! $account->is_postable || ! in_array($account->control_account_type, ['AR', 'AP'], true)) {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'บรรทัด Open Item ต้องใช้บัญชีคุม AR หรือ AP']);
            }

            $ledgerType = $account->control_account_type;
            $partyType = $contract['party_type'];
            $partyReference = trim((string) $line->subledger_id);
            if ($ledgerType !== $contract['ledger_type'] || $line->subledger_type !== $partyType || $partyReference === '') {
                throw ValidationException::withMessages(['journal_entry_line_id' => "บัญชีคุม {$ledgerType} ต้องมี {$partyType} Subledger ครบคู่"]);
            }
            $existing = OpenItem::query()->where('journal_entry_line_id', $line->id)->lockForUpdate()->first();
            $party = $this->resolveParty($partyReference, $partyType, $existing !== null);

            $totals = JournalBalance::totals([['debit' => $line->debit, 'credit' => $line->credit]]);
            if (! (($totals['debit'] > 0 && $totals['credit'] === 0) || ($totals['credit'] > 0 && $totals['debit'] === 0))) {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'บรรทัด Open Item ต้องมียอดบวกเพียงฝั่ง Debit หรือ Credit']);
            }

            $balanceSide = $totals['debit'] > 0 ? 'DEBIT' : 'CREDIT';
            if ($balanceSide !== $contract['balance_side']) {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'ฝั่งยอด Open Item ไม่ตรงกับ Accounting event']);
            }
            $documentDate = ($line->entry->document_date ?? $line->entry->entry_date)->format('Y-m-d');
            if ($metadata['document_type'] === 'INVOICE' && $metadata['due_date'] < $documentDate) {
                throw ValidationException::withMessages(['due_date' => 'วันครบกำหนดต้องไม่ก่อนวันที่เอกสาร']);
            }

            $payload = [
                'warehouse_id' => $line->entry->warehouse_id,
                'ledger_type' => $ledgerType,
                'party_type' => $partyType,
                'party_id' => $party->id,
                'account_id' => $line->account_id,
                'journal_entry_line_id' => $line->id,
                'document_type' => $metadata['document_type'],
                'document_number' => $metadata['document_number'],
                'document_date' => $documentDate,
                'posting_date' => $line->entry->entry_date->format('Y-m-d'),
                'due_date' => $metadata['due_date'] ?? null,
                'balance_side' => $balanceSide,
                'original_amount' => JournalBalance::decimal($balanceSide === 'DEBIT' ? $line->debit : $line->credit),
                'tax_code_id' => $line->tax_code_id,
                'tax_kind' => $line->taxCode?->kind,
                'tax_rate' => $line->taxCode?->rate,
                'tax_base' => $line->tax_base,
                'tax_amount' => $line->tax_amount,
                'tax_point_date' => $line->tax_point_date?->format('Y-m-d') ?? $documentDate,
                'withholding_tax_code_id' => $metadata['withholding_tax_code_id'] ?? null,
                'withholding_rate' => $metadata['withholding_rate'], 'withholding_base' => $metadata['withholding_base'],
                'withholding_amount' => $metadata['withholding_amount'],
            ];
            $openItem = $existing ?? OpenItem::query()->create($payload);

            if (! hash_equals(PostingIdentity::fingerprint($payload), PostingIdentity::fingerprint($this->openItemPayload($openItem)))) {
                throw ValidationException::withMessages(['journal_entry_line_id' => 'บรรทัด Journal นี้เคยสร้าง Open Item ด้วยข้อมูลคนละชุด']);
            }

            return $openItem;
        }, 3);
    }

    public function allocate(array $attributes, ?User $actor = null): Allocation
    {
        $attributes['source_type'] = strtoupper(trim((string) ($attributes['source_type'] ?? '')));
        $attributes['source_id'] = trim((string) ($attributes['source_id'] ?? ''));
        $values = Validator::make($attributes, [
            'debit_open_item_id' => ['required', 'integer', 'min:1', 'different:credit_open_item_id'],
            'credit_open_item_id' => ['required', 'integer', 'min:1'],
            'allocation_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'source_type' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/'],
            'source_id' => ['required', 'string', 'max:100'],
        ])->validate();
        $payload = [
            'debit_open_item_id' => (int) $values['debit_open_item_id'],
            'credit_open_item_id' => (int) $values['credit_open_item_id'],
            'allocation_date' => $values['allocation_date'],
            'amount' => JournalBalance::decimal($values['amount']),
            'source_type' => $values['source_type'],
            'source_id' => $values['source_id'],
        ];
        $key = PostingIdentity::key($payload['source_type'], 'open_item.allocation', $payload['source_id']);
        $hash = PostingIdentity::fingerprint($payload);

        return DB::transaction(function () use ($payload, $key, $hash, $actor) {
            $items = OpenItem::query()
                ->whereKey([$payload['debit_open_item_id'], $payload['credit_open_item_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $debit = $items->get($payload['debit_open_item_id']);
            $credit = $items->get($payload['credit_open_item_id']);
            if (! $debit || ! $credit) {
                throw ValidationException::withMessages(['debit_open_item_id' => 'ไม่พบ Open Item ที่ต้องการจัดสรร']);
            }
            if ($debit->balance_side !== 'DEBIT' || $credit->balance_side !== 'CREDIT') {
                throw ValidationException::withMessages(['debit_open_item_id' => 'การจัดสรรต้องจับคู่ Debit Open Item กับ Credit Open Item']);
            }

            foreach (['warehouse_id', 'ledger_type', 'party_type', 'party_id', 'account_id'] as $field) {
                if ((string) $debit->{$field} !== (string) $credit->{$field}) {
                    throw ValidationException::withMessages(['credit_open_item_id' => 'Open Item ที่จัดสรรต้องอยู่คลัง บัญชี และคู่ค้าเดียวกัน']);
                }
            }
            if ($payload['allocation_date'] < $debit->posting_date->format('Y-m-d') || $payload['allocation_date'] < $credit->posting_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['allocation_date' => 'วันจัดสรรต้องไม่ก่อนวัน Post ของ Open Item']);
            }

            $existing = Allocation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->allocation_hash, $hash)
                    || ! hash_equals(PostingIdentity::fingerprint($this->allocationPayload($existing)), $hash)) {
                    throw ValidationException::withMessages(['source_id' => 'การจัดสรรนี้เคยบันทึกด้วยข้อมูลคนละชุด']);
                }

                return $existing;
            }

            $this->assertAvailable($debit, 'debit_open_item_id', $payload['allocation_date'], $payload['amount']);
            $this->assertAvailable($credit, 'credit_open_item_id', $payload['allocation_date'], $payload['amount']);

            return Allocation::query()->create([
                ...$payload,
                'idempotency_key' => $key,
                'allocation_hash' => $hash,
                'created_by' => $actor?->id,
            ]);
        }, 3);
    }

    private function assertAvailable(OpenItem $openItem, string $foreignKey, string $date, string $amount, ?string $errorField = null): void
    {
        $allocations = Allocation::query()
            ->where($foreignKey, $openItem->id)
            ->get(['allocation_date', 'amount', 'reversal_date']);
        $advanceApplications = DB::table('finance_advance_deposit_applications')
            ->where('open_item_id', $openItem->id)
            ->get(['application_date', 'amount', 'reversal_date']);

        try {
            OpenItemBalance::remaining($openItem->original_amount, $amount);
            OpenItemBalance::assertAllocationFitsTimeline($openItem->original_amount, $date, $amount, [
                ...$allocations->map(fn (Allocation $allocation) => [
                    'allocation_date' => $allocation->allocation_date->format('Y-m-d'),
                    'amount' => $allocation->amount,
                    'reversal_date' => $allocation->reversal_date?->format('Y-m-d'),
                ])->all(),
                ...$advanceApplications->map(fn ($application) => [
                    'allocation_date' => $application->application_date,
                    'amount' => $application->amount,
                    'reversal_date' => $application->reversal_date,
                ])->all(),
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$errorField ?? $foreignKey => 'ยอดจัดสรรเกินยอดคงเหลือของ Open Item']);
        }
    }

    private function resolveParty(string $reference, string $role, bool $allowInactive): Party
    {
        $party = Party::query()->withTrashed()
            ->when(ctype_digit($reference), fn ($query) => $query->whereKey((int) $reference), fn ($query) => $query->where('code', $reference))
            ->sharedLock()
            ->first();
        if (! $party) {
            throw ValidationException::withMessages(['journal_entry_line_id' => 'ไม่พบคู่ค้าของ Subledger']);
        }

        $partyRole = PartyRole::query()
            ->where('party_id', $party->id)
            ->where('role', $role)
            ->sharedLock()
            ->first();
        if (! $partyRole || (! $allowInactive && ($party->trashed() || ! $party->is_active || ! $partyRole->is_active))) {
            throw ValidationException::withMessages(['journal_entry_line_id' => 'คู่ค้าและบทบาท Subledger ต้องเปิดใช้งาน']);
        }

        return $party;
    }

    private function openItemPayload(OpenItem $openItem): array
    {
        return [
            'warehouse_id' => (int) $openItem->warehouse_id,
            'ledger_type' => $openItem->ledger_type,
            'party_type' => $openItem->party_type,
            'party_id' => (int) $openItem->party_id,
            'account_id' => (int) $openItem->account_id,
            'journal_entry_line_id' => (int) $openItem->journal_entry_line_id,
            'document_type' => $openItem->document_type,
            'document_number' => $openItem->document_number,
            'document_date' => $openItem->document_date->format('Y-m-d'),
            'posting_date' => $openItem->posting_date->format('Y-m-d'),
            'due_date' => $openItem->due_date?->format('Y-m-d'),
            'balance_side' => $openItem->balance_side,
            'original_amount' => $openItem->original_amount,
            'tax_code_id' => $openItem->tax_code_id,
            'tax_kind' => $openItem->tax_kind,
            'tax_rate' => $openItem->tax_rate,
            'tax_base' => $openItem->tax_base,
            'tax_amount' => $openItem->tax_amount,
            'tax_point_date' => $openItem->tax_point_date?->format('Y-m-d'),
            'withholding_tax_code_id' => $openItem->withholding_tax_code_id,
            'withholding_rate' => $openItem->withholding_rate, 'withholding_base' => $openItem->withholding_base,
            'withholding_amount' => $openItem->withholding_amount,
        ];
    }

    private function allocationPayload(Allocation $allocation): array
    {
        return [
            'debit_open_item_id' => (int) $allocation->debit_open_item_id,
            'credit_open_item_id' => (int) $allocation->credit_open_item_id,
            'allocation_date' => $allocation->allocation_date->format('Y-m-d'),
            'amount' => $allocation->amount,
            'source_type' => $allocation->source_type,
            'source_id' => $allocation->source_id,
        ];
    }
}
