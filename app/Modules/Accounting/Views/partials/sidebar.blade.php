<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}">
        <i class="bx bx-grid-alt me-2" aria-hidden="true"></i>กลับหน้าเลือกโปรแกรม
    </a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.index') ? 'active' : '' }}" href="{{ route('accounting.index') }}">
        <i class="bx bx-home-alt-2 me-2" aria-hidden="true"></i>Dashboard
    </a>
    <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.workflow.*') ? 'active' : '' }}" href="{{ route('accounting.workflow.index') }}">
        <i class="bx bx-map-alt me-2" aria-hidden="true"></i>คู่มือการทำงาน
    </a>
</div>

@if (auth()->user()->hasPermission('accounting.journal-entries.view'))
    <p class="eyebrow px-3 mb-2">รายการบัญชี</p>
    <div class="list-group mb-4">
        @php($journalMenu = [['label' => 'สมุดรายวันรวม', 'type' => null], ['label' => 'สมุดรายวันซื้อ', 'type' => 'PURCHASE'], ['label' => 'สมุดรายวันขาย', 'type' => 'SALES'], ['label' => 'สมุดรายวันรับ', 'type' => 'RECEIPT'], ['label' => 'สมุดรายวันจ่าย', 'type' => 'PAYMENT'], ['label' => 'สมุดรายวันทั่วไป', 'type' => 'GENERAL']])
        @php($journalActive = request()->routeIs('accounting.journal-entries.*'))
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $journalActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#accounting-journal-menu" aria-expanded="{{ $journalActive ? 'true' : 'false' }}" aria-controls="accounting-journal-menu"><span><i class="bx bx-book-open me-2" aria-hidden="true"></i>สมุดรายวัน</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="accounting-journal-menu" class="collapse {{ $journalActive ? 'show' : '' }}">
            @foreach ($journalMenu as $journalItem)
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ $journalActive && request()->input('book_type') === $journalItem['type'] ? 'active' : '' }}" href="{{ route('accounting.journal-entries.index', $journalItem['type'] ? ['book_type' => $journalItem['type']] : []) }}">{{ $journalItem['label'] }}</a>
            @endforeach
        </div>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.reports.view') || auth()->user()->hasPermission('accounting.reports.comparative-income.view'))
    <p class="eyebrow px-3 mb-2">รายงานบัญชี</p>
    <div class="list-group mb-4">
        @php($financialReportsActive = request()->routeIs('accounting.reports.general-ledger.*', 'accounting.reports.working-paper.*', 'accounting.reports.trial-balance.*', 'accounting.reports.balance-sheet.*', 'accounting.reports.profit-loss.*', 'accounting.reports.comparative-income.*'))
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $financialReportsActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#accounting-financial-report-menu" aria-expanded="{{ $financialReportsActive ? 'true' : 'false' }}" aria-controls="accounting-financial-report-menu"><span><i class="bx bx-bar-chart-alt-2 me-2" aria-hidden="true"></i>รายงานการเงิน</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="accounting-financial-report-menu" class="collapse {{ $financialReportsActive ? 'show' : '' }}">
            @if (auth()->user()->hasPermission('accounting.reports.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.general-ledger.*') ? 'active' : '' }}" href="{{ route('accounting.reports.general-ledger.index') }}">บัญชีแยกประเภท</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.trial-balance.*') ? 'active' : '' }}" href="{{ route('accounting.reports.trial-balance.index') }}">งบทดลอง</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.working-paper.*') ? 'active' : '' }}" href="{{ route('accounting.reports.working-paper.index') }}">กระดาษทำการ</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.balance-sheet.*') ? 'active' : '' }}" href="{{ route('accounting.reports.balance-sheet.index') }}">งบดุล</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.profit-loss.*') ? 'active' : '' }}" href="{{ route('accounting.reports.profit-loss.index') }}">งบกำไรขาดทุน</a>
            @endif
            @if (auth()->user()->hasPermission('accounting.reports.comparative-income.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.comparative-income.*') ? 'active' : '' }}" href="{{ route('accounting.reports.comparative-income.index') }}">เปรียบเทียบรายได้</a>
            @endif
            @if (auth()->user()->hasPermission('accounting.reports.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.cash-flow.*') ? 'active' : '' }}" href="{{ route('accounting.reports.cash-flow.index') }}">กระแสเงินสด</a>
            @endif
        </div>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.reports.view') || auth()->user()->hasPermission('accounting.reports.withholding-expense.view') || auth()->user()->hasPermission('accounting.reports.withholding-received.view'))
    <p class="eyebrow px-3 mb-2">รายงานภาษีและหัก ณ ที่จ่าย</p>
    <div class="list-group mb-4">
        @php($taxReportsActive = request()->routeIs('accounting.reports.tax.*', 'accounting.reports.withholding-expense.*', 'accounting.reports.withholding-received.*'))
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $taxReportsActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#accounting-tax-report-menu" aria-expanded="{{ $taxReportsActive ? 'true' : 'false' }}" aria-controls="accounting-tax-report-menu"><span><i class="bx bx-receipt me-2" aria-hidden="true"></i>รายงานภาษี</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="accounting-tax-report-menu" class="collapse {{ $taxReportsActive ? 'show' : '' }}">
            @if (auth()->user()->hasPermission('accounting.reports.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.tax.*') && !request()->input('tax_kind') ? 'active' : '' }}" href="{{ route('accounting.reports.tax.index') }}">รายงานภาษีรวม</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.tax.*') && request()->input('tax_kind') === 'VAT_IN' ? 'active' : '' }}" href="{{ route('accounting.reports.tax.index', ['tax_kind' => 'VAT_IN']) }}">รายการภาษีซื้อ</a>
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.tax.*') && request()->input('tax_kind') === 'VAT_OUT' ? 'active' : '' }}" href="{{ route('accounting.reports.tax.index', ['tax_kind' => 'VAT_OUT']) }}">รายงานภาษีขาย</a>
            @endif
            @if (auth()->user()->hasPermission('accounting.reports.withholding-expense.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.withholding-expense.*') ? 'active' : '' }}" href="{{ route('accounting.reports.withholding-expense.index') }}">WHT ค่าใช้จ่าย</a>
            @endif
            @if (auth()->user()->hasPermission('accounting.reports.withholding-received.view'))
                <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.withholding-received.*') ? 'active' : '' }}" href="{{ route('accounting.reports.withholding-received.index') }}">WHT ถูกหัก ณ ที่จ่าย</a>
            @endif
        </div>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.reports.view'))
    <p class="eyebrow px-3 mb-2">กระทบยอดบัญชี</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.bank-reconciliation.*') ? 'active' : '' }}" href="{{ route('accounting.bank-reconciliation.index') }}"><i class="bx bx-buildings me-2" aria-hidden="true"></i>Bank Reconciliation</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.ar-ap-reconciliation.*') ? 'active' : '' }}" href="{{ route('accounting.reports.ar-ap-reconciliation.index') }}"><i class="bx bx-transfer me-2" aria-hidden="true"></i>AR/AP Reconciliation</a>
        <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.reports.reconciliation.*') ? 'active' : '' }}" href="{{ route('accounting.reports.reconciliation.index') }}"><i class="bx bx-check-shield me-2" aria-hidden="true"></i>กระทบยอดบัญชีคุม</a>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.periods.view'))
    <p class="eyebrow px-3 mb-2">ปิดงวดบัญชี</p>
    <div class="list-group mb-4">
        <a class="list-group-item list-group-item-action {{ request()->routeIs('accounting.fiscal-years.*', 'accounting.fiscal-periods.*') ? 'active' : '' }}" href="{{ route('accounting.fiscal-years.index') }}">
            <i class="bx bx-calendar me-2" aria-hidden="true"></i>งวดบัญชีและการปิดงวด
        </a>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.journal-entries.view'))
    <p class="eyebrow px-3 mb-2">Audit และ Control</p>
    <div class="list-group mb-4">
        @php($auditControlActive = request()->routeIs('accounting.audit-log.*', 'accounting.reports.posting-exceptions.*', 'accounting.journal-approval-queue.*') || (request()->routeIs('accounting.journal-entries.*') && request('status') === 'REVERSED'))
        <button class="list-group-item list-group-item-action d-flex align-items-center justify-content-between {{ $auditControlActive ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#accounting-audit-control-menu" aria-expanded="{{ $auditControlActive ? 'true' : 'false' }}" aria-controls="accounting-audit-control-menu"><span><i class="bx bx-shield-quarter me-2" aria-hidden="true"></i>Audit และ Control</span><i class="bx bx-chevron-down" aria-hidden="true"></i></button>
        <div id="accounting-audit-control-menu" class="collapse {{ $auditControlActive ? 'show' : '' }}">
            <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.journal-approval-queue.*') ? 'active' : '' }}" href="{{ route('accounting.journal-approval-queue.index') }}">Journal Approval Queue</a>
            <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.journal-entries.*') && request('status') === 'REVERSED' ? 'active' : '' }}" href="{{ route('accounting.journal-entries.index', ['status' => 'REVERSED']) }}">ประวัติการกลับรายการ</a>
            <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.reports.posting-exceptions.*') ? 'active' : '' }}" href="{{ route('accounting.reports.posting-exceptions.index') }}">Posting Error / Exception</a>
            <a class="list-group-item list-group-item-action border-0 small fw-normal {{ request()->routeIs('accounting.audit-log.*') ? 'active' : '' }}" href="{{ route('accounting.audit-log.index') }}">Audit Log</a>
        </div>
    </div>
@endif

@if (auth()->user()->hasPermission('accounting.accounts.view') || auth()->user()->hasPermission('accounting.account-mappings.view') || auth()->user()->hasPermission('accounting.periods.view') || auth()->user()->hasPermission('accounting.journal-books.view') || auth()->user()->hasPermission('accounting.tax-codes.view'))
    <p class="eyebrow px-3 mb-2">ตั้งค่าบัญชี</p>
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
