<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesQuotation;
use App\Modules\Pos\Models\SalesRfq;
use App\Modules\Pos\Requests\ChangeSalesOrderStatusRequest;
use App\Modules\Pos\Support\SalesDocumentTrail;
use App\Modules\Pos\Support\SalesOrderState;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SalesOrderController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-orders.index');
    }

    public function data(Request $request): JsonResponse
    {
        $query = SalesOrder::query()
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->withCount('lines')
            ->with(['quotation:id,document_number', 'rfq:id,document_number', 'physicalSales:id,source_id,document_type,document_number,status'])
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
            ->addColumn('party_label', fn (SalesOrder $row) => trim($row->party_code.' · '.$row->party_name, ' ·'))
            ->addColumn('status_label', fn (SalesOrder $row) => ['DRAFT' => 'ร่าง', 'CONFIRMED' => 'ยืนยันแล้ว', 'CANCELLED' => 'ยกเลิก', 'FULFILLED' => 'ดำเนินการแล้ว'][$row->status] ?? $row->status)
            ->addColumn('source_label', fn (SalesOrder $row) => $row->quotation?->document_number ?? $row->rfq?->document_number ?? '-')
            ->addColumn('physical_sale_status', fn (SalesOrder $row) => $row->physicalSales->contains(fn (PhysicalSale $sale) => $sale->status !== 'VOID') ? 'CREATED' : ($row->physicalSales->isNotEmpty() ? 'VOIDED' : 'NONE'))
            ->addColumn('physical_sale_label', fn (SalesOrder $row) => $row->physicalSales->where('status', '!=', 'VOID')->map(fn (PhysicalSale $sale) => "{$sale->document_type} · {$sale->document_number}")->implode(', ') ?: '—')
            ->addColumn('physical_sale_url', fn (SalesOrder $row) => ($sale = $row->physicalSales->first(fn (PhysicalSale $item) => $item->status !== 'VOID')) ? route('pos.physical-sales.show', $sale) : null)
            ->addColumn('physical_sale_create_url', fn (SalesOrder $row) => $row->status === 'CONFIRMED' && $row->physicalSales->every(fn (PhysicalSale $sale) => $sale->status === 'VOID') && $request->user()->hasPermission('pos.physical-sales.create') ? route('pos.physical-sales.create', ['sales_order_id' => $row->id]) : null)
            ->addColumn('show_url', fn (SalesOrder $row) => route('pos.sales-orders.show', $row))
            ->addColumn('pdf_url', fn (SalesOrder $row) => route('pos.sales-orders.pdf', $row))
            ->toJson();
    }

    public function fromQuotation(Request $request, SalesQuotation $salesQuotation, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->scope($request, $salesQuotation);
        $order = DB::transaction(function () use ($request, $salesQuotation, $sequences, $audit) {
            $quotation = SalesQuotation::query()->with('lines')->lockForUpdate()->findOrFail($salesQuotation->id);
            if ($quotation->status !== 'ACCEPTED') {
                throw ValidationException::withMessages(['quotation' => 'ใบเสนอราคาต้องตอบรับแล้วจึงสร้างใบสั่งขายได้']);
            }
            $existing = SalesOrder::query()->where('sales_quotation_id', $quotation->id)->first();
            if ($existing) {
                return $existing;
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_ORDER', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบสั่งขาย']);
            }
            $order = SalesOrder::query()->create([
                'warehouse_id' => $quotation->warehouse_id, 'sales_quotation_id' => $quotation->id, 'party_id' => $quotation->party_id,
                'document_number' => $this->issueOrderNumber($sequences, $sequence, $request, $quotation->document_date), 'party_code' => $quotation->party_code, 'party_name' => $quotation->party_name,
                'party_tax_id' => $quotation->party_tax_id, 'party_branch_code' => $quotation->party_branch_code, 'party_address' => $quotation->party_address,
                'document_date' => $quotation->document_date, 'valid_until' => $quotation->valid_until, 'status' => 'DRAFT', 'subtotal' => $quotation->subtotal,
                'discount_amount' => $quotation->discount_amount, 'promotion_snapshot' => $quotation->promotion_snapshot,
                'promotion_discount_amount' => $quotation->promotion_discount_amount, 'total_amount' => $quotation->total_amount, 'description' => $quotation->description,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $sequences->recordIssued($sequence, $order->document_number, 'sales_orders', $order->id, $quotation->document_date, $request->user()->id);
            foreach ($quotation->lines as $line) {
                $order->lines()->create(['source_quotation_line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'description' => $this->lineDescription($line), 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount, 'promotion_discount_amount' => $line->promotion_discount_amount, 'line_total' => $line->line_total, 'pricing_snapshot' => $line->pricing_snapshot, 'item_snapshot' => $line->item_snapshot, 'uom_snapshot' => $line->uom_snapshot]);
            }
            $audit->record('pos.sales-order.created', $order, [], $order->fresh()->toArray(), $request->user(), $request);

            return $order;
        });
        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'redirect' => route('pos.sales-orders.show', $order)]);
        }

        return redirect()->route('pos.sales-orders.show', $order)->with('success', 'สร้างใบสั่งขายแล้ว');
    }

    public function show(Request $request, SalesOrder $salesOrder): View
    {
        $this->scope($request, $salesOrder);
        $order = $salesOrder->load(['lines', 'quotation.sourceIntake.preparedBy', 'quotation.rfq.sourceIntake.preparedBy', 'rfq.sourceIntake.preparedBy', 'sourceIntake.preparedBy', 'party', 'physicalSales']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->latest()->get();

        return view('Pos::sales-orders.show', ['order' => $order, 'history' => $history, 'flowDocuments' => SalesDocumentTrail::for($order)]);
    }

    public function fromIntake(Request $request, SalesIntake $salesIntake, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        abort_unless((int) $salesIntake->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $order = DB::transaction(function () use ($request, $salesIntake, $sequences, $audit) {
            $intake = SalesIntake::query()->with(['lines', 'quotation', 'order'])->lockForUpdate()->findOrFail($salesIntake->id);
            abort_unless(! $intake->requires_rfq && in_array($intake->status, ['DRAFT', 'COMPLETED'], true), 422);
            if ($intake->order) {
                return $intake->order;
            }
            if ($intake->quotation) {
                throw ValidationException::withMessages(['sales_intake_id' => 'ใบรับข้อมูลนี้มีใบเสนอราคาแล้ว กรุณาสร้างใบสั่งขายจากใบเสนอราคา']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_ORDER', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบสั่งขาย']);
            }
            if ($intake->status === 'DRAFT') {
                $before = $intake->toArray();
                $intake->update(['status' => 'COMPLETED']);
                $audit->record('pos.sales-intake.completed', $intake, $before, $intake->toArray(), $request->user(), $request);
            }
            $order = SalesOrder::query()->create([
                'warehouse_id' => $intake->warehouse_id, 'source_sales_intake_id' => $intake->id, 'party_id' => $intake->party_id,
                'document_number' => $this->issueOrderNumber($sequences, $sequence, $request, $intake->document_date), 'party_code' => $intake->party_code, 'party_name' => $intake->party_name,
                'party_tax_id' => $intake->party_tax_id, 'party_branch_code' => $intake->party_branch_code, 'party_address' => $intake->billing_address ?: $intake->party_address,
                'document_date' => $intake->document_date, 'valid_until' => $intake->document_date->copy()->addDays(30), 'status' => 'DRAFT',
                'subtotal' => $intake->subtotal, 'discount_amount' => $intake->discount_amount,
                'promotion_snapshot' => $intake->promotion_snapshot, 'promotion_discount_amount' => $intake->promotion_discount_amount,
                'total_amount' => $intake->grand_total,
                'description' => $intake->description, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $sequences->recordIssued($sequence, $order->document_number, 'sales_orders', $order->id, $intake->document_date, $request->user()->id);
            foreach ($intake->lines as $line) {
                $order->lines()->create(['source_sales_intake_line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'description' => $this->lineDescription($line), 'quantity' => $line->quantity, 'unit_price' => $line->requested_unit_price ?? $line->standard_unit_price ?? '0', 'discount_amount' => $line->discount_amount, 'promotion_discount_amount' => $line->promotion_discount_amount, 'line_total' => $line->line_total, 'pricing_snapshot' => $line->pricing_snapshot, 'item_snapshot' => $line->item_snapshot, 'uom_snapshot' => $line->uom_snapshot]);
            }
            $audit->record('pos.sales-order.created', $order, [], $order->fresh()->toArray(), $request->user(), $request);

            return $order;
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'redirect' => route('pos.sales-orders.show', $order)]);
        }

        return redirect()->route('pos.sales-orders.show', $order)->with('success', 'สร้างใบสั่งขายจากใบรับข้อมูลแล้ว');
    }

    public function confirm(ChangeSalesOrderStatusRequest $request, SalesOrder $salesOrder, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesOrder, $audit, 'confirm');

        return response()->json(['status' => true, 'msg' => 'ยืนยันใบสั่งขายแล้ว พร้อมดำเนินการขายในขั้นตอนถัดไป']);
    }

    public function cancel(ChangeSalesOrderStatusRequest $request, SalesOrder $salesOrder, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $salesOrder, $audit, 'cancel');

        return response()->json(['status' => true, 'msg' => 'ยกเลิกใบสั่งขายแล้ว']);
    }

    public function fromRfq(Request $request, SalesRfq $salesRfq, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $this->scope($request, $salesRfq);
        $order = DB::transaction(function () use ($request, $salesRfq, $sequences, $audit) {
            $rfq = SalesRfq::query()->with(['lines', 'quotation'])->lockForUpdate()->findOrFail($salesRfq->id);
            if ($rfq->status !== 'APPROVED') {
                throw ValidationException::withMessages(['rfq' => 'RFQ ต้องอนุมัติแล้วจึงสร้างใบสั่งขายได้']);
            }
            if ($rfq->quotation) {
                throw ValidationException::withMessages(['rfq' => 'ใบขอราคานี้มีใบเสนอราคาแล้ว กรุณาสร้างใบสั่งขายจากใบเสนอราคา']);
            }
            if ($existing = SalesOrder::query()->where('sales_rfq_id', $rfq->id)->first()) {
                return $existing;
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SALES_ORDER', 'is_active' => true])->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_number' => 'ยังไม่ได้ตั้งค่าเลขเอกสารใบสั่งขาย']);
            }
            $order = SalesOrder::query()->create([
                'warehouse_id' => $rfq->warehouse_id, 'sales_rfq_id' => $rfq->id, 'party_id' => $rfq->party_id,
                'document_number' => $this->issueOrderNumber($sequences, $sequence, $request, $rfq->document_date), 'party_code' => $rfq->party_code, 'party_name' => $rfq->party_name,
                'party_tax_id' => $rfq->party_tax_id, 'party_branch_code' => $rfq->party_branch_code, 'party_address' => $rfq->party_address,
                'document_date' => $rfq->document_date, 'valid_until' => $rfq->valid_until, 'status' => 'DRAFT', 'subtotal' => $rfq->subtotal,
                'discount_amount' => $rfq->discount_amount, 'promotion_snapshot' => $rfq->promotion_snapshot,
                'promotion_discount_amount' => $rfq->promotion_discount_amount, 'total_amount' => $rfq->total_amount, 'description' => $rfq->description,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $sequences->recordIssued($sequence, $order->document_number, 'sales_orders', $order->id, $rfq->document_date, $request->user()->id);
            foreach ($rfq->lines as $line) {
                $order->lines()->create([
                    'source_rfq_line_id' => $line->id, 'line_number' => $line->line_number, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
                    'description' => $this->lineDescription($line), 'quantity' => $line->quantity, 'unit_price' => $line->proposed_unit_price, 'discount_amount' => $line->proposed_discount_amount,
                    'promotion_discount_amount' => $line->promotion_discount_amount, 'line_total' => $line->line_total, 'pricing_snapshot' => $line->pricing_snapshot,
                    'item_snapshot' => $line->item_snapshot, 'uom_snapshot' => $line->uom_snapshot,
                ]);
            }
            $audit->record('pos.sales-order.created', $order, [], $order->fresh()->toArray(), $request->user(), $request);

            return $order;
        });
        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'redirect' => route('pos.sales-orders.show', $order)]);
        }

        return redirect()->route('pos.sales-orders.show', $order)->with('success', 'สร้างใบสั่งขายจากใบขอราคาแล้ว กรุณากำหนดราคาในขั้นตอนถัดไป');
    }

    private function scope(Request $request, SalesQuotation|SalesRfq|SalesOrder $model): void
    {
        abort_unless((int) $model->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
    }

    private function lineDescription(object $line): string
    {
        return trim((string) $line->description) ?: trim((string) data_get($line->item_snapshot, 'name')) ?: 'รายการสินค้า';
    }

    private function issueOrderNumber(DocumentSequenceService $sequences, DocumentSequence $sequence, Request $request, $date): string
    {
        return $sequences->issueAvailableForBranch($sequence, $request->attributes->get('selectedBranch'), $date, fn (string $number): bool => SalesOrder::query()
            ->where('document_number', $number)->exists());
    }

    private function transition(ChangeSalesOrderStatusRequest $request, SalesOrder $salesOrder, AuditLogger $audit, string $action): void
    {
        DB::transaction(function () use ($request, $salesOrder, $audit, $action): void {
            $this->scope($request, $salesOrder);
            $order = SalesOrder::query()->withCount('lines')->lockForUpdate()->findOrFail($salesOrder->id);
            if ($action === 'confirm' && $order->lines_count === 0) {
                throw ValidationException::withMessages(['status' => 'ไม่สามารถยืนยันใบสั่งขายที่ไม่มีรายการได้']);
            }
            if ($action === 'cancel' && PhysicalSale::query()->where('warehouse_id', $order->warehouse_id)->where('source_type', 'SALES_ORDER')->where('source_id', $order->id)->where('status', '!=', 'VOID')->exists()) {
                throw ValidationException::withMessages(['status' => 'ใบสั่งขายนี้มีเอกสารขายปลายทางแล้ว ต้องยกเลิกเอกสารปลายทางก่อน']);
            }
            try {
                $status = SalesOrderState::{$action}($order->status);
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['status' => $e->getMessage()]);
            }

            $before = $order->only(['status', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason']);
            $values = ['status' => $status, 'updated_by' => $request->user()->id];
            if ($action === 'confirm') {
                $values += ['confirmed_by' => $request->user()->id, 'confirmed_at' => now()];
            } else {
                $values += ['cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancel_reason' => $request->validated('reason')];
            }
            $order->update($values);
            $audit->record("pos.sales-order.{$action}", $order, $before, $order->fresh()->only(array_keys($before)), $request->user(), $request);
            if ($action === 'cancel' && $order->source_sales_intake_id) {
                $intake = SalesIntake::query()->lockForUpdate()->find($order->source_sales_intake_id);
                if ($intake && $intake->status === 'COMPLETED') {
                    $beforeIntake = $intake->only(['status']);
                    $intake->update(['status' => 'DRAFT']);
                    $audit->record('pos.sales-intake.reopened', $intake, $beforeIntake, $intake->only(['status']), $request->user(), $request);
                }
            }
        });
    }
}
