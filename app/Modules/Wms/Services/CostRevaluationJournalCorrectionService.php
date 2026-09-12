<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Corrects legacy revaluation journals without editing immutable ledger rows. */
final class CostRevaluationJournalCorrectionService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
    ) {}

    /** @return array{corrected:int,skipped:int,details:list<array<string,mixed>>} */
    public function correct(CostRevaluationRun $run, ?User $actor = null): array
    {
        $corrected = 0;
        $skipped = 0;
        $details = [];

        CostRevaluationDelta::query()->where('run_id', $run->id)->whereNotNull('applied_cost_allocation_id')->orderBy('id')->chunkById(50, function ($deltas) use ($run, $actor, &$corrected, &$skipped, &$details): void {
            foreach ($deltas as $delta) {
                $result = DB::transaction(fn (): array => $this->correctDelta($run, $delta->id, $actor), 3);
                $result['corrected'] ? $corrected++ : $skipped++;
                $details[] = $result;
            }
        });

        return compact('corrected', 'skipped', 'details');
    }

    /** @return array{delta_id:int,corrected:bool,reason:string,original_journal_id:?int,correction_journal_id:?int} */
    private function correctDelta(CostRevaluationRun $run, int $deltaId, ?User $actor): array
    {
        $delta = CostRevaluationDelta::query()->with('appliedCostAllocation.journalEntry.lines')->lockForUpdate()->findOrFail($deltaId);
        $allocation = $delta->appliedCostAllocation;
        $journal = $allocation?->journalEntry;
        if (! $allocation || ! $journal || ! $delta->target_event) {
            return ['delta_id' => $delta->id, 'corrected' => false, 'reason' => 'NO_GL_JOURNAL', 'original_journal_id' => $journal?->id, 'correction_journal_id' => null];
        }

        $expected = $this->expectedInventoryImpact($delta);
        $actual = $this->inventoryImpact($journal->id);
        if ($this->money($expected) === $this->money($actual)) {
            return ['delta_id' => $delta->id, 'corrected' => false, 'reason' => 'ALREADY_CORRECT', 'original_journal_id' => $journal->id, 'correction_journal_id' => null];
        }

        $postingDate = $run->posting_date?->format('Y-m-d') ?: $delta->created_at?->format('Y-m-d');
        $sourceKey = "correction:run:{$run->id}:delta:{$delta->id}:journal:{$journal->id}";
        $this->journals->reverseWithinTransaction($journal, [
            'source_type' => 'WMS_REVALUATION',
            'source_id' => $sourceKey.':reverse',
            'reversal_date' => $postingDate,
            'reason' => 'แก้ไข Journal Revaluation ที่ลงทิศทางผิดตาม signed delta',
        ], $actor);

        $event = (string) $delta->target_event;
        [$primaryRole, $offsetRole] = $this->roles($event, BigDecimal::of((string) $delta->delta_value));
        $primary = $this->mappings->resolveForEvent($event, $primaryRole);
        $offset = $this->mappings->resolveForEvent($event, $offsetRole);
        $value = BigDecimal::of((string) $delta->delta_value);
        $amount = $value->abs()->toScale(2, RoundingMode::HALF_UP)->__toString();
        $warehouse = Warehouse::query()->whereKey($delta->target_warehouse_id)->where('branch_id', $delta->target_branch_id)->firstOrFail();
        $correction = $this->journals->postWithinTransaction([
            'source_type' => 'WMS_REVALUATION',
            'source_id' => $sourceKey.':post',
            'source_reference' => 'WMS Revaluation correction Run #'.$run->id,
            'event_code' => $event,
            'entry_date' => $postingDate,
            'document_date' => $postingDate,
            'description' => 'แก้ไขทิศทาง Journal Revaluation จาก signed delta',
            'posting_metadata' => [
                'contract_version' => 1,
                'event_code' => $event,
                'revaluation_run_id' => $run->id,
                'revaluation_delta_id' => $delta->id,
                'correction_of_journal_id' => $journal->id,
                'correction_contract' => 'signed-delta-v1',
                'impact_bucket' => $delta->impact_bucket,
                'impact_date' => data_get($run->shadow_snapshot, 'summary.impact_date'),
                'posting_date' => $postingDate,
                'accounts' => [$primary['provenance'], $offset['provenance']],
            ],
            'lines' => $value->isPositive() ? [
                $this->line($primary['account'], $allocation, $amount, '0.00'),
                $this->line($offset['account'], $allocation, '0.00', $amount),
            ] : [
                $this->line($offset['account'], $allocation, $amount, '0.00'),
                $this->line($primary['account'], $allocation, '0.00', $amount),
            ],
        ], $warehouse, $actor);

        return ['delta_id' => $delta->id, 'corrected' => true, 'reason' => 'SIGNED_DELTA_MISMATCH', 'original_journal_id' => $journal->id, 'correction_journal_id' => $correction->id];
    }

    private function expectedInventoryImpact(CostRevaluationDelta $delta): BigDecimal
    {
        $value = BigDecimal::of((string) $delta->delta_value);
        [$primary, $offset] = $this->roles((string) $delta->target_event, $value);

        return $primary === 'INVENTORY' ? $value : $value->negated();
    }

    private function inventoryImpact(int $journalId): BigDecimal
    {
        $rows = DB::table('journal_entry_lines as lines')->join('accounts', 'accounts.id', '=', 'lines.account_id')->where('lines.journal_entry_id', $journalId)->where('accounts.control_account_type', 'INVENTORY')->get(['lines.debit', 'lines.credit']);

        return $rows->reduce(fn (BigDecimal $sum, object $row): BigDecimal => $sum->plus(BigDecimal::of((string) $row->debit)->minus((string) $row->credit)), BigDecimal::zero());
    }

    /** @return array{0:string,1:string} */
    private function roles(string $event, BigDecimal $value): array
    {
        return match ($event) {
            'inventory.revaluation.cogs' => ['COGS', 'INVENTORY'],
            'inventory.revaluation.issue_expense' => ['ISSUE_EXPENSE', 'INVENTORY'],
            'inventory.revaluation.sales_return' => ['INVENTORY', 'COGS'],
            'inventory.revaluation.issue_return' => ['INVENTORY', 'ISSUE_EXPENSE'],
            'inventory.revaluation.rounding' => ['INVENTORY', $value->isPositive() ? 'ROUNDING_GAIN' : 'ROUNDING_LOSS'],
            'production.revaluation.wip' => ['WIP', 'INVENTORY'],
            'production.revaluation.finished_goods' => ['FINISHED_GOODS', 'WIP'],
            'production.revaluation.material_return' => ['INVENTORY', 'WIP'],
            'purchasing.revaluation.return_cost' => ['PURCHASE_RETURN_VARIANCE', 'INVENTORY'],
            default => ['INVENTORY', $value->isPositive() ? 'RECOST_GAIN' : 'RECOST_LOSS'],
        };
    }

    /** @return array<string,int|string|null> */
    private function line(Account $account, $allocation, string $debit, string $credit): array
    {
        return ['account_id' => (int) $account->id, 'subledger_type' => $account->control_account_type !== null ? 'ITEM' : null, 'subledger_id' => $account->control_account_type !== null ? (string) $allocation->item_id : null, 'description' => 'Cost Revaluation correction · '.$allocation->id, 'debit' => $debit, 'credit' => $credit];
    }

    private function money(BigDecimal $value): string
    {
        return $value->toScale(2, RoundingMode::HALF_UP)->__toString();
    }
}
