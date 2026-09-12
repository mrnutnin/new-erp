<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Support\InventoryRoundingAllocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Posts the immutable Stock Cost -> GL proof for issue and issue-return documents. */
final class IssueAccountingPostingService
{
    public function __construct(
        private readonly AccountMappingService $mappings,
        private readonly JournalPostingService $journals,
        private readonly InventoryCostAllocationService $allocations,
    ) {}

    /** @param Collection<int, array{allocation: CostAllocation, item_id: int}> $rows */
    public function post(Model $document, Warehouse $warehouse, User $actor, Collection $rows, bool $isReturn, string $issueType, ?string $postingDate = null, array $postingMetadata = []): JournalEntry
    {
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['allocation' => 'ไม่พบ Cost Allocation สำหรับลงบัญชี']);
        }

        $production = strtoupper($issueType) === 'PRODUCTION';
        $event = $production
            ? ($isReturn ? 'production.material_return' : 'production.material_issue')
            : ($isReturn ? 'inventory.issue_return' : 'inventory.issue');
        $counterRole = $production ? 'WIP' : 'ISSUE_EXPENSE';
        $inventory = $this->mappings->resolveForEvent($event, 'INVENTORY');
        $counter = $this->mappings->resolveForEvent($event, $counterRole);
        $amounts = InventoryRoundingAllocator::allocate($rows->pluck('allocation.value')->all());
        $lines = [];

        foreach ($rows->values() as $index => $row) {
            $amount = $amounts[$index];
            $itemId = (string) $row['item_id'];
            $inventoryLine = $this->line($inventory['account'], $itemId, $amount, $isReturn, 'สินค้าคงเหลือ');
            $counterLine = $this->line($counter['account'], $itemId, $amount, ! $isReturn, $production ? 'งานระหว่างทำ' : 'ค่าใช้จ่ายจากการเบิก');
            array_push($lines, ...($isReturn ? [$inventoryLine, $counterLine] : [$counterLine, $inventoryLine]));
        }

        $documentDate = $document->document_date->format('Y-m-d');
        $date = $postingDate ?: $documentDate;
        $sourceType = $isReturn ? 'WMS_ISSUE_RETURN' : 'WMS_ISSUE';
        $journal = $this->journals->postWithinTransaction([
            'source_type' => $sourceType,
            'source_id' => (string) $document->id,
            'source_reference' => $document->document_number,
            'event_code' => $event,
            'entry_date' => $date,
            'document_date' => $documentDate,
            'description' => ($isReturn ? 'รับคืนจากการเบิก ' : 'เบิกสินค้า ').$document->document_number,
            'posting_metadata' => array_merge([
                'contract_version' => 1,
                'event_code' => $event,
                'accounts' => [$inventory['provenance'], $counter['provenance']],
            ], $postingMetadata),
            'lines' => $lines,
        ], $warehouse, $actor);

        $journalLines = $journal->lines()->orderBy('line_number')->get(['id', 'journal_entry_id', 'line_number']);
        foreach ($rows->values() as $index => $row) {
            $inventoryLine = $journalLines->get(($index * 2) + ($isReturn ? 0 : 1));
            if (! $inventoryLine) {
                throw ValidationException::withMessages(['journal' => 'Journal ไม่มีบรรทัด Inventory ครบทุก Cost Allocation']);
            }
            $this->allocations->linkJournalLineWithinTransaction($row['allocation'], $inventoryLine);
        }

        return $journal;
    }

    private function line($account, string $itemId, string $amount, bool $debit, string $description): array
    {
        return [
            'account_id' => $account->id,
            'subledger_type' => $account->control_account_type !== null ? 'ITEM' : null,
            'subledger_id' => $account->control_account_type !== null ? $itemId : null,
            'description' => $description.' item #'.$itemId,
            'debit' => $debit ? $amount : '0.00',
            'credit' => $debit ? '0.00' : $amount,
        ];
    }
}
