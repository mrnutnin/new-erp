<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class OperationalReportController extends Controller
{
    public function index(): View
    {
        return view('Purchasing::reports.index', [
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
}
