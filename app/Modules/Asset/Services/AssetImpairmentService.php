<?php

namespace App\Modules\Asset\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Asset\Models\AssetImpairment;
use App\Modules\Asset\Models\AssetValueEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetImpairmentService
{
    public function __construct(private readonly ?JournalPostingService $journals = null) {}

    public function assess(float $carryingAmount, float $recoverableAmount): array
    {
        if ($carryingAmount < 0 || $recoverableAmount < 0) {
            throw ValidationException::withMessages(['recoverable_amount' => 'มูลค่าต้องไม่ติดลบ']);
        }

        return ['impairment_amount' => round(max(0, $carryingAmount - $recoverableAmount), 2), 'future_depreciation_basis' => round(min($carryingAmount, $recoverableAmount), 2)];
    }

    public function validatePost(AssetImpairment $impairment): void
    {
        if ($impairment->impairment_amount < 0 || $impairment->recoverable_amount > $impairment->carrying_amount) {
            throw ValidationException::withMessages(['recoverable_amount' => 'Impairment ต้องไม่ทำให้มูลค่าติดลบหรือสูงกว่ามูลค่าตามบัญชี']);
        }
    }

    public function post(AssetImpairment $impairment, User $actor): AssetImpairment
    {
        // Keep the dependency optional for lightweight calculation tests, but
        // resolve the posting service when an actual posting is requested.
        $journals = $this->journals ?? app(JournalPostingService::class);

        return DB::transaction(function () use ($impairment, $actor, $journals): AssetImpairment {
            $impairment = AssetImpairment::query()->with('asset.category')->lockForUpdate()->findOrFail($impairment->id);
            if ($impairment->status === 'POSTED') {
                return $impairment;
            }
            if ($impairment->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'ลงบัญชีได้เฉพาะเอกสารที่อนุมัติแล้ว']);
            }
            $this->validatePost($impairment);
            $asset = $impairment->asset;
            $category = $asset?->category;
            $loss = $category?->impairment_loss_account_id;
            $accumulated = $category?->accumulated_impairment_account_id;
            if (! $loss || ! $accumulated) {
                throw ValidationException::withMessages(['accounts' => 'หมวดสินทรัพย์ยังไม่ได้กำหนดบัญชีขาดทุนและบัญชีด้อยค่าสะสม']);
            }
            foreach ([$loss, $accumulated] as $accountId) {
                $account = Account::query()->find($accountId);
                if (! $account || ! $account->is_active || ! $account->is_postable) {
                    throw ValidationException::withMessages(['accounts' => 'บัญชีด้อยค่าไม่พร้อมลงรายการ']);
                }
            }
            $amount = (float) $impairment->impairment_amount;
            $journal = $journals->postForBranchWithinTransaction(['source_type' => 'ASSET', 'source_id' => (string) $impairment->id, 'source_reference' => $impairment->document_number, 'event_code' => 'asset.impairment', 'entry_date' => $impairment->assessment_date->toDateString(), 'document_date' => $impairment->assessment_date->toDateString(), 'description' => 'ด้อยค่าสินทรัพย์ '.$impairment->document_number, 'lines' => [['account_id' => $loss, 'debit' => number_format($amount, 2, '.', ''), 'credit' => '0.00', 'description' => 'ขาดทุนจากการด้อยค่า'], ['account_id' => $accumulated, 'debit' => '0.00', 'credit' => number_format($amount, 2, '.', ''), 'subledger_type' => 'ASSET', 'subledger_id' => (string) $asset->id, 'description' => 'ด้อยค่าสะสม']]], $impairment->branch, null, $actor);
            $asset->update(['book_accumulated_impairment' => (float) $asset->book_accumulated_impairment + $amount, 'book_value' => max(0, (float) $asset->book_value - $amount), 'updated_by' => $actor->id]);
            AssetValueEvent::query()->create(['asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => $impairment->assessment_date, 'event_type' => 'IMPAIRMENT', 'impairment_delta' => $amount, 'source_type' => 'ASSET_IMPAIRMENT', 'source_id' => $impairment->id, 'journal_entry_id' => $journal->id, 'idempotency_key' => hash('sha256', 'ASSET_IMPAIRMENT|'.$impairment->id), 'created_by' => $actor->id]);
            $impairment->update(['status' => 'POSTED', 'journal_entry_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $impairment->fresh();
        }, 3);
    }

    public function reverse(AssetImpairment $original, string $documentNumber, string $reason, User $actor): AssetImpairment
    {
        $journals = $this->journals ?? app(JournalPostingService::class);

        return DB::transaction(function () use ($original, $documentNumber, $reason, $actor, $journals): AssetImpairment {
            $original = AssetImpairment::query()->with(['asset.category', 'branch', 'journalEntry'])->lockForUpdate()->findOrFail($original->id);
            if ($original->status !== 'POSTED' || $original->reversal_of_id) {
                throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะเอกสารด้อยค่าที่ลงบัญชีแล้ว']);
            }
            if (AssetImpairment::query()->where('reversal_of_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'เอกสารนี้ถูกกลับรายการแล้ว']);
            }
            $asset = $original->asset;
            $amount = (float) $original->impairment_amount;
            if ((float) $asset->book_accumulated_impairment < $amount) {
                throw ValidationException::withMessages(['status' => 'ยอดด้อยค่าสะสมปัจจุบันไม่เพียงพอสำหรับกลับรายการ']);
            }
            $journal = $journals->reverseWithinTransaction($original->journalEntry, ['source_type' => 'ASSET', 'source_id' => (string) $original->id, 'reversal_date' => today()->toDateString(), 'reason' => $reason], $actor);
            $asset->update(['book_accumulated_impairment' => (float) $asset->book_accumulated_impairment - $amount, 'book_value' => (float) $asset->book_value + $amount, 'updated_by' => $actor->id]);
            $reversal = AssetImpairment::query()->create(['document_number' => $documentNumber, 'asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'assessment_date' => today(), 'status' => 'POSTED', 'carrying_amount' => $asset->book_value, 'recoverable_amount' => $asset->book_value, 'impairment_amount' => $amount, 'journal_entry_id' => $journal->id, 'reversal_of_id' => $original->id, 'reversal_journal_entry_id' => $journal->id, 'reversal_reason' => $reason, 'reason' => 'กลับรายการจาก '.$original->document_number.': '.$reason, 'posted_by' => $actor->id, 'posted_at' => now(), 'created_by' => $actor->id]);
            AssetValueEvent::query()->create(['asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => today(), 'event_type' => 'IMPAIRMENT_REVERSAL', 'impairment_delta' => -$amount, 'source_type' => 'ASSET_IMPAIRMENT', 'source_id' => $reversal->id, 'journal_entry_id' => $journal->id, 'reversal_of_event_id' => AssetValueEvent::query()->where('source_type', 'ASSET_IMPAIRMENT')->where('source_id', $original->id)->value('id'), 'idempotency_key' => hash('sha256', 'ASSET_IMPAIRMENT_REVERSAL|'.$original->id), 'created_by' => $actor->id]);

            return $reversal;
        }, 3);
    }
}
