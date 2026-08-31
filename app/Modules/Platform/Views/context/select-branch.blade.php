@extends('layouts.app')

@section('title', 'เลือกสาขา | New ERP')

@section('content')
    <div class="selection-shell container py-5">
        <div class="context-card card border-0 shadow-sm mx-auto">
            <div class="card-body p-4 p-md-5">
                <p class="eyebrow mb-2">STEP 2 OF 2</p>
                <h1 class="h3 mb-2">เลือกสาขา</h1>
                <p class="text-secondary mb-4">สาขาเป็นบริบทการทำงานหลัก ระบบจะเลือกคลังเริ่มต้นของสาขาให้เมื่อทำรายการสินค้า</p>

                @if ($branches->isEmpty())
                    <div class="alert alert-light border mb-4">บัญชีนี้ยังไม่มีสิทธิ์เข้าใช้งานสาขาที่มีคลังพร้อมใช้งาน</div>
                @else
                    <form id="branch-context-form" action="{{ route('branches.store') }}" method="post">
                        @csrf
                        <div class="mb-4">
                            <label class="form-label" for="branch_id">สาขา</label>
                            <select class="js-select2 form-select" id="branch_id" name="branch_id" required>
                                <option value="">กรุณาเลือกสาขา</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($selectedBranch?->id === $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback d-block" data-error-for="branch_id"></div>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-dark" href="{{ route('programs.index') }}">ย้อนกลับ</a>
                            <button class="btn btn-dark flex-grow-1" type="submit" data-busy-text="กำลังเลือก...">เริ่มทำงาน</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () { window.erpAjaxForm({form: '#branch-context-form', redirect: true, alert: false}); });
    </script>
@endpush
