<?php

namespace App\Modules\Wms\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Services\LandedCostPropagationCostResolver;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationBatch;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CostPropagationManualTriggerService
{
    private const TYPES = [
        'OPENING_BALANCE' => 'ยอดยกมาสินค้า',
        'INVENTORY_ADJUSTMENT' => 'ปรับปรุงสินค้า',
        'PRODUCTION_FINISHED_RECEIPT' => 'รับสินค้าผลิตเสร็จ',
        'ISSUE_DOCUMENT' => 'ใบเบิกสินค้า/วัตถุดิบ',
        'ISSUE_RETURN' => 'ใบรับคืนจากการเบิก',
        'WMS_TRANSFER' => 'โอนสินค้า',
        'PURCHASE_DOCUMENT' => 'ใบซื้อเชื่อ',
        'PURCHASE_CREDIT_RETURN' => 'ใบลดหนี้ซื้อแบบคืนสินค้า',
        'PURCHASE_RETURN' => 'ใบคืนสินค้าให้ผู้ขาย',
        'LANDED_COST' => 'Landed Cost',
        'PHYSICAL_SALE' => 'ขายสินค้า',
        'SALES_RETURN' => 'รับคืนสินค้าจากการขาย',
    ];

    public function __construct(
        private readonly CostPropagationTriggerPlanner $planner,
        private readonly CostPropagationTriggerDispatcher $dispatcher,
        private readonly LandedCostPropagationCostResolver $landedCosts,
        private readonly CostRevaluationCompensationResolver $compensations,
    ) {}

    /** @return array<string, string> */
    public function types(): array
    {
        return self::TYPES;
    }

