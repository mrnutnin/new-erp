<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Repairs missing GL proof on already-posted legacy Issue/Issue Return documents.
 * It never changes stock quantities and never creates Journal rows outside the
 * existing IssueAccountingPostingService identity/lineage pipeline.
 */
final class LegacyIssueAccountingProofRecoveryService
{
    public function __construct(
        private readonly AccountMappingService $mappings,
        private readonly IssueAccountingPostingService $accounting,
    ) {}

    /** @return array{ready: bool, document_type: string, document_id: int, document_number: string, posting_date: string, rows: Collection, blockers: array<int, string>, summary: array} */
    public function preview(IssueDocument|IssueReturn $document, Warehouse $warehouse, string $postingDate): array
    {
        $rows = $this->rows($document);
        $blockers = [];
        $proofRows = $rows->filter(fn (array $row): bool => $row['allocation']->journal_entry_id !== null || $row['allocation']->status === 'POSTED')->count();
        $alreadyRecovered = $rows->isNotEmpty() && $proofRows === $rows->count();

        if ((int) $document->warehouse_id !== (int) $warehouse->id) {
            $blockers[] = 'WAREHOUSE_CONTEXT_MISMATCH';
        }
        if ($document->status !== 'POSTED') {
            $blockers[] = 'DOCUMENT_NOT_POSTED';
        }
        if ($rows->isEmpty()) {
            $blockers[] = 'NO_COST_ALLOCATIONS';
        }
        if ($proofRows > 0 && ! $alreadyRecovered) {
            $blockers[] = 'PARTIAL_OR_EXISTING_JOURNAL_PROOF';
        }
        if ($rows->contains(fn (array $row): bool => $row['allocation']->status === 'REVERSED' || $row['allocation']->cost_status === 'PENDING')) {
            $blockers[] = 'ALLOCATION_NOT_POSTABLE';
        }

        $period = FiscalPeriod::query()
            ->where('start_date', '<=', $postingDate)
            ->where('end_date', '>=', $postingDate)
            ->where('status', 'OPEN')
            ->first(['id', 'start_date', 'end_date', 'status']);
        if (! $period) {
            $blockers[] = 'POSTING_PERIOD_NOT_OPEN';
        }

        $issueType = $document instanceof IssueReturn ? (string) $document->issue?->issue_type : (string) $document->issue_type;
        $event = strtoupper($issueType) === 'PRODUCTION'
            ? ($document instanceof IssueReturn ? 'production.material_return' : 'production.material_issue')
            : ($document instanceof IssueReturn ? 'inventory.issue_return' : 'inventory.issue');
        foreach (['INVENTORY', strtoupper($issueType) === 'PRODUCTION' ? 'WIP' : 'ISSUE_EXPENSE'] as $role) {
            try {
                $this->mappings->resolveForEvent($event, $role);
            } catch (ValidationException) {
                $blockers[] = 'ACCOUNT_MAPPING_NOT_READY:'.$event.':'.$role;
            }
        }

        $value = $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, (string) $row['allocation']->value, 8), '0.00000000');

        return [
            'ready' => $blockers === [] && ! $alreadyRecovered,
            'already_recovered' => $alreadyRecovered,
            'document_type' => $document instanceof IssueReturn ? 'ISSUE_RETURN' : 'ISSUE',
            'document_id' => (int) $document->id,
            'document_number' => (string) $document->document_number,
            'posting_date' => $postingDate,
            'rows' => $rows,
            'blockers' => array_values(array_unique($blockers)),
            'summary' => ['rows' => $rows->count(), 'proof_rows' => $proofRows, 'value' => $value, 'period_id' => $period?->id],
        ];
    }

    public function recover(IssueDocument|IssueReturn $document, Warehouse $warehouse, User $actor, string $postingDate, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'เหตุผลการกู้คืนต้องมีอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($document, $warehouse, $actor, $postingDate, $reason): array {
            $locked = $document instanceof IssueReturn
                ? IssueReturn::with('issue:id,issue_type')->lockForUpdate()->findOrFail($document->id)
                : IssueDocument::query()->lockForUpdate()->findOrFail($document->id);
            $preview = $this->preview($locked, $warehouse, $postingDate);
            if (! $preview['ready']) {
                throw ValidationException::withMessages(['recovery' => 'กู้คืนไม่ได้: '.implode(', ', $preview['blockers'])]);
            }

            $issueType = $locked instanceof IssueReturn ? (string) $locked->issue?->issue_type : (string) $locked->issue_type;
            $journal = $this->accounting->post(
                $locked,
                $warehouse,
                $actor,
                $preview['rows'],
                $locked instanceof IssueReturn,
                $issueType,
                $postingDate,
                [
                    'recovery_type' => 'LEGACY_ORIGINAL_PROOF',
                    'original_effective_date' => $locked->document_date->format('Y-m-d'),
                    'recovery_reason' => $reason,
                ],
            );

            return ['journal_id' => (int) $journal->id, 'document_id' => (int) $locked->id, 'summary' => $preview['summary']];
        }, 3);
    }

    private function rows(IssueDocument|IssueReturn $document): Collection
    {
        if ($document instanceof IssueDocument) {
            $document->loadMissing('lines:id,document_id,item_id,stock_movement_id');
            $movementIds = $document->lines->pluck('stock_movement_id')->filter()->values();
            $allocations = CostAllocation::query()
                ->whereIn('stock_movement_id', $movementIds)
                ->where('direction', 'OUT')
                ->orderBy('id')
                ->get(['id', 'stock_movement_id', 'item_id', 'quantity', 'unit_cost', 'value', 'journal_entry_id', 'status', 'cost_status', 'revision']);

            return $allocations->map(fn (CostAllocation $allocation): array => ['allocation' => $allocation, 'item_id' => (int) $allocation->item_id]);
        }

        $document->loadMissing('issue:id,issue_type', 'lines:id,return_id,issue_line_id', 'lines.issueLine:id,item_id', 'lines.sourceAllocations:id,return_line_id,cost_allocation_id');
        $allocationIds = $document->lines->flatMap(fn ($line) => $line->sourceAllocations->pluck('cost_allocation_id'))->filter()->values();
        $allocations = CostAllocation::query()->whereIn('id', $allocationIds)->orderBy('id')->get(['id', 'stock_movement_id', 'item_id', 'quantity', 'unit_cost', 'value', 'journal_entry_id', 'status', 'cost_status', 'revision']);

        return $allocations->map(fn (CostAllocation $allocation): array => ['allocation' => $allocation, 'item_id' => (int) $allocation->item_id]);
    }
}
