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
        document.querySelectorAll('.btn-dark').forEach(element => { element.classList.remove('btn-dark'); element.classList.add('btn-app-primary'); });
        document.querySelectorAll('.btn-outline-dark, .btn-outline-secondary').forEach(element => { element.classList.remove('btn-outline-dark', 'btn-outline-secondary'); element.classList.add('btn-app-soft'); });
        document.querySelectorAll('.btn-outline-danger').forEach(element => { element.classList.remove('btn-outline-danger'); element.classList.add('btn-app-danger'); });
        document.querySelectorAll('button[id$="-filter"].btn-app-soft').forEach(element => { element.classList.remove('btn-app-soft'); element.classList.add('btn-app-primary'); element.lastChild.nodeValue = ' ใช้ตัวกรอง'; });
        document.querySelectorAll('button[id$="-filter"]').forEach(filter => {
            const resetId = `${filter.id.replace(/-filter$/, '')}-reset`;
            if (document.getElementById(resetId)) return;
            const card = filter.closest('.card');
            const filterArea = filter.closest('form, .row');
            const cardBody = card?.querySelector('.card-body');
            if (! card || ! filterArea || ! cardBody) return;

            const reset = document.createElement('button');
            reset.type = 'button'; reset.id = resetId; reset.className = 'btn btn-sm btn-app-soft'; reset.innerHTML = '<i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง';
            reset.addEventListener('click', () => { const card = filter.closest('.card') || document; card.querySelectorAll('input:not([readonly]), select').forEach(field => { field.value = ''; $(field).trigger('change'); }); filter.click(); });
            const header = document.createElement('div');
            header.className = 'd-flex flex-wrap justify-content-between align-items-center gap-2 mb-3';
            header.innerHTML = '<div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="text-secondary mb-0 small">กรองข้อมูลก่อนค้นหาจากตาราง</p></div>';
            header.append(reset);
            cardBody.insertBefore(header, filterArea);
            window.erpStandardizeFilterResets(card);
        });
        document.querySelectorAll('a.btn[href], a.list-group-item[href]').forEach(element => {
            if (/^(?:←\s*)?(?:กลับเอก|ยกเลิก)$|^กลับเอก/.test(element.textContent.trim())) setLabel(element, 'กลับหน้ารายการ');
        });
        document.querySelectorAll('.js-void, .js-void-sale, .js-cancel-full-sale, .js-sales-return-cancel, .js-quotation-cancel, .js-order-action[data-reason="1"], .js-return-cancel, .js-advance-deposit-cancel').forEach(element => setLabel(element, 'ยกเลิกเอกสาร'));
    };
    normalizeLabels();
    new MutationObserver(normalizeLabels).observe(document.body, { childList: true, subtree: true });
});
</script>
@endpush
