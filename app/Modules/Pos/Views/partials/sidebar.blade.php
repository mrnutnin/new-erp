<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('pos.dashboard.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('pos.index') ? 'active' : '' }}" href="{{ route('pos.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
    @endif
    <a class="list-group-item list-group-item-action {{ request()->routeIs('pos.workflow.*') ? 'active' : '' }}" href="{{ route('pos.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน</a>
</div>

@if (auth()->user()->hasPermission('pos.sales-intakes.view') || auth()->user()->hasPermission('pos.sales-rfqs.view') || auth()->user()->hasPermission('pos.sales-quotations.view') || auth()->user()->hasPermission('pos.sales-orders.view') || auth()->user()->hasPermission('pos.sales-documents.view') || auth()->user()->hasPermission('pos.physical-sales.view') || auth()->user()->hasPermission('pos.receipts.view') || auth()->user()->hasPermission('pos.receivables.view') || auth()->user()->hasPermission('pos.advance-deposits.view') || auth()->user()->hasPermission('pos.sales-returns.view') || auth()->user()->hasPermission('pos.sales-reports.view'))
    <p class="eyebrow px-3 mb-2">งานขาย</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('pos.sales-intakes.view') || auth()->user()->hasPermission('pos.sales-rfqs.view') || auth()->user()->hasPermission('pos.sales-quotations.view') || auth()->user()->hasPermission('pos.sales-orders.view') || auth()->user()->hasPermission('pos.physical-sales.view'))
            @php($salesDocumentsActive = request()->routeIs('pos.sales-intakes.*', 'pos.sales-rfqs.*', 'pos.sales-quotations.*', 'pos.sales-orders.*', 'pos.physical-sales.*'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $salesDocumentsActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#pos-sales-document-menu" aria-expanded="{{ $salesDocumentsActive ? 'true' : 'false' }}" aria-controls="pos-sales-document-menu"><span><i class="bx bx-file me-2" aria-hidden="true"></i>เอกสารขาย</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="pos-sales-document-menu" class="collapse {{ $salesDocumentsActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('pos.sales-intakes.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-intakes.*') ? 'active' : '' }}" href="{{ route('pos.sales-intakes.index') }}">ใบรับข้อมูลเบื้องต้น</a>@endif
                @if (auth()->user()->hasPermission('pos.sales-rfqs.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-rfqs.*') ? 'active' : '' }}" href="{{ route('pos.sales-rfqs.index') }}">ใบขอราคา</a>@endif
                @if (auth()->user()->hasPermission('pos.sales-quotations.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-quotations.*') ? 'active' : '' }}" href="{{ route('pos.sales-quotations.index') }}">ใบเสนอราคา</a>@endif
                @if (auth()->user()->hasPermission('pos.sales-orders.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-orders.*') ? 'active' : '' }}" href="{{ route('pos.sales-orders.index') }}">ใบสั่งขาย</a>@endif
                @if (auth()->user()->hasPermission('pos.physical-sales.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.physical-sales.*') ? 'active' : '' }}" href="{{ route('pos.physical-sales.index') }}">ขายสด / ขายเชื่อ (HS/IV)</a>@endif
            </div>
        @endif
        @if (auth()->user()->hasPermission('pos.receipts.view') || auth()->user()->hasPermission('pos.receivables.view') || auth()->user()->hasPermission('pos.advance-deposits.view'))
            @php($receivablesActive = request()->routeIs('pos.receipts.*', 'pos.receivables.*', 'pos.advance-deposits.*'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $receivablesActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#pos-receivable-menu" aria-expanded="{{ $receivablesActive ? 'true' : 'false' }}" aria-controls="pos-receivable-menu"><span><i class="bx bx-money me-2" aria-hidden="true"></i>รับชำระและลูกหนี้</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="pos-receivable-menu" class="collapse {{ $receivablesActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('pos.receivables.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.receivables.index') ? 'active' : '' }}" href="{{ route('pos.receivables.index') }}">ลูกหนี้คงค้าง</a>@endif
                @if (auth()->user()->hasPermission('pos.receivables.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.receivables.aging.*') ? 'active' : '' }}" href="{{ route('pos.receivables.aging.index') }}">Aging ลูกหนี้</a>@endif
                @if (auth()->user()->hasPermission('pos.receipts.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.receipts.*') ? 'active' : '' }}" href="{{ route('pos.receipts.index') }}">รับชำระหนี้</a>@endif
                @if (auth()->user()->hasPermission('pos.advance-deposits.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.advance-deposits.*') ? 'active' : '' }}" href="{{ route('pos.advance-deposits.index') }}">ใบรับเงินล่วงหน้า</a>@endif
            </div>
        @endif
        @if (auth()->user()->hasPermission('pos.sales-returns.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('pos.sales-returns.*') ? 'active' : '' }}" href="{{ route('pos.sales-returns.index') }}"><i class="bx bx-undo me-2" aria-hidden="true"></i>ใบลดหนี้ / รับคืน</a>
        @endif
        @if (auth()->user()->hasPermission('pos.sales-documents.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('pos.sales-documents.*') ? 'active' : '' }}" href="{{ route('pos.sales-documents.index') }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>ใบแจ้งหนี้</a>
        @endif
        @if (auth()->user()->hasPermission('pos.sales-reports.view'))
            @php($reportsActive = request()->routeIs('pos.sales-reports.*'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $reportsActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#pos-report-menu" aria-expanded="{{ $reportsActive ? 'true' : 'false' }}" aria-controls="pos-report-menu">
                <span><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงาน</span>
                <i class="bx bx-chevron-down" aria-hidden="true"></i>
            </button>
            <div id="pos-report-menu" class="collapse {{ $reportsActive ? 'show' : '' }}">
                @if(auth()->user()->hasPermission('pos.sales-reports.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.daily.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.daily.index') }}">รายงานยอดขายรายวัน</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.customer.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.customer.index') }}">รายงานยอดขายตามลูกค้า</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.item.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.item.index') }}">รายงานยอดขายตามสินค้า</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.gross-profit.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.gross-profit.index') }}">รายงานกำไรขั้นต้น</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.promotion.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.promotion.index') }}">รายงานผล Promotion</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.campaign-roi.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.campaign-roi.index') }}">รายงาน Campaign ROI</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.sales-target-performance.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.sales-target-performance.index') }}">รายงานผลงานเทียบเป้าหมาย</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-reports.reconciliation.*') ? 'active' : '' }}" href="{{ route('pos.sales-reports.reconciliation.index') }}">กระทบยอดขาย–รับชำระ–ลูกหนี้</a>
                @endif
            </div>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('pos.customers.view') || auth()->user()->hasPermission('pos.customer-groups.view') || auth()->user()->hasPermission('pos.price-lists.view') || auth()->user()->hasPermission('pos.promotions.view') || auth()->user()->hasPermission('pos.commission-plans.view') || auth()->user()->hasPermission('pos.branch-sales-targets.view') || auth()->user()->hasPermission('pos.employee-sales-targets.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักการขาย</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('pos.customers.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('pos.customers.*') ? 'active' : '' }}" href="{{ route('pos.customers.index') }}"><i class="bx bx-user me-2" aria-hidden="true"></i>ลูกค้า</a>
        @endif
        @if (auth()->user()->hasPermission('pos.customer-groups.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('pos.customer-groups.*') ? 'active' : '' }}" href="{{ route('pos.customer-groups.index') }}"><i class="bx bx-group me-2" aria-hidden="true"></i>กลุ่มลูกค้า</a>
        @endif
        @if (auth()->user()->hasPermission('pos.price-lists.view') || auth()->user()->hasPermission('pos.promotions.view'))
            @php($pricingActive = request()->routeIs('pos.price-lists.*', 'pos.promotions.*'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $pricingActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#pos-pricing-menu" aria-expanded="{{ $pricingActive ? 'true' : 'false' }}" aria-controls="pos-pricing-menu"><span><i class="bx bx-purchase-tag me-2" aria-hidden="true"></i>ราคา &amp; โปรโมชั่น</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="pos-pricing-menu" class="collapse {{ $pricingActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('pos.price-lists.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.price-lists.*') ? 'active' : '' }}" href="{{ route('pos.price-lists.index') }}">รายการราคา</a>@endif
                @if (auth()->user()->hasPermission('pos.promotions.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.promotions.*') ? 'active' : '' }}" href="{{ route('pos.promotions.index') }}">โปรโมชั่น</a>@endif
            </div>
        @endif
        @if (auth()->user()->hasPermission('pos.sales-commissions.view') || auth()->user()->hasPermission('pos.commission-plans.view'))
            @php($commissionActive = request()->routeIs('pos.sales-commissions.*', 'pos.sales-commission-plans.*'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $commissionActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#pos-commission-menu" aria-expanded="{{ $commissionActive ? 'true' : 'false' }}" aria-controls="pos-commission-menu"><span><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>Commission</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="pos-commission-menu" class="collapse {{ $commissionActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('pos.sales-commissions.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-commissions.*') ? 'active' : '' }}" href="{{ route('pos.sales-commissions.index') }}">คอมมิชชั่นขาย</a>@endif
                @if (auth()->user()->hasPermission('pos.commission-plans.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.sales-commission-plans.*') ? 'active' : '' }}" href="{{ route('pos.sales-commission-plans.index') }}">ตั้งค่าคอมมิชชั่นขาย</a>@endif
            </div>
        @endif
        @if (auth()->user()->hasPermission('pos.branch-sales-targets.view') || auth()->user()->hasPermission('pos.employee-sales-targets.view'))
            @php($salesTargetActive = request()->routeIs('pos.branch-sales-targets.*', 'pos.employee-sales-targets.*'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $salesTargetActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#pos-sales-target-menu" aria-expanded="{{ $salesTargetActive ? 'true' : 'false' }}" aria-controls="pos-sales-target-menu"><span><i class="bx bx-target-lock me-2" aria-hidden="true"></i>เป้าหมายการขาย</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="pos-sales-target-menu" class="collapse {{ $salesTargetActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('pos.branch-sales-targets.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.branch-sales-targets.*') ? 'active' : '' }}" href="{{ route('pos.branch-sales-targets.index') }}">เป้าหมายสาขา</a>@endif
                @if (auth()->user()->hasPermission('pos.employee-sales-targets.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('pos.employee-sales-targets.*') ? 'active' : '' }}" href="{{ route('pos.employee-sales-targets.index') }}">เป้าหมายพนักงาน</a>@endif
            </div>
        @endif
    </div>
@endif
