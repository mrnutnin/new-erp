@if($paginator->hasPages())
<nav class="sf-pager" aria-label="เปลี่ยนหน้า{{ $label }}">
    @if($paginator->onFirstPage())<span class="sf-page-disabled">ก่อนหน้า</span>@else<a class="btn btn-app-soft" href="{{ $paginator->previousPageUrl() }}"><i class="bx bx-chevron-left" aria-hidden="true"></i>ก่อนหน้า</a>@endif
    <span>หน้า {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} · {{ $paginator->total() }} รายการ</span>
    @if($paginator->hasMorePages())<a class="btn btn-app-soft" href="{{ $paginator->nextPageUrl() }}">ถัดไป<i class="bx bx-chevron-right" aria-hidden="true"></i></a>@else<span class="sf-page-disabled">ถัดไป</span>@endif
</nav>
@endif
