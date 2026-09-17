@extends('layouts.app')

@section('title', 'เลือกโปรแกรม | '.($companySetting->company_name ?: config('app.name')))
@section('body-class', 'selection-page')

@section('content')
    <div class="selection-shell container py-5">
        <div class="selection-heading mb-4 p-4 p-md-5">
            <p class="eyebrow mb-2">STEP 1 OF 2</p>
            <div class="program-selection-brand mb-2">
                @if ($companyLogoDataUri)
                    <span class="program-selection-brand__logo-wrap">
                        <img class="program-selection-brand__logo" src="{{ $companyLogoDataUri }}" alt="โลโก้ {{ $companySetting->company_name }}">
                    </span>
                @endif
                <div class="program-selection-brand__content">
                    <p class="program-selection-brand__name mb-1">{{ $companySetting->company_name ?: config('app.name') }}</p>
                    <h1 class="h2 mb-0">เลือกโปรแกรม</h1>
                </div>
            </div>
            <p class="text-secondary mb-0">เลือกส่วนงานที่ต้องการเข้าใช้งาน</p>
        </div>

        @if ($programs->isEmpty())
            <div class="alert alert-light border">บัญชีนี้ยังไม่มีสิทธิ์เข้าใช้งานโปรแกรม</div>
        @else
            @php($programIcons = ['dashboard' => 'bx-bar-chart-alt-2', 'settings' => 'bx-cog', 'purchasing' => 'bx-cart-alt', 'wms' => 'bx-package', 'crm' => 'bx-group', 'pos' => 'bx-store-alt', 'finance' => 'bx-wallet', 'accounting' => 'bx-calculator', 'asset' => 'bx-building-house'])
            <div class="row g-3">
                @foreach ($programs as $program)
                    <div class="col-12 col-md-6 col-xl-3">
                        <form class="js-program-form h-100" action="{{ route('programs.store') }}" method="post">
                            @csrf
                            <input name="program_id" type="hidden" value="{{ $program->id }}">
                            <button class="program-card card h-100 w-100 text-start border-0" type="submit" data-busy-text="กำลังเลือก...">
                                <span class="program-code"><i class="bx {{ $programIcons[$program->code] ?? 'bx-grid-alt' }}" aria-hidden="true"></i></span>
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
