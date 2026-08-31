<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.workflow.*') ? 'active' : '' }}" href="{{ route('finance.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน</a>
    @if (auth()->user()->hasPermission('finance.dashboard.view'))
        <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.index') ? 'active' : '' }}" href="{{ route('finance.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>
    @endif
</div>

@if (auth()->user()->hasPermission('finance.ar-open-items.view') || auth()->user()->hasPermission('finance.ar-aging.view'))
    <p class="eyebrow px-3 mb-2">ลูกหนี้ (AR)</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('finance.ar-open-items.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.receivables.open-items.*') ? 'active' : '' }}" href="{{ route('finance.receivables.open-items.index') }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>ลูกหนี้คงค้าง</a>
        @endif
        @if (auth()->user()->hasPermission('finance.ar-aging.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.receivables.aging.*') ? 'active' : '' }}" href="{{ route('finance.receivables.aging.index') }}"><i class="bx bx-time-five me-2" aria-hidden="true"></i>Aging ลูกหนี้</a>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.ap-open-items.view') || auth()->user()->hasPermission('finance.ap-aging.view'))
    <p class="eyebrow px-3 mb-2">เจ้าหนี้ (AP)</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('finance.ap-open-items.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.payables.open-items.*') ? 'active' : '' }}" href="{{ route('finance.payables.open-items.index') }}"><i class="bx bx-purchase-tag me-2" aria-hidden="true"></i>เจ้าหนี้คงค้าง</a>
        @endif
        @if (auth()->user()->hasPermission('finance.ap-aging.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.payables.aging.*') ? 'active' : '' }}" href="{{ route('finance.payables.aging.index') }}"><i class="bx bx-time-five me-2" aria-hidden="true"></i>Aging เจ้าหนี้</a>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.settlements.view') || auth()->user()->hasPermission('finance.payment-vouchers.view') || auth()->user()->hasPermission('finance.commission-payouts.view') || auth()->user()->hasPermission('finance.reports.payment-activity.view') || auth()->user()->hasPermission('finance.advance-deposits.view'))
    <p class="eyebrow px-3 mb-2">ธุรกรรมการเงิน</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.settlements.*') ? 'active' : '' }}" href="{{ route('finance.settlements.index') }}"><i class="bx bx-transfer me-2" aria-hidden="true"></i>รับเงิน / จ่ายเงิน</a>
        @if (auth()->user()->hasPermission('finance.payment-vouchers.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.payment-vouchers.*') ? 'active' : '' }}" href="{{ route('finance.payment-vouchers.index') }}"><i class="bx bx-file me-2" aria-hidden="true"></i>ใบขอจ่าย / ใบสำคัญจ่าย</a>
        @endif
        @if (auth()->user()->hasPermission('finance.commission-payouts.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.commission-payouts.*') ? 'active' : '' }}" href="{{ route('finance.commission-payouts.index') }}"><i class="bx bx-money-withdraw me-2" aria-hidden="true"></i>ชุดจ่ายคอมมิชชั่น</a>
        @endif
        @if (auth()->user()->hasPermission('finance.reports.payment-activity.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.reports.payment-activity.*') ? 'active' : '' }}" href="{{ route('finance.reports.payment-activity.index') }}"><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงานธุรกรรมการเงิน</a>
        @endif
        @if (auth()->user()->hasPermission('finance.advance-deposits.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.advance-deposits.*') ? 'active' : '' }}" href="{{ route('finance.advance-deposits.index') }}"><i class="bx bx-wallet me-2" aria-hidden="true"></i>เงินล่วงหน้า / เงินมัดจำ</a>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.bank-accounts.view') || auth()->user()->hasPermission('finance.other-categories.view') || auth()->user()->hasPermission('finance.payment-terms.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักการเงิน</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('finance.bank-accounts.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.bank-accounts.*') ? 'active' : '' }}" href="{{ route('finance.bank-accounts.index') }}"><i class="bx bx-bank me-2" aria-hidden="true"></i>บัญชีเงินสด/ธนาคาร</a>
        @endif
        @if (auth()->user()->hasPermission('finance.other-categories.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.other-categories.*') ? 'active' : '' }}" href="{{ route('finance.other-categories.index') }}"><i class="bx bx-category-alt me-2" aria-hidden="true"></i>รายได้/รายจ่ายอื่น</a>
        @endif
        @if (auth()->user()->hasPermission('finance.payment-terms.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.payment-terms.*') ? 'active' : '' }}" href="{{ route('finance.payment-terms.index') }}"><i class="bx bx-calendar-check me-2" aria-hidden="true"></i>เงื่อนไขการชำระเงิน</a>
        @endif
    </div>
@endif
