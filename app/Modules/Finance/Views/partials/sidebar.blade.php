<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม</a>
    @if (auth()->user()->hasPermission('finance.dashboard.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('finance.index') ? 'active' : '' }}" href="{{ route('finance.index') }}"><i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard</a>@endif
    <a class="list-group-item list-group-item-action {{ request()->routeIs('finance.workflow.*') ? 'active' : '' }}" href="{{ route('finance.workflow.index') }}"><i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน</a>
</div>

@if (auth()->user()->hasPermission('finance.settlements.view') || auth()->user()->hasPermission('finance.payment-vouchers.view') || auth()->user()->hasPermission('finance.advance-deposits.view') || auth()->user()->hasPermission('finance.internal-transfers.view') || auth()->user()->hasPermission('finance.employee-advances.view') || auth()->user()->hasPermission('finance.employee-advance-clearings.view') || auth()->user()->hasPermission('finance.petty-cash.view') || auth()->user()->hasPermission('finance.petty-cash-top-ups.view') || auth()->user()->hasPermission('finance.petty-cash-clearings.view') || auth()->user()->hasPermission('finance.petty-cash.manage-funds'))
    @php($cashActive = request()->routeIs('finance.settlements.*', 'finance.payment-vouchers.*', 'finance.pre-payment-vouchers.*', 'finance.advance-deposits.*', 'finance.advances.*', 'finance.deposits.*', 'finance.internal-transfers.*'))
    <p class="eyebrow px-3 mb-2">ธุรกรรมการเงิน</p>
    <div class="list-group mb-4">
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $cashActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-cash-menu" aria-expanded="{{ $cashActive ? 'true' : 'false' }}" aria-controls="finance-cash-menu"><span><i class="bx bx-transfer me-2" aria-hidden="true"></i>เงินรับ–จ่าย</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="finance-cash-menu" class="collapse {{ $cashActive ? 'show' : '' }}">
            @if (auth()->user()->hasPermission('finance.settlements.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.settlements.*') ? 'active' : '' }}" href="{{ route('finance.settlements.index') }}">รับเงิน / จ่ายเงิน</a>@endif
            @if (auth()->user()->hasPermission('finance.payment-vouchers.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.pre-payment-vouchers.*') ? 'active' : '' }}" href="{{ route('finance.pre-payment-vouchers.index') }}">ใบขอจ่ายล่วงหน้า</a><a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.payment-vouchers.*') ? 'active' : '' }}" href="{{ route('finance.payment-vouchers.index') }}">ใบสำคัญจ่าย</a>@endif
            @if (auth()->user()->hasPermission('finance.advance-deposits.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.advances.*') ? 'active' : '' }}" href="{{ route('finance.advances.index') }}">เงินล่วงหน้า</a><a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.deposits.*') ? 'active' : '' }}" href="{{ route('finance.deposits.index') }}">เงินมัดจำ</a>@endif
            @if (auth()->user()->hasPermission('finance.internal-transfers.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.internal-transfers.*') ? 'active' : '' }}" href="{{ route('finance.internal-transfers.index') }}">โอนเงินระหว่างบัญชี</a>@endif
        </div>
    @if (auth()->user()->hasPermission('finance.employee-advances.view') || auth()->user()->hasPermission('finance.employee-advance-clearings.view'))
        @php($employeeAdvanceActive = request()->routeIs('finance.employee-advances.*', 'finance.employee-advance-clearings.*'))
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $employeeAdvanceActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-employee-advance-menu" aria-expanded="{{ $employeeAdvanceActive ? 'true' : 'false' }}" aria-controls="finance-employee-advance-menu"><span><i class="bx bx-user me-2" aria-hidden="true"></i>เงินทดรองจ่าย</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="finance-employee-advance-menu" class="collapse {{ $employeeAdvanceActive ? 'show' : '' }}">
            @if (auth()->user()->hasPermission('finance.employee-advances.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.employee-advances.*') ? 'active' : '' }}" href="{{ route('finance.employee-advances.index') }}">ใบเงินทดรองพนักงาน</a>@endif
            @if (auth()->user()->hasPermission('finance.employee-advance-clearings.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.employee-advance-clearings.*') ? 'active' : '' }}" href="{{ route('finance.employee-advance-clearings.index') }}">เคลียร์เงินทดรองพนักงาน</a>@endif
        </div>
    @endif

    @if (auth()->user()->hasPermission('finance.petty-cash.view') || auth()->user()->hasPermission('finance.petty-cash-top-ups.view') || auth()->user()->hasPermission('finance.petty-cash-clearings.view') || auth()->user()->hasPermission('finance.petty-cash.manage-funds'))
        @php($pettyActive = request()->routeIs('finance.petty-cash.*', 'finance.petty-cash-top-ups.*', 'finance.petty-cash-clearings.*', 'finance.petty-cash-funds.*'))
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $pettyActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-petty-menu" aria-expanded="{{ $pettyActive ? 'true' : 'false' }}" aria-controls="finance-petty-menu"><span><i class="bx bx-wallet me-2" aria-hidden="true"></i>เงินสดย่อย</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="finance-petty-menu" class="collapse {{ $pettyActive ? 'show' : '' }}">
            @if (auth()->user()->hasPermission('finance.petty-cash.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.petty-cash.*') ? 'active' : '' }}" href="{{ route('finance.petty-cash.index') }}">ใบสำคัญเงินสดย่อย</a>@endif
            @if (auth()->user()->hasPermission('finance.petty-cash-top-ups.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.petty-cash-top-ups.*') ? 'active' : '' }}" href="{{ route('finance.petty-cash-top-ups.index') }}">เติมเงินสดย่อย</a>@endif
            @if (auth()->user()->hasPermission('finance.petty-cash-clearings.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.petty-cash-clearings.*') ? 'active' : '' }}" href="{{ route('finance.petty-cash-clearings.index') }}">เคลียร์เงินสดย่อย</a>@endif
            @if (auth()->user()->hasPermission('finance.petty-cash.manage-funds'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.petty-cash-funds.*') ? 'active' : '' }}" href="{{ route('finance.petty-cash-funds.index') }}">วงเงินสดย่อย</a>@endif
        </div>
    @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.ar-open-items.view') || auth()->user()->hasPermission('finance.ar-aging.view') || auth()->user()->hasPermission('finance.ap-open-items.view') || auth()->user()->hasPermission('finance.ap-aging.view'))
    @php($receivableActive = request()->routeIs('finance.receivables.*'))
    @php($payableActive = request()->routeIs('finance.payables.*'))
    <p class="eyebrow px-3 mb-2">ลูกหนี้และเจ้าหนี้</p><div class="list-group mb-4">
        @if (auth()->user()->hasPermission('finance.ar-open-items.view') || auth()->user()->hasPermission('finance.ar-aging.view'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $receivableActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-receivables-menu" aria-expanded="{{ $receivableActive ? 'true' : 'false' }}" aria-controls="finance-receivables-menu"><span><i class="bx bx-user me-2" aria-hidden="true"></i>ลูกหนี้</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="finance-receivables-menu" class="collapse {{ $receivableActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('finance.ar-open-items.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.receivables.open-items.*') ? 'active' : '' }}" href="{{ route('finance.receivables.open-items.index') }}">รายการคงค้าง</a>@endif
                @if (auth()->user()->hasPermission('finance.ar-aging.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.receivables.aging.*') ? 'active' : '' }}" href="{{ route('finance.receivables.aging.index') }}">Aging ลูกหนี้</a>@endif
            </div>
        @endif
        @if (auth()->user()->hasPermission('finance.ap-open-items.view') || auth()->user()->hasPermission('finance.ap-aging.view'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $payableActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-payables-menu" aria-expanded="{{ $payableActive ? 'true' : 'false' }}" aria-controls="finance-payables-menu"><span><i class="bx bx-wallet me-2" aria-hidden="true"></i>เจ้าหนี้</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="finance-payables-menu" class="collapse {{ $payableActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('finance.ap-open-items.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.payables.open-items.*') ? 'active' : '' }}" href="{{ route('finance.payables.open-items.index') }}">รายการคงค้าง</a>@endif
                @if (auth()->user()->hasPermission('finance.ap-aging.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.payables.aging.*') ? 'active' : '' }}" href="{{ route('finance.payables.aging.index') }}">Aging เจ้าหนี้</a>@endif
            </div>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.commission-payouts.view') || auth()->user()->hasPermission('finance.reports.payment-activity.view') || auth()->user()->hasPermission('finance.reports.petty-cash.view') || auth()->user()->hasPermission('finance.reports.cash-position.view') || auth()->user()->hasPermission('finance.reports.expected.view') || auth()->user()->hasPermission('finance.reports.employee-advances.view') || auth()->user()->hasPermission('finance.reports.reconciliation.view') || auth()->user()->hasPermission('finance.reports.settlement-allocations.view'))
    @php($reportActive = request()->routeIs('finance.reports.*'))
    <p class="eyebrow px-3 mb-2">รายงานและงานควบคุม</p><div class="list-group mb-4">
        @if (auth()->user()->hasPermission('finance.commission-payouts.view'))<a class="list-group-item list-group-item-action {{ request()->routeIs('finance.commission-payouts.*') ? 'active' : '' }}" href="{{ route('finance.commission-payouts.index') }}"><i class="bx bx-money-withdraw me-2" aria-hidden="true"></i>ชุดจ่ายคอมมิชชั่น</a>@endif
        @if (auth()->user()->hasPermission('finance.reports.payment-activity.view') || auth()->user()->hasPermission('finance.reports.petty-cash.view') || auth()->user()->hasPermission('finance.reports.cash-position.view') || auth()->user()->hasPermission('finance.reports.expected.view') || auth()->user()->hasPermission('finance.reports.employee-advances.view') || auth()->user()->hasPermission('finance.reports.reconciliation.view') || auth()->user()->hasPermission('finance.reports.settlement-allocations.view'))
            <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $reportActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-reports-menu" aria-expanded="{{ $reportActive ? 'true' : 'false' }}" aria-controls="finance-reports-menu"><span><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงาน</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
            <div id="finance-reports-menu" class="collapse {{ $reportActive ? 'show' : '' }}">
                @if (auth()->user()->hasPermission('finance.reports.payment-activity.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.payment-activity.*') ? 'active' : '' }}" href="{{ route('finance.reports.payment-activity.index') }}">รายงานธุรกรรมการเงิน</a>@endif
                @if (auth()->user()->hasPermission('finance.reports.petty-cash.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.petty-cash.*') ? 'active' : '' }}" href="{{ route('finance.reports.petty-cash.index') }}">รายงานเงินสดย่อย</a>@endif
                @if (auth()->user()->hasPermission('finance.reports.cash-position.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.cash-position.*') ? 'active' : '' }}" href="{{ route('finance.reports.cash-position.index') }}">สถานะเงินสดและธนาคาร</a>@endif
                @if (auth()->user()->hasPermission('finance.reports.expected.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.expected.*') ? 'active' : '' }}" href="{{ route('finance.reports.expected.index') }}">ยอดคาดว่าจะรับและจ่าย</a>@endif
                @if (auth()->user()->hasPermission('finance.reports.employee-advances.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.employee-advances.*') ? 'active' : '' }}" href="{{ route('finance.reports.employee-advances.index') }}">รายงานเงินทดรองพนักงาน</a>@endif
                @if (auth()->user()->hasPermission('finance.reports.reconciliation.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.reconciliation.*') ? 'active' : '' }}" href="{{ route('finance.reports.reconciliation.index') }}">กระทบยอด Finance กับ GL</a>@endif
                @if (auth()->user()->hasPermission('finance.reports.settlement-allocations.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.reports.settlement-allocations.*') ? 'active' : '' }}" href="{{ route('finance.reports.settlement-allocations.index') }}">รายงาน Settlement และ Allocation</a>@endif
            </div>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('finance.bank-accounts.view') || auth()->user()->hasPermission('finance.other-categories.view') || auth()->user()->hasPermission('finance.payment-terms.view'))
    @php($masterActive = request()->routeIs('finance.bank-accounts.*', 'finance.other-categories.*', 'finance.payment-terms.*'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักการเงิน</p><div class="list-group mb-4">
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $masterActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#finance-master-menu" aria-expanded="{{ $masterActive ? 'true' : 'false' }}" aria-controls="finance-master-menu"><span><i class="bx bx-cog me-2" aria-hidden="true"></i>ตั้งค่าการเงิน</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="finance-master-menu" class="collapse {{ $masterActive ? 'show' : '' }}">
            @if (auth()->user()->hasPermission('finance.bank-accounts.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.bank-accounts.*') ? 'active' : '' }}" href="{{ route('finance.bank-accounts.index') }}">บัญชีเงินสด/ธนาคาร</a>@endif
            @if (auth()->user()->hasPermission('finance.other-categories.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.other-categories.*') ? 'active' : '' }}" href="{{ route('finance.other-categories.index') }}">รายได้/รายจ่ายอื่น</a>@endif
            @if (auth()->user()->hasPermission('finance.payment-terms.view'))<a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('finance.payment-terms.*') ? 'active' : '' }}" href="{{ route('finance.payment-terms.index') }}">เงื่อนไขการชำระเงิน</a>@endif
        </div>
    </div>
@endif
