@extends('layouts.app')

@push('styles')
<link rel="manifest" href="{{ asset('production-shop-floor.webmanifest') }}">
<meta name="theme-color" content="#c45b22">
<link rel="apple-touch-icon" href="{{ asset('images/alexiasoft-logo.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="MintERP Shop Floor">
@endpush

@section('sidebar')
    @include('Production::partials.sidebar')
@endsection

@push('scripts')
<script>
$(function(){
    const buttons=$('[data-production-install]'); let installPrompt=null;
    const standalone=window.matchMedia('(display-mode: standalone)').matches||window.navigator.standalone===true;
    const isiOS=/iphone|ipad|ipod/i.test(navigator.userAgent);
    const show=()=>{if(!standalone)buttons.removeClass('d-none');};
    if('serviceWorker' in navigator) navigator.serviceWorker.register('/production-shop-floor-sw.js').catch(()=>{});
    window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();installPrompt=event;show();});
    window.addEventListener('appinstalled',()=>{installPrompt=null;buttons.addClass('d-none');});
    if(isiOS||window.matchMedia('(max-width: 767.98px)').matches)show();
    buttons.on('click',async function(){
        if(installPrompt){installPrompt.prompt();await installPrompt.userChoice;installPrompt=null;return;}
        const instruction=isiOS?'แตะปุ่ม Share <i class="bx bx-share"></i> แล้วเลือก <strong>Add to Home Screen</strong>':'เปิดเมนู Browser แล้วเลือก <strong>ติดตั้งแอป</strong> หรือ <strong>เพิ่มลงในหน้าจอหลัก</strong>';
        Swal.fire({icon:'info',title:'ติดตั้ง MintERP Shop Floor',html:instruction,confirmButtonText:'เข้าใจแล้ว'});
    });
});
</script>
@endpush
