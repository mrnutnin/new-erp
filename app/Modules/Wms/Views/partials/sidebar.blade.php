@php($programCode = request()->attributes->get('selectedProgram')?->code)
@php($isPurchasing = $programCode === 'wms')
@php($isInventory = $programCode === 'inventory')
<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('wms.dashboard.view'))
        @php($dashboardRoute = $isInventory ? 'wms.index' : 'purchasing.index')
        <a class="list-group-item list-group-item-action {{ request()->routeIs($dashboardRoute) || request()->routeIs('wms.index') ? 'active' : '' }}" href="{{ route($dashboardRoute) }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
    @endif
    <a class="list-group-item list-group-item-action {{ request()->routeIs($isPurchasing ? 'purchasing.workflow.*' : 'wms.workflow.*') ? 'active' : '' }}" href="{{ route($isPurchasing ? 'purchasing.workflow.index' : 'wms.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือ{{ $isInventory ? ' WMS' : ' Purchasing' }}</a>
</div>

@if ($isPurchasing && auth()->user()->hasPermission('wms.suppliers.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักจัดซื้อ</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.suppliers.*') || request()->routeIs('wms.suppliers.*') ? 'active' : '' }}" href="{{ route('purchasing.suppliers.index') }}"><i class="bx bx-store me-2" aria-hidden="true"></i>Supplier</a>
    </div>
@endif

@if ($isPurchasing && (auth()->user()->hasPermission('wms.purchase-requisitions.view') || auth()->user()->hasPermission('wms.purchase-orders.view') || auth()->user()->hasPermission('wms.purchase-receipts.view') || auth()->user()->hasPermission('wms.purchase-documents.view')))
    <p class="eyebrow px-3 mb-2">ขั้นตอนจัดซื้อและเจ้าหนี้</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.purchase-requisitions.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-requisitions.*') || request()->routeIs('wms.purchase-requisitions.*') ? 'active' : '' }}" href="{{ route('purchasing.purchase-requisitions.index') }}"><i class="bx bx-file me-2" aria-hidden="true"></i>ใบขอซื้อ (PR)</a>@endif
        @if (auth()->user()->hasPermission('wms.purchase-orders.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-orders.*') || request()->routeIs('wms.purchase-orders.*') ? 'active' : '' }}" href="{{ route('purchasing.purchase-orders.index') }}"><i class="bx bx-file me-2" aria-hidden="true"></i>ใบสั่งซื้อ (PO)</a>@endif
        @if (auth()->user()->hasPermission('wms.purchase-receipts.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.purchase-receipts.*') || request()->routeIs('wms.purchase-receipts.*') ? 'active' : '' }}" href="{{ route('purchasing.purchase-receipts.index') }}"><i class="bx bx-package me-2" aria-hidden="true"></i>ตรวจรับสินค้า (GR)</a>@endif
        @if (auth()->user()->hasPermission('wms.purchase-documents.view'))
            <a class="list-group-item list-group-item-action {{ (request()->routeIs('purchasing.purchase-documents.*') || request()->routeIs('wms.purchase-documents.*')) && request('document_type','INVOICE') === 'INVOICE' ? 'active' : '' }}" href="{{ route('purchasing.purchase-documents.index', ['document_type' => 'INVOICE']) }}"><i class="bx bx-purchase-tag me-2" aria-hidden="true"></i>ใบตั้งหนี้ซื้อ</a>
            <a class="list-group-item list-group-item-action {{ (request()->routeIs('purchasing.purchase-documents.*') || request()->routeIs('wms.purchase-documents.*')) && request('document_type') === 'CREDIT_NOTE' ? 'active' : '' }}" href="{{ route('purchasing.purchase-documents.index', ['document_type' => 'CREDIT_NOTE']) }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>ใบลดหนี้ซื้อ</a>
        @endif
    </div>
@endif

