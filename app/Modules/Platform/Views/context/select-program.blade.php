@extends('layouts.app')

@section('title', 'เลือกโปรแกรม | New ERP')

@section('content')
    <div class="selection-shell container py-5">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">STEP 1 OF 2</p>
            <h1 class="h3 mb-2">เลือกโปรแกรม</h1>
            <p class="text-secondary mb-0">เลือกส่วนงานที่ต้องการเข้าใช้งาน</p>
        </div>

        @if ($programs->isEmpty())
            <div class="alert alert-light border">บัญชีนี้ยังไม่มีสิทธิ์เข้าใช้งานโปรแกรม</div>
        @else
            <div class="row g-3">
                @foreach ($programs as $program)
                    <div class="col-12 col-md-6 col-xl-3">
                        <form class="js-program-form h-100" action="{{ route('programs.store') }}" method="post">
                            @csrf
                            <input name="program_id" type="hidden" value="{{ $program->id }}">
                            <button class="program-card card h-100 w-100 text-start border-0 shadow-sm" type="submit" data-busy-text="กำลังเลือก...">
                                @php($programCode = $program->code === 'purchasing' ? 'PU' : ($program->code === 'wms' ? 'WM' : strtoupper(substr($program->code, 0, 2))))
                                <span class="program-code">{{ $programCode }}</span>
                                <span class="h5 mt-4 mb-2">{{ $program->name }}</span>
                                <span class="text-secondary">{{ $program->description }}</span>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection


@push('scripts')
    <script>
        $(function () {
            window.erpAjaxForm({
                form: '.js-program-form',
                redirect: true,
                alert: false
            });
        });
    </script>
@endpush
