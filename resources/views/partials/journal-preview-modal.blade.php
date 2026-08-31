@if (auth()->user()->hasPermission('accounting.journal-entries.view'))
    <div class="modal fade" id="journal-preview-modal" tabindex="-1" aria-labelledby="journal-preview-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><div><p class="eyebrow mb-1">ACCOUNTING / POSTED GL</p><h2 class="modal-title h4 mb-0" id="journal-preview-title">รายการบัญชี</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
            <div class="modal-body"><div id="journal-preview-loading" class="text-center py-5 text-secondary">กำลังโหลดรายการบัญชี…</div><div id="journal-preview-content" class="d-none"><div class="row g-3 mb-4"><div class="col-md-3"><div class="small text-secondary">สถานะ</div><div class="fw-semibold" data-journal-status></div></div><div class="col-md-3"><div class="small text-secondary">วันที่ลงบัญชี</div><div class="fw-semibold" data-journal-entry-date></div></div><div class="col-md-3"><div class="small text-secondary">สมุดบัญชี</div><div class="fw-semibold" data-journal-book></div></div><div class="col-md-3"><div class="small text-secondary">งวดบัญชี</div><div class="fw-semibold" data-journal-period></div></div><div class="col-12"><div class="small text-secondary">คำอธิบาย</div><div data-journal-description></div></div></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>#</th><th>บัญชี</th><th>คำอธิบาย</th><th>ภาษี</th><th class="text-end">เดบิต</th><th class="text-end">เครดิต</th></tr></thead><tbody data-journal-lines></tbody><tfoot class="fw-semibold"><tr><td colspan="4" class="text-end">รวม</td><td class="text-end" data-journal-debit-total></td><td class="text-end" data-journal-credit-total></td></tr></tfoot></table></div></div></div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ปิด</button></div>
        </div></div>
    </div>
    <script>
    $(document).on('click', '[data-journal-preview-url], [data-journal-preview-urls]', function () {
        const button = $(this), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('journal-preview-modal')),
            loading = $('#journal-preview-loading'), content = $('#journal-preview-content'), esc = value => $('<div>').text(value ?? '—').html(), money = value => Number(value || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        let urls = button.data('journal-preview-urls') || [button.data('journal-preview-url')];
        if (typeof urls === 'string') urls = JSON.parse(urls);
        loading.removeClass('d-none').text('กำลังโหลดรายการบัญชี…'); content.addClass('d-none'); modal.show();
        $.when(...urls.map(url => $.get(url))).done(function (...responses) {
            const entries = urls.length === 1 ? [responses[0]] : responses.map(response => response[0]);
            let debit = 0, credit = 0;
            const first = entries[0];
            $('[data-journal-status]').text(entries.length === 1 ? first.status_label : entries.length+' รายการ'); $('[data-journal-entry-date]').text(first.entry_date || '—'); $('[data-journal-book]').text(entries.length === 1 ? first.book || '—' : 'หลายสมุดบัญชี'); $('[data-journal-period]').text(first.period || '—'); $('[data-journal-description]').text(entries.length === 1 ? first.description || '—' : 'รายการ GL ที่เกี่ยวข้องกับเอกสารนี้'); $('#journal-preview-title').text(entries.length === 1 ? 'รายการบัญชี '+first.entry_number : 'รายการ GL ทั้งรายการ');
            $('[data-journal-lines]').html(entries.map(entry => '<tr class="table-light"><td colspan="6"><strong>'+esc(entry.entry_number)+'</strong> <span class="text-secondary">· '+esc(entry.description)+'</span></td></tr>'+(entry.lines || []).map(line => { debit += Number(line.debit || 0); credit += Number(line.credit || 0); return '<tr><td>'+esc(line.line_number)+'</td><td>'+esc(line.account)+'</td><td>'+esc(line.description)+'</td><td>'+esc(line.tax_code || '—')+'</td><td class="text-end">'+money(line.debit)+'</td><td class="text-end">'+money(line.credit)+'</td></tr>'; }).join('')).join(''));
            $('[data-journal-debit-total]').text(money(debit)); $('[data-journal-credit-total]').text(money(credit)); loading.addClass('d-none'); content.removeClass('d-none');
        }).fail(function (xhr) { loading.text(xhr.responseJSON?.message || 'ไม่สามารถโหลดรายการบัญชีได้'); });
    });
    </script>
@endif
