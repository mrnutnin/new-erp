<?php

namespace App\Modules\Wms\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueLine;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\IssueReturnLine;
use App\Modules\Wms\Models\IssueReturnLineAllocation;
use App\Modules\Wms\Models\Item;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueReturnService
{
    public function createIssue(array $v, Warehouse $w, User $u, DocumentSequenceService $seq, AuditLogger $audit, Request $req): IssueDocument
    {
        return DB::transaction(function () use ($v, $w, $u, $seq, $audit, $req) {
            $s = $this->sequence($w, 'INVENTORY_ISSUE');
            $date = Carbon::parse((string) $v['document_date']);
            $n = $seq->issueAvailableForBranch($s, $this->branch($w), $date, fn (string $number): bool => IssueDocument::withTrashed()->where('document_number', $number)->exists());
            $d = IssueDocument::create(['warehouse_id' => $w->id, 'document_number' => $n, 'document_date' => $date->toDateString(), 'issue_type' => $v['issue_type'], 'reason' => $v['reason'], 'idempotency_key' => 'issue-document:'.bin2hex(random_bytes(12)), 'created_by' => $u->id]);
            $seq->recordIssued($s->fresh(), $n, 'wms_issue_documents', $d->id, $date, $u->id);
            foreach ($v['lines'] as $i => $l) {
                $item = Item::findOrFail($l['item_id']);
                if ((int) $item->base_uom_id !== (int) $l['uom_id']) {
                    throw ValidationException::withMessages(['lines.'.$i.'.uom_id' => 'ใบเบิกใช้หน่วย Stock ของสินค้าเท่านั้น']);
                }IssueLine::create(['document_id' => $d->id, 'item_id' => $item->id, 'uom_id' => $l['uom_id'], 'quantity' => $l['quantity'], 'line_number' => $i + 1]);
            }$audit->record('wms.issue.created', $d, $before = [], $d->fresh()->load('lines')->toArray(), $u, $req);

            return $d;
        });
    }

    public function approve(IssueDocument|IssueReturn $d, User $u, AuditLogger $audit, Request $r): IssueDocument|IssueReturn
    {
        return DB::transaction(function () use ($d, $u, $audit, $r) {
            $model = $d instanceof IssueDocument ? IssueDocument::class : IssueReturn::class;
            $event = $d instanceof IssueDocument ? 'wms.issue.approved' : 'wms.issue_return.approved';
            $x = $model::query()->lockForUpdate()->findOrFail($d->id);
            if ($x->status !== 'DRAFT' || ! $x->lines()->exists()) {
                throw ValidationException::withMessages(['status' => 'อนุมัติได้เฉพาะร่างที่มีรายการ']);
            }
            if ($x instanceof IssueReturn) {
                $this->assertReturnQuantitiesWithinIssued($x->load('lines'));
            }
            $b = $x->toArray();
            $x->update(['status' => 'APPROVED', 'approved_by' => $u->id]);
            $audit->record($event, $x, $b, $x->fresh()->toArray(), $u, $r);

            return $x->fresh();
        });
    }

    public function post(IssueDocument $d, Warehouse $w, User $u, AuditLogger $audit, Request $r): IssueDocument
    {
        return DB::transaction(function () use ($d, $w, $u, $audit, $r) {
            $x = IssueDocument::query()->lockForUpdate()->findOrFail($d->id);
            if ($x->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'ลง Stock ได้เฉพาะเอกสารที่อนุมัติแล้ว']);
            }foreach ($x->lines as $l) {
                $m = app(StockMovementService::class)->recordIntent(['warehouse_id' => $w->id, 'item_id' => $l->item_id, 'uom_id' => $l->uom_id, 'movement_type' => 'ISSUE', 'direction' => 'OUT', 'quantity' => (string) $l->quantity, 'base_quantity' => (string) $l->quantity, 'business_date' => $x->document_date->format('Y-m-d'), 'source_type' => 'ISSUE_DOCUMENT', 'source_id' => (string) $x->id, 'source_reference' => $x->document_number, 'idempotency_key' => 'issue:'.$x->id.':line:'.$l->id, 'metadata' => ['issue_type' => $x->issue_type]]);
                $m = app(StockMovementService::class)->post($m);
                $a = CostAllocation::where('stock_movement_id', $m->id)->latest('id')->first();
                $l->update(['stock_movement_id' => $m->id, 'cost_allocation_id' => $a?->id]);
            }$b = $x->toArray();
            $x->update(['status' => 'POSTED', 'posted_by' => $u->id]);
            $audit->record('wms.issue.posted', $x, $b, $x->fresh()->load('lines')->toArray(), $u, $r);

            return $x->fresh();
        }, 3);
    }

    public function createReturn(array $v, Warehouse $w, User $u, DocumentSequenceService $seq, AuditLogger $audit, Request $req): IssueReturn
    {
        return DB::transaction(function () use ($v, $w, $u, $seq, $audit, $req) {
            $issue = IssueDocument::with('lines')->where('warehouse_id', $w->id)->lockForUpdate()->findOrFail($v['issue_document_id']);
            if ($issue->status !== 'POSTED') {
                throw ValidationException::withMessages(['issue_document_id' => 'รับคืนได้เฉพาะใบเบิกที่ลง Stock แล้ว']);
            }
            $requested = [];
            foreach ($v['lines'] as $l) {
                $key = (int) $l['issue_line_id'];
                $requested[$key] = BigDecimal::of((string) ($requested[$key] ?? '0'))->plus((string) $l['quantity']);
            }
            $lockedLines = [];
            $lineIds = array_keys($requested);
            sort($lineIds, SORT_NUMERIC);
            foreach ($lineIds as $lineId) {
                $line = IssueLine::query()->where('document_id', $issue->id)->lockForUpdate()->find($lineId);
                if (! $line) {
                    throw ValidationException::withMessages(['lines' => 'รายการรับคืนต้องอยู่ในใบเบิกต้นทาง']);
                }
                $lockedLines[$lineId] = $line;
                $alreadyReturned = BigDecimal::of((string) IssueReturnLine::query()
                    ->where('issue_line_id', $line->id)
                    ->whereHas('return', fn ($q) => $q->whereIn('status', ['APPROVED', 'POSTED']))
                    ->sum('quantity'));
                if ($alreadyReturned->plus($requested[$lineId])->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                    throw ValidationException::withMessages(['lines' => 'จำนวนรับคืนรวมเกินจำนวนที่เบิกไปแล้ว']);
                }
            }
            $s = $this->sequence($w, 'INVENTORY_RETURN');
            $date = Carbon::parse((string) $v['document_date']);
            $n = $seq->issueAvailableForBranch($s, $this->branch($w), $date, fn (string $number): bool => IssueReturn::withTrashed()->where('document_number', $number)->exists());
            $d = IssueReturn::create(['warehouse_id' => $w->id, 'document_number' => $n, 'document_date' => $date->toDateString(), 'issue_document_id' => $issue->id, 'reason' => $v['reason'], 'idempotency_key' => 'issue-return:'.bin2hex(random_bytes(12)), 'created_by' => $u->id]);
            $seq->recordIssued($s->fresh(), $n, 'wms_issue_returns', $d->id, $date, $u->id);
            foreach ($v['lines'] as $i => $l) {
                $line = $lockedLines[(int) $l['issue_line_id']] ?? null;
                if (! $line) {
                    throw ValidationException::withMessages(['lines.'.$i.'.issue_line_id' => 'รายการไม่อยู่ในใบเบิกนี้']);
                }
                $sourceLayers = CostAllocation::query()->where('stock_movement_id', $line->stock_movement_id)->where('direction', 'OUT')->where('status', '!=', 'REVERSED')->where('cost_status', '!=', 'PENDING')->orderBy('id')->lockForUpdate()->get();
                if ($sourceLayers->isEmpty()) {
                    throw ValidationException::withMessages(['lines.'.$i.'.quantity' => 'ไม่พบ cost lineage ของใบเบิกต้นทาง จึงรับคืนอย่างปลอดภัยไม่ได้']);
                }
                $usedByLayer = IssueReturnLineAllocation::query()->whereIn('source_allocation_id', $sourceLayers->modelKeys())->whereHas('returnLine.return', fn ($q) => $q->whereIn('status', ['APPROVED', 'POSTED']))->selectRaw('source_allocation_id, SUM(quantity) AS quantity')->groupBy('source_allocation_id')->pluck('quantity', 'source_allocation_id');
                $available = $sourceLayers->reduce(fn (BigDecimal $sum, CostAllocation $source): BigDecimal => $sum->plus(BigDecimal::of((string) $source->quantity)->minus((string) ($usedByLayer[$source->id] ?? '0'))), BigDecimal::zero());
                if ($requested[$line->id]->isGreaterThan($available)) {
                    throw ValidationException::withMessages(['lines.'.$i.'.quantity' => 'จำนวนรับคืนเกินจำนวนที่เบิกได้']);
                }
                $returnLine = IssueReturnLine::create(['return_id' => $d->id, 'issue_line_id' => $line->id, 'quantity' => $l['quantity'], 'line_number' => $i + 1]);
                $remaining = $requested[$line->id];
                foreach ($sourceLayers as $source) {
                    if ($remaining->isZero()) {
                        break;
                    }
                    $layerAvailable = BigDecimal::of((string) $source->quantity)->minus((string) ($usedByLayer[$source->id] ?? '0'));
                    $split = $remaining->isLessThan($layerAvailable) ? $remaining : $layerAvailable;
                    if ($split->isZero()) {
                        continue;
                    }
                    IssueReturnLineAllocation::create(['return_line_id' => $returnLine->id, 'source_allocation_id' => $source->id, 'quantity' => $split->__toString()]);
                    $remaining = $remaining->minus($split);
                }
                if (! $remaining->isZero()) {
                    throw ValidationException::withMessages(['lines.'.$i.'.quantity' => 'ไม่สามารถแบ่งคืนตาม cost layer ได้ครบถ้วน']);
                }
            }
            $audit->record('wms.issue_return.created', $d, [], $d->fresh()->load('lines')->toArray(), $u, $req);

            return $d;
        });
    }

    public function postReturn(IssueReturn $d, Warehouse $w, User $u, AuditLogger $audit, Request $r): IssueReturn
    {
        return DB::transaction(function () use ($d, $w, $u, $audit, $r) {
            $x = IssueReturn::with('lines.issueLine', 'lines.sourceAllocations.sourceAllocation')->lockForUpdate()->findOrFail($d->id);
            if ($x->status === 'POSTED') {
                return $x;
            }
            if ($x->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'ลง Stock ได้เฉพาะเอกสารที่อนุมัติแล้ว']);
            }
            $this->assertReturnQuantitiesWithinIssued($x->load('lines'));
            foreach ($x->lines as $l) {
                $splits = $l->sourceAllocations->sortBy('source_allocation_id')->values();
                if ($splits->isEmpty()) {
                    throw ValidationException::withMessages(['allocation' => 'ไม่พบ cost lineage ของรายการรับคืน']);
                }
                foreach ($splits as $split) {
                    $src = $split->sourceAllocation;
                    if (! $src || $src->direction !== 'OUT' || $src->status === 'REVERSED' || $src->cost_status === 'PENDING') {
                        throw ValidationException::withMessages(['allocation' => 'cost lineage ของรายการรับคืนไม่สมบูรณ์หรือยังไม่ final']);
                    }
                    $m = app(StockMovementService::class)->recordIntent(['warehouse_id' => $w->id, 'item_id' => $l->issueLine->item_id, 'uom_id' => $l->issueLine->uom_id, 'movement_type' => 'ISSUE', 'direction' => 'IN', 'quantity' => (string) $split->quantity, 'base_quantity' => (string) $split->quantity, 'business_date' => $x->document_date->format('Y-m-d'), 'source_type' => 'ISSUE_RETURN', 'source_id' => (string) $x->id, 'source_reference' => $x->document_number, 'idempotency_key' => 'issue-return:'.$x->id.':line:'.$l->id.':source:'.$src->id, 'metadata' => ['unit_cost' => (string) $src->unit_cost, 'unit_cost_trusted' => true, 'reversal_parent_allocation_id' => $src->id]]);
                    $m = app(StockMovementService::class)->post($m);
                    $a = CostAllocation::where('stock_movement_id', $m->id)->latest('id')->first();
                    $split->update(['stock_movement_id' => $m->id, 'cost_allocation_id' => $a?->id]);
                }
                $first = $l->sourceAllocations->sortBy('id')->first();
                $l->update(['stock_movement_id' => $first?->stock_movement_id, 'cost_allocation_id' => $first?->cost_allocation_id]);
            }$b = $x->toArray();
            $x->update(['status' => 'POSTED', 'posted_by' => $u->id]);
            $audit->record('wms.issue_return.posted', $x, $b, $x->fresh()->load('lines')->toArray(), $u, $r);

            return $x->fresh();
        }, 3);
    }

    private function sequence(Warehouse $w, string $type): DocumentSequence
    {
        $s = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $type)->where('is_active', true)->lockForUpdate()->first();
        if (! $s) {
            throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขที่เอกสาร']);
        }

        return $s;
    }

    private function branch(Warehouse $warehouse): Branch
    {
        $warehouse->loadMissing('branch');
        if (! $warehouse->branch) {
            throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขา']);
        }

        return $warehouse->branch;
    }

    private function assertReturnQuantitiesWithinIssued(IssueReturn $return): void
    {
        $totals = $return->lines()
            ->selectRaw('issue_line_id, SUM(quantity) AS quantity')
            ->groupBy('issue_line_id')
            ->pluck('quantity', 'issue_line_id');

        $lineIds = array_keys($totals->all());
        sort($lineIds, SORT_NUMERIC);
        foreach ($lineIds as $issueLineId) {
            $quantity = $totals[$issueLineId];
            $line = IssueLine::query()->lockForUpdate()->find($issueLineId);
            if (! $line || (int) $line->document_id !== (int) $return->issue_document_id) {
                throw ValidationException::withMessages(['lines' => 'รายการรับคืนไม่อยู่ในใบเบิกต้นทาง']);
            }

            $alreadyReturned = IssueReturnLine::query()
                ->where('issue_line_id', $line->id)
                ->where('return_id', '!=', $return->id)
                ->whereHas('return', fn ($q) => $q->whereIn('status', ['APPROVED', 'POSTED']))
                ->sum('quantity');
            if (BigDecimal::of((string) $alreadyReturned)->plus((string) $quantity)->isGreaterThan(BigDecimal::of((string) $line->quantity))) {
                throw ValidationException::withMessages(['lines' => 'จำนวนรับคืนรวมเกินจำนวนที่เบิกไปแล้ว']);
            }
        }
    }
}
