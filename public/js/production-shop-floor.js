/* Shared tablet workboard: no offline business writes. */
$(function () {
    const token = $('meta[name="csrf-token"]').attr('content');
    const networkStatus = $('#shop-floor-network-status');
    const setNetworkStatus = () => networkStatus.toggleClass('d-none', navigator.onLine);
    setNetworkStatus();
    $(window).on('online offline', setNetworkStatus);
    const online = () => { setNetworkStatus(); return navigator.onLine; };
    const message = xhr => Object.values(xhr.responseJSON?.errors || {}).flat()[0] || xhr.responseJSON?.message || 'ทำรายการไม่สำเร็จ กรุณาตรวจสอบการเชื่อมต่อแล้วลองใหม่';
    const feedback = text => $('#shop-floor-feedback').removeClass('d-none alert-success').addClass('alert-danger').text(text);
    const feedbackKey = 'shop-floor-feedback:' + location.pathname;
    try {
        const success = sessionStorage.getItem(feedbackKey);
        if (success) $('#shop-floor-feedback').removeClass('d-none alert-danger').addClass('alert-success').text(success);
        sessionStorage.removeItem(feedbackKey);
    } catch (_) { /* Storage can be unavailable in private browser sessions. */ }
    function reloadWithSuccess(response) {
        try { sessionStorage.setItem(feedbackKey, response.msg || 'บันทึกแล้ว ตรวจสอบสถานะและขั้นตอนถัดไป'); } catch (_) {}
        location.reload();
    }

    // Account for the ERP toolbar and iPad browser chrome without locking body scroll.
    const toolbar = document.querySelector('.app-topbar');
    const size = () => document.documentElement.style.setProperty('--sf-toolbar-height', (toolbar?.getBoundingClientRect().height || 0) + 'px');
    size();
    if (toolbar && window.ResizeObserver) new ResizeObserver(size).observe(toolbar);
    $(window).on('resize', size);

    const tabs = [...document.querySelectorAll('.sf-tabs [data-bs-toggle="tab"]')];
    function showTab(id) {
        const tab = tabs.find(el => el.getAttribute('aria-controls') === id);
        if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
    }
    showTab(location.hash.slice(1));
    $(window).on('hashchange', () => showTab(location.hash.slice(1)));
    tabs.forEach(tab => tab.addEventListener('shown.bs.tab', () => {
        history.replaceState(null, '', location.pathname + location.search + '#' + tab.getAttribute('aria-controls'));
    }));
    $('[data-sf-tab]').on('click', function () { showTab(this.dataset.sfTab); document.getElementById('sf-tab-' + this.dataset.sfTab)?.focus(); });

    $('.js-production-elapsed').each(function () {
        const el = $(this), start = Date.parse(el.data('started-at')), end = Date.parse(el.data('ended-at'));
        if (!start) return;
        const render = () => {
            const seconds = Math.max(0, Math.floor(((end || Date.now()) - start) / 1000));
            el.text(Math.floor(seconds / 3600) + ' ชม. ' + Math.floor(seconds % 3600 / 60) + ' นาที');
        };
        render();
        if (!end) setInterval(render, 1000);
    });

    const confirmAction = label => Swal.fire({icon: 'question', title: label + '?', text: 'ตรวจสอบข้อมูลก่อนทำรายการ', showCancelButton: true, confirmButtonText: label, cancelButtonText: 'กลับไปตรวจสอบ'}).then(r => r.isConfirmed);
    async function submit(button, url, data, method = 'POST') {
        if (button.prop('disabled') || !online()) return;
        button.prop('disabled', true);
        try {
            if (!await confirmAction(button.attr('aria-label') || button.text().trim())) return;
            if (!online()) return;
            reloadWithSuccess(await $.ajax({url, type: method, data: {_token: token, ...data}}));
        } catch (xhr) {
            feedback(message(xhr));
        } finally {
            button.prop('disabled', false);
        }
    }
    $(document).on('click', '.js-shop-action, .js-shop-resume', function () { submit($(this), $(this).data('url'), {}); });
    $(document).on('submit', '.js-shop-create', function (e) {
        e.preventDefault();
        submit($(this).find('[type=submit]'), this.action, Object.fromEntries(new FormData(this)));
    });
    $(document).on('click', '.js-operation-delete', function () { submit($(this), $(this).data('url'), {}, 'DELETE'); });

    // SweetAlert keeps the dialog open on validation/server errors and blocks duplicate submits.
    function dialogRequest(url, data, method = 'POST') {
        if (!online()) { Swal.showValidationMessage('ไม่มีการเชื่อมต่อเครือข่าย'); return false; }
        return $.ajax({url, type: method, data: {_token: token, ...data}}).catch(xhr => { Swal.showValidationMessage(message(xhr)); return false; });
    }
    const dialogOptions = {showCancelButton: true, cancelButtonText: 'ปิด', showLoaderOnConfirm: true, allowOutsideClick: () => !Swal.isLoading()};
    $(document).on('click', '.js-shop-hold', async function () {
        if (!online()) return;
        const b = $(this);
        const result = await Swal.fire({...dialogOptions, icon: 'warning', title: 'พักงานผลิต?', input: 'textarea', inputLabel: 'เหตุผลที่พักงาน (อย่างน้อย 10 ตัวอักษร)', confirmButtonText: 'พักงานผลิต', preConfirm: value => {
            if (value.trim().length < 10) { Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return false; }
            return dialogRequest(b.data('url'), {reason: value.trim()});
        }});
        if (result.isConfirmed) reloadWithSuccess(result.value);
    });
    $(document).on('click', '.js-shop-scrap', async function () {
        if (!online()) return;
        const b = $(this);
        const result = await Swal.fire({...dialogOptions, title: 'บันทึกร่าง Scrap', html: '<label for="sf-scrap-qty">จำนวนของเสีย</label><input id="sf-scrap-qty" class="swal2-input" type="number" min="0.00000001" step="0.00000001"><label for="sf-scrap-reason">เหตุผลอย่างน้อย 5 ตัวอักษร</label><textarea id="sf-scrap-reason" class="swal2-textarea"></textarea>', confirmButtonText: 'บันทึกร่าง Scrap', preConfirm: () => {
            const quantity = $('#sf-scrap-qty').val(), reason = $('#sf-scrap-reason').val().trim();
            if (!quantity || Number(quantity) <= 0 || reason.length < 5) { Swal.showValidationMessage('กรุณาระบุจำนวนมากกว่า 0 และเหตุผลอย่างน้อย 5 ตัวอักษร'); return false; }
            return dialogRequest(b.data('url'), {quantity, reason, uom_id: b.data('uom-id')});
        }});
        if (result.isConfirmed) reloadWithSuccess(result.value);
    });
    $(document).on('click', '.js-operation-add, .js-operation-edit', async function () {
        if (!online()) return;
        const b = $(this), edit = b.hasClass('js-operation-edit');
        const result = await Swal.fire({...dialogOptions, title: edit ? 'แก้ไขขั้นตอนการผลิต' : 'เพิ่มขั้นตอนการผลิต', html: '<label for="sf-operation-name">ชื่อขั้นตอน</label><input id="sf-operation-name" class="swal2-input" maxlength="150"><label for="sf-operation-minutes">เวลาที่วางแผน (นาที)</label><input id="sf-operation-minutes" class="swal2-input" type="number" min="1" max="100000">', didOpen: () => { $('#sf-operation-name').val(b.data('name') || ''); $('#sf-operation-minutes').val(b.data('minutes') || ''); }, confirmButtonText: 'บันทึกขั้นตอน', preConfirm: () => {
            const name = $('#sf-operation-name').val().trim(), minutes = $('#sf-operation-minutes').val();
            if (!name || name.length > 150 || (minutes && (!Number.isInteger(Number(minutes)) || Number(minutes) < 1 || Number(minutes) > 100000))) { Swal.showValidationMessage('กรุณาระบุชื่อขั้นตอน และเวลาจำนวนเต็ม 1–100000 นาที หรือเว้นว่าง'); return false; }
            return dialogRequest(b.data('url'), {name, planned_minutes: minutes || null}, edit ? 'PUT' : 'POST');
        }});
        if (result.isConfirmed) reloadWithSuccess(result.value);
    });
    $(document).on('click', '.js-shop-resolve-issue', async function () {
        if (!online()) return;
        const button = $(this);
        if (button.prop('disabled')) return;
        const result = await Swal.fire({...dialogOptions, icon: 'question', title: 'ปิดปัญหา?', input: 'textarea', inputLabel: 'วิธีการแก้ปัญหา', inputAttributes: {maxlength: 2000}, confirmButtonText: 'ปิดปัญหา', preConfirm: value => {
            if (!value.trim()) { Swal.showValidationMessage('กรุณาระบุวิธีการแก้ปัญหา'); return false; }
            return dialogRequest(button.data('url'), {resolution_method: value.trim()});
        }});
        if (result.isConfirmed) { location.hash = 'issues'; reloadWithSuccess(result.value); }
    });
    $('#production-issue-form').on('submit', async function (e) {
        e.preventDefault();
        const b = $(this).find('[type=submit]'), formError = $('#production-issue-error');
        if (b.prop('disabled')) return;
        const checkConnection = () => {
            if (online()) return true;
            formError.removeClass('d-none').text('ไม่มีการเชื่อมต่อเครือข่าย กรุณาลองใหม่เมื่อเชื่อมต่อแล้ว');
            return false;
        };
        formError.addClass('d-none');
        $(this).find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
        if (!checkConnection()) return;
        b.prop('disabled', true);
        try {
            if (!await confirmAction('แจ้งปัญหา')) return;
            if (!checkConnection()) return;
            const response = await $.post(this.action, {_token: token, ...Object.fromEntries(new FormData(this))});
            location.hash = 'issues';
            reloadWithSuccess(response);
        } catch (xhr) {
            const errors = xhr.responseJSON?.errors || {};
            let fieldError = false;
            for (const name of ['severity', 'description']) {
                if (!errors[name]) continue;
                fieldError = true;
                $('#production-issue-' + name).addClass('is-invalid').attr('aria-invalid', 'true');
                $('#production-issue-' + name + '-error').text(errors[name][0]);
            }
            if (!fieldError) formError.removeClass('d-none').text(message(xhr));
        } finally {
            b.prop('disabled', false);
        }
    });
});