    /** @return list<array{id:int,text:string,revision:int,status:string,document_date:?string}> */
    public function options(string $type, int $warehouseId, ?string $search = null): array
    {
        $type = $this->type($type);
        $query = $this->query($type, $warehouseId);
        $referenceField = $this->referenceField($type);
        $search = trim((string) $search);
        if ($search !== '') {
            $prefix = addcslashes($search, '\\%_').'%';
            $query->where(function (Builder $query) use ($search, $prefix, $referenceField): void {
                $query->where($referenceField, 'like', $prefix);
                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search);
                }
            });
        }

        return $query->latest('id')->limit(20)->get()->map(fn (Model $document): array => $this->option($type, $document))->all();
    }

    /** @return array<string, mixed> */
    public function preview(string $type, int $documentId, int $warehouseId): array
    {
        $type = $this->type($type);
        $document = $this->query($type, $warehouseId)->whereKey($documentId)->firstOrFail();
        $revision = $this->revision($type, $document);
        $plan = $this->planner->plan($type, $documentId, $revision);
        $proposed = $type === 'LANDED_COST' ? $this->landedCosts->resolve($document) : [];
        $allocationIds = collect($plan['partitions'])->flatMap(fn (array $partition): array => $partition['root_allocation_ids'])->unique()->values();
        $allocations = CostAllocation::query()->whereIn('id', $allocationIds->all())->orderBy('id')
            ->get(['id', 'parent_allocation_id', 'unit_cost', 'business_date']);
        $compensation = $this->compensations->resolve($allocations, $revision);
        $proposed = array_replace($proposed, $compensation['costs']);
        $costs = $allocations->map(fn (CostAllocation $allocation): array => [
            'allocation_id' => (int) $allocation->id,
            'current_unit_cost' => (string) $allocation->unit_cost,
            'proposed_unit_cost' => (string) ($proposed[(int) $allocation->id] ?? $allocation->unit_cost),
        ])->all();

        if ($compensation['blockers'] !== []) {
            $plan['blockers'] = array_values(array_unique([...$plan['blockers'], ...$compensation['blockers']]));
            $plan['summary']['ready'] = false;
        }

        return [...$plan, 'document' => $this->option($type, $document), 'root_costs' => $costs, 'proposed_unit_costs' => $proposed, 'compensation' => $compensation];
    }

    public function trigger(string $type, int $documentId, int $warehouseId, User $actor, string $reason, ?string $ipAddress = null, ?string $userAgent = null): CostRevaluationBatch
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }
        $preview = $this->preview($type, $documentId, $warehouseId);
        if (! $preview['summary']['ready']) {
            throw ValidationException::withMessages(['document_id' => 'เอกสารยังไม่พร้อม Trigger: '.implode(', ', $preview['blockers'])]);
        }
        $batch = $this->dispatcher->dispatch(
            $preview['source']['document_type'],
            (int) $preview['source']['document_id'],
            (int) $preview['source']['revision'],
            $preview['proposed_unit_costs'],
            $actor->id,
        );
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => 'wms.cost_revaluation.manual_triggered',
            'subject_type' => $batch->getMorphClass(),
            'subject_id' => $batch->id,
            'old_values' => null,
            'new_values' => [
                'reason' => trim($reason),
                'source_document_type' => $preview['source']['document_type'],
                'source_document_id' => $preview['source']['document_id'],
                'source_revision' => $preview['source']['revision'],
                'expected_partitions' => $preview['summary']['expected_partitions'],
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $batch->fresh('runs');
    }

    private function type(string $type): string
    {
        $type = strtoupper(trim($type));
        if (! array_key_exists($type, self::TYPES)) {
            throw ValidationException::withMessages(['document_type' => 'ไม่รองรับประเภทเอกสารที่เลือก']);
        }

        return $type;
    }

    private function query(string $type, int $warehouseId): Builder
    {
        $posted = ['POSTED', 'REVERSED'];

        return match ($type) {
            'OPENING_BALANCE' => OpeningBalanceBatch::query()->select(['id', 'warehouse_id', 'source_reference', 'cutover_date', 'status'])->where('warehouse_id', $warehouseId)->whereIn('status', $posted),
            'INVENTORY_ADJUSTMENT' => InventoryAdjustmentDocument::query()->select(['id', 'warehouse_id', 'document_number', 'document_date', 'document_context', 'status', 'reversal_revision'])->where('warehouse_id', $warehouseId)->where('document_context', 'INVENTORY_ADJUSTMENT')->whereIn('status', $posted),
            'PRODUCTION_FINISHED_RECEIPT' => InventoryAdjustmentDocument::query()->select(['id', 'warehouse_id', 'document_number', 'document_date', 'document_context', 'status', 'reversal_revision'])->where('warehouse_id', $warehouseId)->where('document_context', 'PRODUCTION_RECEIPT')->whereIn('status', $posted),
            'ISSUE_DOCUMENT' => IssueDocument::withTrashed()->select(['id', 'warehouse_id', 'document_number', 'document_date', 'status'])->where('warehouse_id', $warehouseId)->whereIn('status', $posted),
            'ISSUE_RETURN' => IssueReturn::withTrashed()->select(['id', 'warehouse_id', 'document_number', 'document_date', 'status', 'reversal_revision'])->where('warehouse_id', $warehouseId)->whereIn('status', $posted),
            'WMS_TRANSFER' => Transfer::withTrashed()->select(['id', 'source_warehouse_id', 'destination_warehouse_id', 'document_number', 'document_date', 'status'])->withCount('events')->where(fn (Builder $query) => $query->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId))->whereIn('status', ['DISPATCHED', 'ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED', 'VOID']),
            'PURCHASE_DOCUMENT' => PurchaseDocument::query()->select(['id', 'warehouse_id', 'document_type', 'document_number', 'document_date', 'status', 'reversal_revision'])->where('warehouse_id', $warehouseId)->where('document_type', 'INVOICE')->whereIn('status', $posted),
            'PURCHASE_CREDIT_RETURN' => PurchaseDocument::query()->select(['id', 'warehouse_id', 'document_type', 'credit_note_mode', 'document_number', 'document_date', 'status', 'reversal_revision'])->where('warehouse_id', $warehouseId)->where('document_type', 'CREDIT_NOTE')->where('credit_note_mode', 'RETURN')->whereIn('status', $posted),
            'PURCHASE_RETURN' => PurchaseReturn::query()->select(['id', 'warehouse_id', 'return_number', 'return_date', 'status'])->where('warehouse_id', $warehouseId)->whereIn('status', $posted),
            'LANDED_COST' => LandedCost::query()->select(['id', 'warehouse_id', 'document_number', 'business_date', 'status'])->where('warehouse_id', $warehouseId)->where('status', 'POSTED'),
            'PHYSICAL_SALE' => PhysicalSale::query()->select(['id', 'warehouse_id', 'document_number', 'document_date', 'status'])->where('warehouse_id', $warehouseId)->where('status', 'POSTED'),
            'SALES_RETURN' => SalesReturn::query()->select(['id', 'warehouse_id', 'document_number', 'document_date', 'status', 'reversal_revision'])->where('warehouse_id', $warehouseId)->whereIn('status', $posted),
        };
    }

    private function referenceField(string $type): string
    {
        return match ($type) {
            'OPENING_BALANCE' => 'source_reference',
            'PURCHASE_RETURN' => 'return_number',
            default => 'document_number',
        };
    }

    private function dateField(string $type): string
    {
        return match ($type) {
            'OPENING_BALANCE' => 'cutover_date',
            'PURCHASE_RETURN' => 'return_date',
            'LANDED_COST' => 'business_date',
            default => 'document_date',
        };
    }

    private function revision(string $type, Model $document): int
    {
        if ($type === 'WMS_TRANSFER') {
            return (int) $document->events_count;
        }

        return in_array($type, ['INVENTORY_ADJUSTMENT', 'PRODUCTION_FINISHED_RECEIPT', 'ISSUE_RETURN', 'PURCHASE_DOCUMENT', 'PURCHASE_CREDIT_RETURN', 'SALES_RETURN'], true)
            ? (int) ($document->reversal_revision ?? 0)
            : 0;
    }

    /** @return array{id:int,text:string,revision:int,status:string,document_date:?string} */
    private function option(string $type, Model $document): array
    {
        $reference = (string) ($document->{$this->referenceField($type)} ?: '#'.$document->id);
        $date = $document->{$this->dateField($type)}?->format('Y-m-d');
        $revision = $this->revision($type, $document);

        return [
            'id' => (int) $document->id,
            'text' => $reference.' · '.($date ?: 'ไม่พบวันที่').' · '.$document->status.($revision > 0 ? " · revision {$revision}" : ''),
            'revision' => $revision,
            'status' => (string) $document->status,
            'document_date' => $date,
        ];
    }
}
