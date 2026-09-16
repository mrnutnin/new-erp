<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use App\Modules\Settings\Services\GlobalSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class OperationalReportController extends Controller
{
    public function index(Request $request): View
    {
        return view('Purchasing::reports.index', [
            'dateFrom' => $request->date('date_from')?->toDateString() ?? today()->startOfMonth()->toDateString(),
            'dateTo' => $request->date('date_to')?->toDateString() ?? today()->toDateString(),
            'reports' => [
                [
                    'title' => 'รายงานใบขอซื้อ (PR)',
                    'description' => 'ติดตามคำขอซื้อ สถานะอนุมัติ และการนำไปสร้างใบสั่งซื้อ',
                    'route' => 'purchasing.purchase-requisitions.index',
                    'permission' => 'purchasing.purchase-requisitions.view',
                    'icon' => 'bx-file',
                ],
                [
                    'title' => 'รายงานใบสั่งซื้อ (PO)',
                    'description' => 'ตรวจสอบ Supplier ยอดสั่งซื้อ และสถานะการรับสินค้า',
                    'route' => 'purchasing.purchase-orders.index',
                    'permission' => 'purchasing.purchase-orders.view',
                    'icon' => 'bx-receipt',
                ],
                [
                    'title' => 'รายงานตรวจรับสินค้า (GR)',
                    'description' => 'ติดตามสินค้าที่รับเข้าแล้วและเอกสารต้นทางที่เชื่อมโยง',
                    'route' => 'purchasing.purchase-receipts.index',
                    'permission' => 'purchasing.purchase-receipts.view',
                    'icon' => 'bx-package',
                ],
                [
                    'title' => 'รายงานใบตั้งหนี้/ใบลดหนี้ซื้อ',
                    'description' => 'ตรวจสอบยอดเจ้าหนี้ สถานะอนุมัติ และการ Post เข้าบัญชี',
                    'route' => 'purchasing.purchase-documents.index',
                    'permission' => 'purchasing.purchase-documents.view',
                    'icon' => 'bx-purchase-tag',
                    'query' => ['document_type' => 'INVOICE'],
                ],
            ],
        ]);
    }

    public function pdf(Request $request, DocumentPdfRenderer $renderer, GlobalSettings $settings): Response
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $dateFrom = CarbonImmutable::parse($filters['date_from']);
        $dateTo = CarbonImmutable::parse($filters['date_to']);
        $branch = $request->attributes->get('selectedBranch');
        $warehouses = $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $branch->id)->orderBy('code')->get(['warehouses.id', 'code', 'name']);
        $warehouseIds = $warehouses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $summarize = function (string $model, string $dateColumn, ?string $amountColumn = null, ?string $documentType = null) use ($warehouseIds, $dateFrom, $dateTo) {
            return $model::query()->whereIn('warehouse_id', $warehouseIds)
                ->whereDate($dateColumn, '>=', $dateFrom->toDateString())
                ->whereDate($dateColumn, '<=', $dateTo->toDateString())
                ->when($documentType, fn (Builder $query) => $query->where('document_type', $documentType))
                ->select('status')->selectRaw('COUNT(*) AS document_count')
                ->when($amountColumn, fn (Builder $query) => $query->selectRaw("COALESCE(SUM({$amountColumn}), 0) AS total_amount"))
                ->groupBy('status')->orderBy('status')->get();
        };
        $sections = collect([
            ['title' => 'ใบขอซื้อ (PR)', 'rows' => $summarize(PurchaseRequisition::class, 'document_date')],
            ['title' => 'ใบสั่งซื้อ (PO)', 'rows' => $summarize(PurchaseOrder::class, 'document_date', 'total_amount')],
            ['title' => 'ใบรับสินค้า (GR)', 'rows' => $summarize(GoodsReceipt::class, 'business_date')],
            ['title' => 'ใบตั้งหนี้ซื้อ', 'rows' => $summarize(PurchaseDocument::class, 'document_date', 'gross_amount', 'INVOICE')],
            ['title' => 'ใบลดหนี้ซื้อ', 'rows' => $summarize(PurchaseDocument::class, 'document_date', 'gross_amount', 'CREDIT_NOTE')],
            ['title' => 'Landed Cost', 'rows' => $summarize(LandedCost::class, 'business_date', 'total_amount')],
        ]);
        $bytes = $renderer->renderView('Purchasing::pdf.operational-report', [
            'sections' => $sections,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'generatedAt' => now(),
            'generatedBy' => $request->user()->name,
            'warehouses' => $warehouses,
            'logo' => $settings->logoDataUri(),
            'companyName' => (string) ($settings->value('company_name') ?: config('app.name')),
            'companyAddress' => (string) ($branch->tax_address ?: $settings->value('company_address')),
            'companyTaxId' => (string) ($settings->value('tax_id') ?: ''),
            'companyTaxBranchCode' => $branch->tax_branch_code,
            'branchName' => $branch->name,
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="purchasing-operations-'.$dateFrom->format('Ymd').'-'.$dateTo->format('Ymd').'.pdf"',
        ]);
    }
}
