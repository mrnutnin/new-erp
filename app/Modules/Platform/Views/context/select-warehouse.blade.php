@extends('layouts.app')

@section('title', 'เลือกคลัง | New ERP')

@section('content')
    <div class="selection-shell container py-5">
        <div class="context-card card border-0 shadow-sm mx-auto">
            <div class="card-body p-4 p-md-5">
                <p class="eyebrow mb-2">STEP 2 OF 2</p>
                <h1 class="h3 mb-2">เลือกสาขาและคลัง</h1>
                <p class="text-secondary mb-4">Context นี้จะถูกใช้ต่อเมื่อเปลี่ยนโปรแกรม และเปลี่ยนได้จากแถบด้านบน</p>

                @if ($warehouses->isEmpty())
                    <div class="alert alert-light border mb-4">บัญชีนี้ยังไม่มีสิทธิ์เข้าใช้งานคลัง</div>
                @else
                    <form id="warehouse-context-form" action="{{ route('warehouses.store') }}" method="post">
                        @csrf
                        <div class="mb-4">
                            <label class="form-label" for="warehouse_id">คลังสินค้า</label>
                            <select class="js-select2 form-select" id="warehouse_id" name="warehouse_id" required>
                                <option value="">กรุณาเลือกคลัง</option>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected($selectedWarehouse?->id === $warehouse->id)>{{ $warehouse->branch->name }} — {{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback d-block" data-error-for="warehouse_id"></div>
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
        $(function () {
            window.erpAjaxForm({
                form: '#warehouse-context-form',
                redirect: true,
                alert: false
            });
        });
    </script>
@endpush
