@php($programCode = request()->attributes->get('selectedProgram')?->code)
<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('purchasing.dashboard.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.index') ? 'active' : '' }}" href="{{ route('purchasing.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
    @endif
    <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.workflow.*') ? 'active' : '' }}" href="{{ route('purchasing.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือ Purchasing</a>
</div>

@if (auth()->user()->hasPermission('purchasing.suppliers.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักจัดซื้อ</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.suppliers.*') ? 'active' : '' }}" href="{{ route('purchasing.suppliers.index') }}"><i class="bx bx-store me-2" aria-hidden="true"></i>Supplier</a>
    </div>
@endif

@if (auth()->user()->hasPermission('purchasing.purchase-requisitions.view') || auth()->user()->hasPermission('purchasing.purchase-orders.view') || auth()->user()->hasPermission('purchasing.purchase-receipts.view') || auth()->user()->hasPermission('purchasing.purchase-documents.view') || auth()->user()->hasPermission('purchasing.landed-costs.view'))
    <p class="eyebrow px-3 mb-2">ขั้นตอนจัดซื้อและเจ้าหนี้</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('purchasing.purchase-requisitions.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-requisitions.*') ? 'active' : '' }}" href="{{ route('purchasing.purchase-requisitions.index') }}"><i class="bx bx-file me-2" aria-hidden="true"></i>ใบขอซื้อ (PR)</a>@endif
        @if (auth()->user()->hasPermission('purchasing.purchase-orders.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-orders.*') ? 'active' : '' }}" href="{{ route('purchasing.purchase-orders.index') }}"><i class="bx bx-file me-2" aria-hidden="true"></i>ใบสั่งซื้อ (PO)</a>@endif
        @if (auth()->user()->hasPermission('purchasing.purchase-receipts.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-receipts.*') ? 'active' : '' }}" href="{{ route('purchasing.purchase-receipts.index') }}"><i class="bx bx-package me-2" aria-hidden="true"></i>ตรวจรับสินค้า (GR)</a>@endif
        @if (auth()->user()->hasPermission('purchasing.purchase-documents.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-documents.*') && request('document_type','INVOICE') === 'INVOICE' ? 'active' : '' }}" href="{{ route('purchasing.purchase-documents.index', ['document_type' => 'INVOICE']) }}"><i class="bx bx-purchase-tag me-2" aria-hidden="true"></i>ใบตั้งหนี้ซื้อ</a>
            <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-documents.*') && request('document_type') === 'CREDIT_NOTE' ? 'active' : '' }}" href="{{ route('purchasing.purchase-documents.index', ['document_type' => 'CREDIT_NOTE']) }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>ใบลดหนี้ซื้อ</a>
        @endif
        @if (auth()->user()->hasPermission('purchasing.landed-costs.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.landed-costs.*') ? 'active' : '' }}" href="{{ route('purchasing.landed-costs.index') }}"><i class="bx bx-calculator me-2" aria-hidden="true"></i>Landed Cost</a>@endif
    </div>
@endif

@if (auth()->user()->hasPermission('purchasing.reports.view'))
    <p class="eyebrow px-3 mb-2">รายงานจัดซื้อ</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.reports.*') ? 'active' : '' }}" href="{{ route('purchasing.reports.index') }}"><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงานปฏิบัติการจัดซื้อ</a>
    </div>
@endif
