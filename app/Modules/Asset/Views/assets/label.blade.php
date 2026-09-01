@extends('Asset::layout')

@section('title', 'พิมพ์ป้ายสินทรัพย์ | New ERP')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4 print-label-page">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-4 d-print-none">
            <a class="btn btn-outline-dark" href="{{ route('asset.assets.show', $asset) }}"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>กลับทะเบียนสินทรัพย์</a>
            <button class="btn btn-dark js-print-label" type="button"><i class="bx bx-printer me-1" aria-hidden="true"></i>พิมพ์ป้าย</button>
        </div>
        <section class="card border-dark print-label" aria-labelledby="asset-label-title">
            <div class="card-body p-3 text-center">
                <p class="small text-secondary mb-1">{{ $branch->code }} · {{ $branch->name }}</p>
                <h1 class="h5 mb-1" id="asset-label-title">{{ $asset->name }}</h1>
                <p class="small mb-2">{{ $asset->asset_number }}</p>
                <svg class="w-100" viewBox="0 0 {{ $barcode['maxw'] }} 48" role="img" aria-label="บาร์โค้ด {{ $barcodeValue }}" xmlns="http://www.w3.org/2000/svg">
                    @php($x = 0)
                    @foreach ($barcode['bcode'] as $bar)
                        @if ($bar['t'])<rect x="{{ $x }}" y="0" width="{{ $bar['w'] }}" height="48" />@endif
                        @php($x += $bar['w'])
                    @endforeach
                </svg>
                <p class="font-monospace small mb-0 mt-2">{{ $barcodeValue }}</p>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
<script>
$(function () { $('.js-print-label').on('click', function () { window.print(); }); });
</script>
@endpush
