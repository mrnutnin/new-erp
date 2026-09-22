<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProductionFinishedReceiptDocumentService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $values, Warehouse $warehouse, User $actor, Request $request): InventoryAdjustmentDocument
    {
        $this->assertValues($values);
        $warehouse->loadMissing('branch');
        if (! $warehouse->branch) {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขา']);
        }
        $idempotencyKey = $values['idempotency_key'] ?? 'production-receipt:'.bin2hex(random_bytes(12));
        $existing = InventoryAdjustmentDocument::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('document_context', ManualProductionReceiptContract::CONTEXT)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            $this->assertSameRetryPayload($existing->load('lines'), $values);
            return $existing->fresh('lines');
        }
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PRODUCTION_FINISHED_RECEIPT')->where('is_active', true)->first();
        if (! $sequence) {
            throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบรับสินค้าผลิตเสร็จ']);
        }

        return DB::transaction(function () use ($values, $warehouse, $actor, $request, $sequence, $idempotencyKey): InventoryAdjustmentDocument {
            $date = Carbon::parse($values['document_date']);
            $document = InventoryAdjustmentDocument::query()->create([
                'warehouse_id' => $warehouse->id,
                'document_number' => $this->sequences->issueForBranch($sequence, $warehouse->branch, $date),
                'document_date' => $date,
                'direction' => 'GAIN',
                'reason' => $values['reason'],
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
                'document_context' => ManualProductionReceiptContract::CONTEXT,
                'source_issue_id' => $values['source_issue_ids'][0],
            ]);
            $this->sequences->recordIssued($sequence->fresh(), $document->document_number, 'inventory_adjustment_document', $document->id, $date, $actor->id);
            $this->replaceLines($document, $values, $actor->id);
            $this->syncSources($document, $values['source_consumptions']);
            $this->audit->record('wms.production_finished_receipt.created', $document, [], $document->load('lines')->toArray(), $actor, $request);

            return $document;
        }, 3);
    }

    public function update(InventoryAdjustmentDocument $document, array $values, User $actor, Request $request): InventoryAdjustmentDocument
    {
        $this->assertValues($values);

        return DB::transaction(function () use ($document, $values, $actor, $request): InventoryAdjustmentDocument {
            $document = InventoryAdjustmentDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertDraftProductionReceipt($document);
            $before = $document->load('lines')->toArray();
            $document->forceFill([
                'document_date' => Carbon::parse($values['document_date']),
                'direction' => 'GAIN',
                'reason' => $values['reason'],
                'source_issue_id' => $values['source_issue_ids'][0],
            ])->save();
            $document->lines()->forceDelete();
            $this->replaceLines($document, $values, (int) $document->created_by);
            $this->syncSources($document, $values['source_consumptions']);
            $this->audit->record('wms.production_finished_receipt.updated', $document, $before, $document->fresh()->load('lines')->toArray(), $actor, $request);

            return $document->fresh('lines');
        }, 3);
    }

    public function approve(InventoryAdjustmentDocument $document, User $actor, Request $request): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $actor, $request): InventoryAdjustmentDocument {
            $document = InventoryAdjustmentDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraftProductionReceipt($document);
            if ($document->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'อนุมัติได้เฉพาะเอกสารร่างที่มีรายการ']);
            }
            $before = $document->toArray();
            $document->lines->each(fn (InventoryAdjustment $line) => $line->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id])->save());
            $document->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id])->save();
            $this->audit->record('wms.production_finished_receipt.approved', $document, $before, $document->fresh()->load('lines')->toArray(), $actor, $request);

            return $document->fresh('lines');
        }, 3);
    }

    private function assertSameRetryPayload(InventoryAdjustmentDocument $document, array $values): void
    {
        $expected = collect($values['lines'])->map(fn (array $line): array => [
            'item_id' => (int) $line['item_id'], 'uom_id' => (int) $line['uom_id'],
            'quantity' => $this->decimal($line['quantity']), 'value' => $this->decimal($line['value']),
        ])->sortBy(fn (array $line): string => implode(':', $line))->values()->all();
        $actual = $document->lines->map(fn (InventoryAdjustment $line): array => [
            'item_id' => (int) $line->item_id, 'uom_id' => (int) $line->uom_id,
            'quantity' => $this->decimal($line->quantity), 'value' => $this->decimal($line->value),
        ])->sortBy(fn (array $line): string => implode(':', $line))->values()->all();
        if ($document->document_date?->format('Y-m-d') !== Carbon::parse($values['document_date'])->format('Y-m-d')
            || $document->reason !== $values['reason']
            || (int) $document->source_issue_id !== (int) $values['source_issue_ids'][0]
            || $actual !== $expected) {
            throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับข้อมูลอื่นแล้ว']);
        }
    }

    private function decimal(mixed $value): string
    {
        return BigDecimal::of((string) $value)->toScale(8)->__toString();
    }

    private function replaceLines(InventoryAdjustmentDocument $document, array $values, int $creatorId): void
    {
        foreach ($values['lines'] as $position => $line) {
            $item = Item::query()->findOrFail($line['item_id']);
            if ((int) $line['uom_id'] !== (int) $item->base_uom_id) {
                throw ValidationException::withMessages(["lines.{$position}.uom_id" => 'รับผลิตต้องใช้หน่วยฐานของสินค้า']);
            }
            InventoryAdjustment::query()->create([
                ...$line,
                'direction' => 'GAIN',
                'document_id' => $document->id,
                'line_number' => $position + 1,
                'warehouse_id' => $document->warehouse_id,
                'business_date' => $document->document_date,
                'reason' => $values['reason'],
                'idempotency_key' => ($values['line_idempotency_prefix'] ?? 'production-receipt:'.$document->id).':line:'.($position + 1),
                'created_by' => $creatorId,
            ]);
        }
    }

    /** @param list<array<string,mixed>> $sources */
    private function syncSources(InventoryAdjustmentDocument $document, array $sources): void
    {
        if (! Schema::hasTable('wms_production_receipt_sources')) {
            return;
        }
        $now = now();
        $payload = collect($sources)->values()->map(fn (array $source, int $position): array => [
            'receipt_document_id' => (int) $document->id,
            'issue_document_id' => (int) $source['issue_document_id'],
            'issue_line_id' => (int) $source['issue_line_id'],
            'source_allocation_id' => (int) $source['source_allocation_id'],
            'source_allocation_revision' => (int) $source['source_allocation_revision'],
            'consumed_quantity' => (string) $source['consumed_quantity'],
            'consumed_value' => (string) $source['consumed_value'],
            'position' => $position + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('wms_production_receipt_sources')->where('receipt_document_id', $document->id)->delete();
        if ($payload !== []) {
            DB::table('wms_production_receipt_sources')->insert($payload);
        }
    }

    private function assertValues(array $values): void
    {
        ManualProductionReceiptContract::assert([
            ...$values,
            'document_context' => ManualProductionReceiptContract::CONTEXT,
            'direction' => 'GAIN',
        ]);
        if (empty($values['source_issue_ids']) || ! isset($values['source_consumptions']) || ! is_array($values['source_consumptions'])) {
            throw ValidationException::withMessages(['sources' => 'ใบรับผลิตต้องมีใบเบิกและต้นทุนต้นทางที่ตรวจสอบแล้ว']);
        }
    }

    private function assertDraftProductionReceipt(InventoryAdjustmentDocument $document): void
    {
        if ($document->document_context !== ManualProductionReceiptContract::CONTEXT || $document->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'ทำรายการได้เฉพาะใบรับผลิตสถานะร่าง']);
        }
    }
}
