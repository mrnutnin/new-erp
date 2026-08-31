<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}">
        <i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม
    </a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.workflow.*') ? 'active' : '' }}" href="{{ route('accounting.workflow.index') }}">
        <i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน
    </a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.index') ? 'active' : '' }}" href="{{ route('accounting.index') }}">
        <i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard
    </a>
</div>

@if (auth()->user()->hasPermission('accounting.journal-entries.view'))
    <p class="eyebrow px-3 mb-2">รายการบัญชี</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.journal-entries.*') ? 'active' : '' }}" href="{{ route('accounting.journal-entries.index') }}">
            <i class="bx bx-receipt me-2" aria-hidden="true"></i>รายการสมุดรายวัน
        </a>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.reports.view') || auth()->user()->hasPermission('accounting.reports.comparative-income.view') || auth()->user()->hasPermission('accounting.reports.withholding-expense.view') || auth()->user()->hasPermission('accounting.reports.withholding-received.view'))
    <p class="eyebrow px-3 mb-2">รายงานบัญชี</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('accounting.reports.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.general-ledger.*') ? 'active' : '' }}" href="{{ route('accounting.reports.general-ledger.index') }}"><i class="bx bx-book-open me-2" aria-hidden="true"></i>บัญชีแยกประเภท</a>
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.trial-balance.*') ? 'active' : '' }}" href="{{ route('accounting.reports.trial-balance.index') }}"><i class="bx bx-table me-2" aria-hidden="true"></i>งบทดลอง</a>
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.balance-sheet.*') ? 'active' : '' }}" href="{{ route('accounting.reports.balance-sheet.index') }}"><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>งบดุล</a>
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.profit-loss.*') ? 'active' : '' }}" href="{{ route('accounting.reports.profit-loss.index') }}"><i class="bx bx-line-chart me-2" aria-hidden="true"></i>งบกำไรขาดทุน</a>
        @endif
        @if (auth()->user()->hasPermission('accounting.reports.withholding-expense.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.withholding-expense.*') ? 'active' : '' }}" href="{{ route('accounting.reports.withholding-expense.index') }}"><i class="bx bx-money me-2" aria-hidden="true"></i>WHT ค่าใช้จ่าย</a>
        @endif
        @if (auth()->user()->hasPermission('accounting.reports.withholding-received.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.withholding-received.*') ? 'active' : '' }}" href="{{ route('accounting.reports.withholding-received.index') }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>WHT ถูกหัก ณ ที่จ่าย</a>
        @endif
        @if (auth()->user()->hasPermission('accounting.reports.comparative-income.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.comparative-income.*') ? 'active' : '' }}" href="{{ route('accounting.reports.comparative-income.index') }}"><i class="bx bx-git-compare me-2" aria-hidden="true"></i>เปรียบเทียบรายได้</a>
        @endif
        @if (auth()->user()->hasPermission('accounting.reports.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.tax.*') ? 'active' : '' }}" href="{{ route('accounting.reports.tax.index') }}"><i class="bx bx-receipt me-2" aria-hidden="true"></i>รายงานภาษี</a>
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.reconciliation.*') ? 'active' : '' }}" href="{{ route('accounting.reports.reconciliation.index') }}"><i class="bx bx-check-shield me-2" aria-hidden="true"></i>กระทบยอดบัญชีคุม</a>
        @endif
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.accounts.view') || auth()->user()->hasPermission('accounting.account-mappings.view') || auth()->user()->hasPermission('accounting.periods.view') || auth()->user()->hasPermission('accounting.journal-books.view') || auth()->user()->hasPermission('accounting.tax-codes.view'))
    <p class="eyebrow px-3 mb-2">ข้อมูลหลักบัญชี</p>
    <div class="list-group mb-4">
        @if (auth()->user()->hasPermission('accounting.accounts.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.accounts.*') || request()->routeIs('accounting.account-import.*') ? 'active' : '' }}" href="{{ route('accounting.accounts.index') }}">
                <i class="bx bx-list-ul me-2" aria-hidden="true"></i>ผังบัญชี
            </a>
        @endif
        @if (auth()->user()->hasPermission('accounting.account-mappings.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.account-mappings.*') ? 'active' : '' }}" href="{{ route('accounting.account-mappings.index') }}">
                <i class="bx bx-link-alt me-2" aria-hidden="true"></i>Account Mapping
            </a>
        @endif
        @if (auth()->user()->hasPermission('accounting.periods.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.fiscal-years.*') || request()->routeIs('accounting.fiscal-periods.*') ? 'active' : '' }}" href="{{ route('accounting.fiscal-years.index') }}">
                <i class="bx bx-calendar me-2" aria-hidden="true"></i>ปีและงวดบัญชี
            </a>
        @endif
        @if (auth()->user()->hasPermission('accounting.journal-books.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.journal-books.*') ? 'active' : '' }}" href="{{ route('accounting.journal-books.index') }}">
                <i class="bx bx-book-bookmark me-2" aria-hidden="true"></i>สมุดบัญชี
            </a>
        @endif
        @if (auth()->user()->hasPermission('accounting.tax-codes.view'))
            <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.tax-codes.*') ? 'active' : '' }}" href="{{ route('accounting.tax-codes.index') }}">
                <i class="bx bx-purchase-tag me-2" aria-hidden="true"></i>Tax Code
            </a>
        @endif
    </div>
@endif
