<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Requests\SaveDocumentSequenceRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class DocumentSequenceController extends Controller
{
    public const DOCUMENT_TYPE_LABELS = [
        'RECEIPT' => 'รับเงิน',
        'PAYMENT' => 'จ่ายเงิน',
        'SALES_INVOICE' => 'ใบแจ้งหนี้ขาย',
        'SALES_CREDIT_NOTE' => 'ใบลดหนี้ขาย',
        'PURCHASE_INVOICE' => 'ใบแจ้งหนี้ซื้อ',
        'PURCHASE_CREDIT_NOTE' => 'ใบลดหนี้ซื้อ',
        'SALES_RFQ' => 'ใบขอราคาขาย',
        'SALES_INTAKE' => 'ใบรับข้อมูลเบื้องต้น',
        'SALES_QUOTATION' => 'ใบเสนอราคา',
        'SALES_ORDER' => 'ใบสั่งขาย',
        'PURCHASE_ORDER' => 'ใบสั่งซื้อ',
        'INVENTORY_ADJUSTMENT' => 'ปรับปรุงสินค้าคงเหลือ',
        'INVENTORY_ISSUE' => 'ใบเบิกสินค้า',
        'INVENTORY_RETURN' => 'ใบรับคืนจากการเบิก',
        'PHYSICAL_SALE_HS' => 'ใบขายสด/ใบกำกับภาษี',
        'PHYSICAL_SALE_IV' => 'ใบส่งสินค้า/ใบกำกับภาษี',
        'SALES_RETURN' => 'ใบรับคืนสินค้า',
        'CUSTOMER' => 'รหัสลูกค้า',
        'SUPPLIER' => 'รหัสผู้ขาย/คู่ค้า',
        'ADVANCE_DEPOSIT_AI' => 'ใบรับเงินล่วงหน้า',
        'PURCHASE_REQUISITION' => 'ใบขอซื้อ',
        'GOODS_RECEIPT' => 'ใบรับสินค้า',
        'WMS_TRANSFER' => 'ใบโอนสินค้า',
        'STOCK_COUNT' => 'ใบนับสินค้า',
        'LANDED_COST' => 'ต้นทุนแฝงสินค้า',
        'PETTY_CASH' => 'ใบสำคัญเงินสดย่อย',
        'PETTY_CASH_TOP_UP' => 'ใบเติมเงินสดย่อย',
        'PETTY_CASH_CLEARING' => 'ใบเคลียร์เงินสดย่อย',
        'EMPLOYEE_ADVANCE' => 'ใบเงินทดรองจ่ายพนักงาน',
        'EMPLOYEE_ADVANCE_CLEARING' => 'ใบเคลียร์เงินทดรองพนักงาน',
        'PURCHASE_RETURN' => 'ใบคืนซื้อ',
        'BILLING_NOTE' => 'ใบวางบิล',
        'INTERNAL_TRANSFER' => 'โอนเงินระหว่างบัญชี',
        'ASSET_REGISTER' => 'ทะเบียนสินทรัพย์',
        'ASSET_CAPITALIZATION' => 'ใบรับรู้สินทรัพย์',
        'ASSET_ADDITION' => 'ใบเพิ่มมูลค่าสินทรัพย์',
        'ASSET_TRANSFER' => 'ใบโอน/ย้ายสินทรัพย์',
        'ASSET_COUNT' => 'ใบตรวจนับสินทรัพย์',
        'ASSET_MAINTENANCE' => 'ใบแจ้งซ่อมสินทรัพย์',
        'ASSET_DEPRECIATION' => 'ชุดคำนวณค่าเสื่อม',
        'ASSET_IMPAIRMENT' => 'ใบบันทึกด้อยค่าสินทรัพย์',
        'ASSET_DISPOSAL' => 'ใบจำหน่าย/ตัดออก',
    ];

    public function index(): View
    {
        return view('Finance::document-sequences.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->sequencesQuery($request))
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request))
            ->addColumn('document_type_label', fn (DocumentSequence $sequence) => self::DOCUMENT_TYPE_LABELS[$sequence->document_type] ?? $sequence->document_type)
            ->addColumn('reset_rule_label', fn (DocumentSequence $sequence) => ['NEVER' => 'ไม่ reset', 'YEARLY' => 'รายปี', 'MONTHLY' => 'รายเดือน'][$sequence->reset_rule]);

        if ($request->user()->hasPermission('finance.document-sequences.update')) {
            $dataTable->addColumn('edit_url', fn (DocumentSequence $sequence) => route('settings.document-sequences.edit', $sequence));
        }

        return $dataTable->toJson();
    }

    public function edit(Request $request, DocumentSequence $documentSequence): View
    {
        return view('Finance::document-sequences.form', ['documentSequence' => $documentSequence]);
    }

    public function update(SaveDocumentSequenceRequest $request, DocumentSequence $documentSequence, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $documentSequence, $audit) {
            $sequence = DocumentSequence::query()->lockForUpdate()->findOrFail($documentSequence->id);
            $before = $this->auditValues($sequence);
            $sequence->fill($request->safe()->except(['next_number', 'document_type']));

            $sequence->save();
            $audit->record('finance.document_sequence.updated', $sequence, $before, $this->auditValues($sequence), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขรูปแบบเอกสารแล้ว']);
    }

    private function auditValues(DocumentSequence $sequence): array
    {
        return $sequence->only(['warehouse_id', 'document_type', 'name', 'prefix', 'number_format', 'reset_rule', 'next_number', 'last_reset_key', 'is_active', 'number_reuse_policy']);
    }

    private function sequencesQuery(Request $request): Builder
    {
        return DocumentSequence::query()
            ->whereNull('warehouse_id')
            ->select('finance_document_sequences.*');
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('document_type', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('prefix', 'like', "%{$search}%")
                ->orWhere('number_format', 'like', "%{$search}%")
                ->orWhere('reset_rule', 'like', "%{$search}%"));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'document_type',
            1 => 'name',
            2 => 'prefix',
            3 => 'number_format',
            4 => 'reset_rule',
            5 => 'next_number',
            6 => 'is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'document_type';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('id');
    }
}