@if ($isPurchasing && auth()->user()->hasPermission('purchasing.reports.view'))
    <p class="eyebrow px-3 mb-2">รายงานจัดซื้อ</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('purchasing.reports.*') ? 'active' : '' }}" href="{{ route('purchasing.reports.index') }}"><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงานปฏิบัติการจัดซื้อ</a>
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.transfers.view') || auth()->user()->hasPermission('wms.issues.view') || auth()->user()->hasPermission('wms.issue-returns.view') || auth()->user()->hasPermission('wms.inventory-adjustments.view') || auth()->user()->hasPermission('wms.stock-counts.view')))
    <p class="eyebrow px-3 mb-2">งานบริหารคลัง</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.transfers.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('wms.transfers.outgoing.*') ? 'active' : '' }}" href="{{ route('wms.transfers.outgoing.index') }}"><i class="bx bx-log-out me-2" aria-hidden="true"></i>โอนสินค้าออก</a>
            <a class="list-group-item list-group-item-action {{ request()->routeIs('wms.transfers.incoming.*') ? 'active' : '' }}" href="{{ route('wms.transfers.incoming.index') }}"><i class="bx bx-log-in me-2" aria-hidden="true"></i>โอนสินค้าเข้า</a>
        @endif
        @if (auth()->user()->hasPermission('wms.issues.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.issues.*') ? 'active' : '' }}" href="{{ route('wms.issues.index') }}"><i class="bx bx-log-out me-2" aria-hidden="true"></i>เบิกสินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.issue-returns.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.issue-returns.*') ? 'active' : '' }}" href="{{ route('wms.issue-returns.index') }}"><i class="bx bx-log-in me-2" aria-hidden="true"></i>รับคืนสินค้าจากการเบิก</a>@endif
        @if (auth()->user()->hasPermission('wms.inventory-adjustments.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.inventory-adjustments.*') ? 'active' : '' }}" href="{{ route('wms.inventory-adjustments.index') }}"><i class="bx bx-edit-alt me-2" aria-hidden="true"></i>ปรับปรุงสินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.stock-counts.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock-counts.*') ? 'active' : '' }}" href="{{ route('wms.stock-counts.index') }}"><i class="bx bx-clipboard me-2" aria-hidden="true"></i>ตรวจนับสินค้า</a>@endif
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.stock.view') || auth()->user()->hasPermission('wms.stock-valuation.view') || auth()->user()->hasPermission('wms.cost-allocation-reviews.view')))
    <p class="eyebrow px-3 mb-2">Stock และรายงาน</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.stock.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock.*') ? 'active' : '' }}" href="{{ route('wms.stock.index') }}"><i class="bx bx-line-chart me-2" aria-hidden="true"></i>Stock Card</a>@endif
        @if (auth()->user()->hasPermission('wms.stock-valuation.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock-valuation.*') ? 'active' : '' }}" href="{{ route('wms.stock-valuation.index') }}"><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>มูลค่าสินค้าคงเหลือ</a>@endif
        @if (auth()->user()->hasPermission('wms.cost-allocation-reviews.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.legacy-allocation-reviews.*') ? 'active' : '' }}" href="{{ route('wms.legacy-allocation-reviews.index') }}"><i class="bx bx-shield-quarter me-2" aria-hidden="true"></i>ตรวจสอบ Legacy Allocation</a>@endif
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.item-categories.view') || auth()->user()->hasPermission('wms.items.view') || auth()->user()->hasPermission('wms.uoms.view') || auth()->user()->hasPermission('wms.uom-conversions.view')))
    <p class="eyebrow px-3 mb-2">ข้อมูลสินค้า</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.item-categories.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.item-categories.*') ? 'active' : '' }}" href="{{ route('wms.item-categories.index') }}"><i class="bx bx-category me-2" aria-hidden="true"></i>หมวดสินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.items.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.items.*') ? 'active' : '' }}" href="{{ route('wms.items.index') }}"><i class="bx bx-package me-2" aria-hidden="true"></i>สินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.uoms.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.uoms.*') ? 'active' : '' }}" href="{{ route('wms.uoms.index') }}"><i class="bx bx-ruler me-2" aria-hidden="true"></i>หน่วยนับ</a>@endif
        @if (auth()->user()->hasPermission('wms.uom-conversions.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.uom-conversions.*') ? 'active' : '' }}" href="{{ route('wms.uom-conversions.index') }}"><i class="bx bx-transfer me-2" aria-hidden="true"></i>แปลงหน่วย</a>@endif
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.stock-policies.view') || auth()->user()->hasPermission('wms.issue-types.view')))
    <p class="eyebrow px-3 mb-2">ตั้งค่า</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.stock-policies.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock-policies.*') ? 'active' : '' }}" href="{{ route('wms.stock-policies.index') }}"><i class="bx bx-slider-alt me-2" aria-hidden="true"></i>Min Max Stock</a>@endif
        @if (auth()->user()->hasPermission('wms.issue-types.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.issue-types.*') ? 'active' : '' }}" href="{{ route('wms.issue-types.index') }}"><i class="bx bx-list-check me-2" aria-hidden="true"></i>ประเภทการเบิก</a>@endif
    </div>
@endif
