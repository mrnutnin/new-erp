@extends('layouts.app')

@section('sidebar')
    @include('Pos::partials.sidebar')
@endsection

@push('scripts')
<script>
$(function () {
    const setLabel = (element, label) => {
        const text = [...element.childNodes].reverse().find(node => node.nodeType === Node.TEXT_NODE && node.nodeValue.trim());
        if (text) text.nodeValue = ` ${label}`;
    };
    const normalizeLabels = () => {
        document.querySelectorAll('a.btn[href], a.list-group-item[href]').forEach(element => {
            if (/^(?:←\s*)?(?:กลับเอก|ยกเลิก)$|^กลับเอก/.test(element.textContent.trim())) setLabel(element, 'ย้อนกลับ');
        });
        document.querySelectorAll('.js-void, .js-void-sale, .js-cancel-full-sale, .js-sales-return-cancel, .js-quotation-cancel, .js-order-action[data-reason="1"], .js-return-cancel, .js-advance-deposit-cancel').forEach(element => setLabel(element, 'ยกเลิกเอกสาร'));
    };
    normalizeLabels();
    new MutationObserver(normalizeLabels).observe(document.body, { childList: true, subtree: true });
});
</script>
@endpush
