<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Models\SalesQuotation;
use App\Modules\Pos\Models\SalesRfq;
use App\Modules\Pos\Requests\ChangeSalesQuotationStatusRequest;
use App\Modules\Pos\Support\SalesDocumentTrail;
use App\Modules\Pos\Support\SalesQuotationState;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SalesQuotationController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-quotations.index');
    }

    public function data(Request $request): JsonResponse
    {
        $query = SalesQuotation::query()
            ->where('branch_id', $this->branchId($request))
            ->withCount('lines')
            ->with('order:id,sales_quotation_id,document_number')
            ->orderByDesc('document_date')->orderByDesc('id');

        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('document_date', $operator, $request->input($field));
            }
        }
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

        return DataTables::eloquent($query)
            ->filter(function ($q) use ($request): void {
                if ($term = trim((string) $request->input('search.value'))) {
                    $q->where(function ($x) use ($term): void {
                        $x->where('document_number', 'like', "%{$term}%")
                            ->orWhere('party_code', 'like', "%{$term}%")
                            ->orWhere('party_name', 'like', "%{$term}%");
                    });
                }
            })
            ->addColumn('party_label', fn (SalesQuotation $row) => trim($row->party_code.' · '.$row->party_name, ' ·'))
            ->addColumn('status_label', fn (SalesQuotation $row) => ['DRAFT' => 'ร่าง', 'SENT' => 'ส่งแล้ว', 'ACCEPTED' => 'ตอบรับแล้ว', 'REJECTED' => 'ปฏิเสธ', 'CANCELLED' => 'ยกเลิก'][$row->status] ?? $row->status)
            ->addColumn('show_url', fn (SalesQuotation $row) => route('pos.sales-quotations.show', $row))
            ->addColumn('pdf_url', fn (SalesQuotation $row) => route('pos.sales-quotations.pdf', $row))
            ->addColumn('order_url', fn (SalesQuotation $row) => $row->order ? route('pos.sales-orders.show', $row->order) : null)
            ->toJson();
    }

    public function edit(Request $request, SalesQuotation $salesQuotation): View
    {
        $this->scope($request, $salesQuotation);
        abort(403, 'ไม่อนุญาตให้แก้ไขราคาในใบเสนอราคา กรุณาแก้ไขที่ใบรับข้อมูล');
    }

    public function update(Request $request, SalesQuotation $salesQuotation): JsonResponse|RedirectResponse
    {
        $this->scope($request, $salesQuotation);
        abort(403, 'ไม่อนุญาตให้แก้ไขราคาในใบเสนอราคา กรุณาแก้ไขที่ใบรับข้อมูล');
    }

    public function show(Request $request, SalesQuotation $salesQuotation): View
    {
        $quotation = $this->scope($request, $salesQuotation)->load(['lines', 'rfq.sourceIntake.preparedBy', 'sourceIntake.preparedBy', 'party', 'order.physicalSales']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $quotation->getMorphClass())->where('subject_id', $quotation->id)->latest()->get();

        return view('Pos::sales-quotations.show', ['quotation' => $quotation, 'history' => $history, 'flowDocuments' => SalesDocumentTrail::for($quotation)]);
    }

    public function fromRfq(Request $request, SalesRfq $salesRfq, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $rfq = $this->scope($request, $salesRfq);
        $quotation = DB::transaction(function () use ($request, $rfq, $sequences, $audit) {
            $rfq = SalesRfq::query()->with('lines')->lockForUpdate()->findOrFail($rfq->id);
            SalesQuotationState::assertCreateFromRfq($rfq->status);
            if (SalesQuotation::query()->where('sales_rfq_id', $rfq->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['sales_rfq_id' => 'RFQ นี้มีใบเสนอราคาแล้ว']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_QUOTATION', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบเสนอราคา']);
            }
            $date = $rfq->document_date;
            $quotation = SalesQuotation::query()->create([
                'warehouse_id' => $rfq->warehouse_id, 'sales_rfq_id' => $rfq->id, 'party_id' => $rfq->party_id,
                'document_number' => $sequences->issueAvailableForBranch($sequence, $request->attributes->get('selectedBranch'), $date, fn (string $number): bool => SalesQuotation::query()
                    ->where('document_number', $number)->exists()), 'party_code' => $rfq->party_code,
                'party_name' => $rfq->party_name, 'party_tax_id' => $rfq->party_tax_id, 'party_branch_code' => $rfq->party_branch_code,
                'party_address' => $rfq->party_address, 'document_date' => $date, 'valid_until' => $rfq->valid_until,
                'status' => 'DRAFT', 'subtotal' => $rfq->subtotal, 'discount_amount' => $rfq->discount_amount,
                'promotion_snapshot' => $rfq->promotion_snapshot, 'promotion_discount_amount' => $rfq->promotion_discount_amount,
                'total_amount' => $rfq->total_amount,
                'description' => $rfq->description, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $sequences->recordIssued($sequence, $quotation->document_number, 'sales_quotations', $quotation->id, $date, $request->user()->id);
            foreach ($rfq->lines as $line) {
                $quotation->lines()->create(['source_rfq_line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_price' => $line->proposed_unit_price, 'discount_amount' => $line->proposed_discount_amount, 'promotion_discount_amount' => $line->promotion_discount_amount, 'line_total' => $line->line_total, 'pricing_snapshot' => $line->pricing_snapshot, 'item_snapshot' => $line->item_snapshot, 'uom_snapshot' => $line->uom_snapshot]);
            }
            $audit->record('pos.sales-quotation.created', $quotation, [], $quotation->fresh()->toArray(), $request->user(), $request);

            return $quotation;
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'redirect' => route('pos.sales-quotations.show', $quotation)]);
        }

        return redirect()->route('pos.sales-quotations.show', $quotation)->with('success', 'สร้างใบเสนอราคาจากใบขอราคาแล้ว');
    }

    public function fromIntake(Request $request, SalesIntake $salesIntake, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        abort_unless((int) $salesIntake->branch_id === $this->branchId($request), 404);
        $quotation = DB::transaction(function () use ($request, $salesIntake, $sequences, $audit) {
            $intake = SalesIntake::query()->with(['lines', 'quotation', 'order'])->lockForUpdate()->findOrFail($salesIntake->id);
            abort_unless(! $intake->requires_rfq && in_array($intake->status, ['DRAFT', 'COMPLETED'], true), 422);
            if ($intake->quotation && $intake->quotation->status !== 'CANCELLED') {
                return $intake->quotation;
            }
            if ($intake->order) {
                throw ValidationException::withMessages(['sales_intake_id' => 'ใบรับข้อมูลนี้มีใบสั่งขายแล้ว']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_QUOTATION', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบเสนอราคา']);
            }
            if ($intake->status === 'DRAFT') {
                $before = $intake->toArray();
                $intake->update(['status' => 'COMPLETED']);
                $audit->record('pos.sales-intake.completed', $intake, $before, $intake->toArray(), $request->user(), $request);
            }
            $quotation = SalesQuotation::query()->create([
                'warehouse_id' => $intake->warehouse_id, 'source_sales_intake_id' => $intake->id, 'party_id' => $intake->party_id,
                'document_number' => $sequences->issueAvailableForBranch($sequence, $request->attributes->get('selectedBranch'), $intake->document_date, fn (string $number): bool => SalesQuotation::query()
                    ->where('document_number', $number)->exists()), 'party_code' => $intake->party_code, 'party_name' => $intake->party_name,
                'party_tax_id' => $intake->party_tax_id, 'party_branch_code' => $intake->party_branch_code, 'party_address' => $intake->billing_address ?: $intake->party_address,
                'document_date' => $intake->document_date, 'valid_until' => $intake->document_date->copy()->addDays(30), 'status' => 'DRAFT',
                'subtotal' => $intake->subtotal, 'discount_amount' => $intake->discount_amount,
                'promotion_snapshot' => $intake->promotion_snapshot, 'promotion_discount_amount' => $intake->promotion_discount_amount,
                'total_amount' => $intake->grand_total,
                'description' => $intake->description, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $sequences->recordIssued($sequence, $quotation->document_number, 'sales_quotations', $quotation->id, $intake->document_date, $request->user()->id);
            foreach ($intake->lines as $line) {
                $quotation->lines()->create(['line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'description' => $this->lineDescription($line), 'quantity' => $line->quantity, 'unit_price' => $line->requested_unit_price ?? $line->standard_unit_price ?? '0', 'discount_amount' => $line->discount_amount, 'promotion_discount_amount' => $line->promotion_discount_amount, 'line_total' => $line->line_total, 'pricing_snapshot' => $line->pricing_snapshot, 'item_snapshot' => $line->item_snapshot, 'uom_snapshot' => $line->uom_snapshot]);
            }
            $audit->record('pos.sales-quotation.created', $quotation, [], $quotation->fresh()->toArray(), $request->user(), $request);

            return $quotation;
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'redirect' => route('pos.sales-quotations.show', $quotation)]);
        }

        return redirect()->route('pos.sales-quotations.show', $quotation)->with('success', 'สร้างใบเสนอราคาจากใบรับข้อมูลแล้ว');
    }

    public function send(ChangeSalesQuotationStatusRequest $request, SalesQuotation $salesQuotation, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesQuotation, $audit, 'send');

        return response()->json(['status' => true, 'msg' => 'ส่งใบเสนอราคาแล้ว']);
    }

    public function accept(ChangeSalesQuotationStatusRequest $request, SalesQuotation $salesQuotation, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesQuotation, $audit, 'accept');

        return response()->json(['status' => true, 'msg' => 'ตอบรับใบเสนอราคาแล้ว']);
    }

    public function reject(ChangeSalesQuotationStatusRequest $request, SalesQuotation $salesQuotation, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesQuotation, $audit, 'reject');

        return response()->json(['status' => true, 'msg' => 'ปฏิเสธใบเสนอราคาแล้ว']);
    }

    public function cancel(ChangeSalesQuotationStatusRequest $request, SalesQuotation $salesQuotation, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesQuotation, $audit, 'cancel');

        return response()->json(['status' => true, 'msg' => 'ยกเลิกใบเสนอราคาแล้ว']);
    }

    private function transition(ChangeSalesQuotationStatusRequest $request, SalesQuotation $salesQuotation, AuditLogger $audit, string $action): void
    {
        DB::transaction(function () use ($request, $salesQuotation, $audit, $action): void {
            $quotation = SalesQuotation::query()->whereKey($this->scope($request, $salesQuotation)->id)->lockForUpdate()->firstOrFail();
            $quotation->load('order');
            if ($quotation->order && $quotation->order->status !== 'CANCELLED') {
                throw ValidationException::withMessages(['status' => 'ใบเสนอราคานี้ถูกสร้างใบสั่งขายแล้ว ไม่สามารถเปลี่ยนสถานะได้']);
            }
            try {
                $status = $action === 'cancel'
                    ? SalesQuotationState::cancel($quotation->status, ! $quotation->order || $quotation->order->status === 'CANCELLED')
                    : SalesQuotationState::{$action}($quotation->status);
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }
            $reason = $request->validated('reason');
            $before = $quotation->only(['status', 'sent_by', 'sent_at', 'accepted_by', 'accepted_at', 'rejected_by', 'rejected_at', 'reject_reason', 'cancelled_by', 'cancelled_at', 'cancel_reason']);
            $values = ['status' => $status, 'updated_by' => $request->user()->id];
            if ($action === 'send') {
                $values += ['sent_by' => $request->user()->id, 'sent_at' => now()];
            } elseif ($action === 'accept') {
                $values += ['accepted_by' => $request->user()->id, 'accepted_at' => now()];
            } elseif ($action === 'reject') {
                $values += ['rejected_by' => $request->user()->id, 'rejected_at' => now(), 'reject_reason' => $reason];
            } else {
                $values += ['cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancel_reason' => $reason];
            }
            $quotation->update($values);
            $audit->record("pos.sales-quotation.{$action}", $quotation, $before, $quotation->only(array_keys($before)), $request->user(), $request);
        });
    }

    private function scope(Request $request, SalesRfq|SalesQuotation $model): SalesRfq|SalesQuotation
    {
        abort_unless((int) $model->branch_id === $this->branchId($request), 404);

        return $model;
    }

    private function warehouseId(Request $request): int
    {
        return (int) $request->attributes->get('selectedWarehouse')->id;
    }

    private function lineDescription(object $line): string
    {
        return trim((string) $line->description) ?: trim((string) data_get($line->item_snapshot, 'name')) ?: 'รายการสินค้า';
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }
}
