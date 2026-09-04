@php($programCode = request()->attributes->get('selectedProgram')?->code)
@php($isPurchasing = $programCode === 'purchasing')
@php($isInventory = $programCode === 'wms')
<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('wms.dashboard.view'))
        @php($dashboardRoute = $isInventory ? 'wms.index' : 'purchasing.index')
        <a class="list-group-item list-group-item-action {{ request()->routeIs($dashboardRoute) || request()->routeIs('wms.index') ? 'active' : '' }}" href="{{ route($dashboardRoute) }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
    @endif
    <a class="list-group-item list-group-item-action {{ request()->routeIs($isPurchasing ? 'purchasing.workflow.*' : 'wms.workflow.*') ? 'active' : '' }}" href="{{ route($isPurchasing ? 'purchasing.workflow.index' : 'wms.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือ{{ $isInventory ? ' WMS' : ' Purchasing' }}</a>
</div>

@if ($isInventory && (auth()->user()->hasPermission('wms.transfers.view') || auth()->user()->hasPermission('wms.issues.view') || auth()->user()->hasPermission('wms.issue-returns.view') || auth()->user()->hasPermission('wms.inventory-adjustments.view') || auth()->user()->hasPermission('wms.stock-counts.view')))
    @php($transferActive = request()->routeIs('wms.transfers.*'))
    @php($issueActive = request()->routeIs('wms.issues.*') || request()->routeIs('wms.issue-returns.*'))
    @php($countActive = request()->routeIs('wms.stock-counts.*') || request()->routeIs('wms.inventory-adjustments.*'))
    <p class="eyebrow px-3 mb-2">การปฏิบัติงานคลัง</p>
    <div class="list-group mb-4">
            @if (auth()->user()->hasPermission('wms.transfers.view'))
                <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between ps-4 {{ $transferActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#wms-transfer-menu" aria-expanded="{{ $transferActive ? 'true' : 'false' }}" aria-controls="wms-transfer-menu"><span><i class="bx bx-transfer me-2" aria-hidden="true"></i>โอนสินค้าเข้า–ออก</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
                <div id="wms-transfer-menu" class="collapse {{ $transferActive ? 'show' : '' }}">
                    <a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('wms.transfers.outgoing.*') ? 'active' : '' }}" href="{{ route('wms.transfers.outgoing.index') }}">โอนสินค้าออก</a>
                    <a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('wms.transfers.incoming.*') ? 'active' : '' }}" href="{{ route('wms.transfers.incoming.index') }}">โอนสินค้าเข้า</a>
                </div>
            @endif
            @if (auth()->user()->hasPermission('wms.issues.view') || auth()->user()->hasPermission('wms.issue-returns.view'))
                <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between ps-4 {{ $issueActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#wms-issue-menu" aria-expanded="{{ $issueActive ? 'true' : 'false' }}" aria-controls="wms-issue-menu"><span><i class="bx bx-log-out me-2" aria-hidden="true"></i>เบิก–รับสินค้า</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
                <div id="wms-issue-menu" class="collapse {{ $issueActive ? 'show' : '' }}">
                    @if (auth()->user()->hasPermission('wms.issues.view'))<a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('wms.issues.*') ? 'active' : '' }}" href="{{ route('wms.issues.index') }}">เบิกสินค้า</a>@endif
                    @if (auth()->user()->hasPermission('wms.issue-returns.view'))<a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('wms.issue-returns.*') ? 'active' : '' }}" href="{{ route('wms.issue-returns.index') }}">รับคืนสินค้าจากการเบิก</a>@endif
                </div>
            @endif
            @if (auth()->user()->hasPermission('wms.stock-counts.view') || auth()->user()->hasPermission('wms.inventory-adjustments.view'))
                <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between ps-4 {{ $countActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#wms-count-menu" aria-expanded="{{ $countActive ? 'true' : 'false' }}" aria-controls="wms-count-menu"><span><i class="bx bx-clipboard me-2" aria-hidden="true"></i>นับ–ปรับปรุงสินค้า</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
                <div id="wms-count-menu" class="collapse {{ $countActive ? 'show' : '' }}">
                    @if (auth()->user()->hasPermission('wms.stock-counts.view'))<a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('wms.stock-counts.*') ? 'active' : '' }}" href="{{ route('wms.stock-counts.index') }}">ตรวจนับสินค้า</a>@endif
                    @if (auth()->user()->hasPermission('wms.inventory-adjustments.view'))<a class="list-group-item list-group-item-action ps-5 {{ request()->routeIs('wms.inventory-adjustments.*') ? 'active' : '' }}" href="{{ route('wms.inventory-adjustments.index') }}">ปรับปรุงสินค้า</a>@endif
                </div>
            @endif
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.opening-balances.view') || auth()->user()->hasPermission('wms.stock.view') || auth()->user()->hasPermission('wms.stock-valuation.view') || auth()->user()->hasPermission('wms.cost-allocation-reviews.view')))
    <p class="eyebrow px-3 mb-2">สต็อกและรายงาน</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.opening-balances.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.opening-balances.*') ? 'active' : '' }}" href="{{ route('wms.opening-balances.index') }}"><i class="bx bx-import me-2" aria-hidden="true"></i>ยอดยกมาสินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.stock.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock.*') ? 'active' : '' }}" href="{{ route('wms.stock.index') }}"><i class="bx bx-line-chart me-2" aria-hidden="true"></i>Stock Card</a>@endif
        @if (auth()->user()->hasPermission('wms.stock-valuation.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock-valuation.*') ? 'active' : '' }}" href="{{ route('wms.stock-valuation.index') }}"><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>มูลค่าสินค้าคงเหลือ</a>@endif
        @if (auth()->user()->hasPermission('wms.cost-allocation-reviews.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.legacy-allocation-reviews.*') ? 'active' : '' }}" href="{{ route('wms.legacy-allocation-reviews.index') }}"><i class="bx bx-shield-quarter me-2" aria-hidden="true"></i>ตรวจสอบ Legacy Allocation</a>@endif
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.item-categories.view') || auth()->user()->hasPermission('wms.items.view') || auth()->user()->hasPermission('wms.uoms.view') || auth()->user()->hasPermission('wms.uom-conversions.view')))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักสินค้า</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.item-categories.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.item-categories.*') ? 'active' : '' }}" href="{{ route('wms.item-categories.index') }}"><i class="bx bx-category me-2" aria-hidden="true"></i>หมวดสินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.items.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.items.*') ? 'active' : '' }}" href="{{ route('wms.items.index') }}"><i class="bx bx-package me-2" aria-hidden="true"></i>สินค้า</a>@endif
        @if (auth()->user()->hasPermission('wms.uoms.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.uoms.*') ? 'active' : '' }}" href="{{ route('wms.uoms.index') }}"><i class="bx bx-ruler me-2" aria-hidden="true"></i>หน่วยนับ</a>@endif
        @if (auth()->user()->hasPermission('wms.uom-conversions.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.uom-conversions.*') ? 'active' : '' }}" href="{{ route('wms.uom-conversions.index') }}"><i class="bx bx-transfer me-2" aria-hidden="true"></i>แปลงหน่วย</a>@endif
    </div>
@endif

@if ($isInventory && (auth()->user()->hasPermission('wms.stock-policies.view') || auth()->user()->hasPermission('wms.issue-types.view')))
    <p class="eyebrow px-3 mb-2">ตั้งค่าคลัง</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('wms.stock-policies.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.stock-policies.*') ? 'active' : '' }}" href="{{ route('wms.stock-policies.index') }}"><i class="bx bx-slider-alt me-2" aria-hidden="true"></i>Min Max Stock</a>@endif
        @if (auth()->user()->hasPermission('wms.issue-types.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('wms.issue-types.*') ? 'active' : '' }}" href="{{ route('wms.issue-types.index') }}"><i class="bx bx-list-check me-2" aria-hidden="true"></i>ประเภทการเบิก</a>@endif
    </div>
@endif
