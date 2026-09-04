@extends($moduleRoutePrefix === 'purchasing' ? 'Purchasing::layout' : 'Wms::layout')

@section('title', $documentType === 'CREDIT_NOTE' ? 'ใบลดหนี้ซื้อ | Purchasing' : ($documentType === 'INVOICE' ? 'ใบตั้งหนี้ซื้อ | Purchasing' : 'เอกสารซื้อ | Purchasing'))

@section('content')
    @php($moduleRoutePrefix = $moduleRoutePrefix ?? 'wms')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
            <div>
                <p class="eyebrow mb-2">PURCHASING / DOCUMENTS</p>
                <h1 class="h3 mb-2">{{ $documentType === 'CREDIT_NOTE' ? 'ใบลดหนี้ซื้อ' : ($documentType === 'INVOICE' ? 'ใบตั้งหนี้ซื้อ' : 'เอกสารซื้อ') }}</h1>
                <p class="text-secondary mb-0">{{ $documentType === 'CREDIT_NOTE' ? 'คืนสินค้า/ลดหนี้จากใบตั้งหนี้ที่ลงบัญชีแล้ว โดยอ้างอิงเอกสารต้นทางเสมอ' : 'Expense/Service แบบ NONE VAT · Post เข้าสมุดซื้อและเจ้าหนี้ตาม Account Mapping' }}</p>
            </div>
            @if (auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.create'))
                <a class="btn btn-dark" href="{{ route($moduleRoutePrefix.'.purchase-documents.create', $documentType ? ['document_type' => $documentType] : []) }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>{{ $documentType === 'CREDIT_NOTE' ? 'สร้างใบลดหนี้' : 'สร้างใบตั้งหนี้' }}</a>
            @endif
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-3 p-lg-4">
                @if($documentType === 'CREDIT_NOTE')
                    <div class="alert alert-info border-0 py-2 mb-3"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>ใบลดหนี้ซื้อใช้สำหรับคืนสินค้า/ลดหนี้จากใบตั้งหนี้ที่ Post แล้ว ระบบจะตรวจ Supplier, คลัง, ยอดสะสม และเก็บประวัติการดำเนินการไว้</div>
                @endif
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="purchase-documents-table"
                           data-url="{{ route($moduleRoutePrefix.'.purchase-documents.data', $documentType ? ['document_type' => $documentType] : []) }}"
                           data-has-actions="{{ auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.update') || auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.approve') || auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.post') || auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.inventory-post') || auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.void') || auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.inventory-reverse') || auth()->user()->hasPermission($moduleRoutePrefix.'.purchase-documents.delete') ? 1 : 0 }}">
                        <thead><tr><th>เลขที่</th><th>ประเภท</th><th>วันที่</th><th>Supplier</th><th>เอกสารต้นทาง</th><th>ครบกำหนด</th><th class="text-end">ยอดรวม</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#purchase-documents-table');
            var text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'document_number', name: 'purchase_documents.document_number', render: function (value, type, row) { return type === 'display' ? '<a href="' + text.display(row.show_url) + '">' + text.display(value) + '</a>' : value; } },
                { data: 'document_type', name: 'purchase_documents.document_type', render: function (value, type) { if (type !== 'display') return value; return '<span class="badge ' + (value === 'INVOICE' ? 'app-status-primary' : 'app-status-danger') + '">' + (value === 'INVOICE' ? 'ใบตั้งหนี้' : 'ใบลดหนี้') + '</span>'; } },
                { data: 'document_date_label', name: 'purchase_documents.document_date', render: text.display },
                { data: 'supplier_label', name: 'purchase_documents.supplier_code', render: text.display },
                { data: 'original_label', name: 'originals.document_number', render: function (value, type, row) { if (type !== 'display' || !row.original_url) return text.display(value); return '<a href="' + text.display(row.original_url) + '">' + text.display(value) + '</a>'; } },
                { data: 'due_date_label', name: 'purchase_documents.due_date', render: text.display },
                { data: 'gross_amount', name: 'purchase_documents.gross_amount', className: 'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
                { data: 'status_label', name: 'purchase_documents.status', render: function (value, type, row) { if (type !== 'display') return row.status; var classes = { DRAFT: 'app-status-neutral', APPROVED: 'app-status-info', POSTED: 'app-status-success', VOID: 'app-status-danger' }; return '<span class="badge ' + (classes[row.status] || classes.DRAFT) + '">' + text.display(value) + '</span>'; } },
                { data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: function (value, type, row) { if (type !== 'display') return ''; var actions = ['<a class="btn btn-sm btn-outline-dark" href="' + text.display(row.show_url) + '">ดู</a>']; if (row.edit_url) actions.push('<a class="btn btn-sm btn-outline-secondary" href="' + text.display(row.edit_url) + '">แก้ไข</a>'); if (row.approve_url) actions.push('<button class="btn btn-sm btn-app-soft js-purchase-action" data-url="' + text.display(row.approve_url) + '" data-label="อนุมัติ" type="button">อนุมัติ</button>'); if (row.post_url) actions.push('<button class="btn btn-sm btn-outline-success js-purchase-post" data-url="' + text.display(row.post_url) + '" data-label="Post" data-document-date="' + text.display(row.document_date_iso) + '" type="button">Post</button>'); if (row.inventory_post_url) actions.push('<button class="btn btn-sm btn-app-soft js-purchase-post" data-url="' + text.display(row.inventory_post_url) + '" data-label="Post Inventory" data-document-date="' + text.display(row.document_date_iso) + '" type="button">Post Inventory</button>'); if (row.inventory_reverse_url) actions.push('<button class="btn btn-sm btn-outline-warning js-purchase-reverse" data-url="' + text.display(row.inventory_reverse_url) + '" data-document-date="' + text.display(row.document_date_iso) + '" type="button">กลับรายการ</button>'); if (row.void_url) actions.push('<button class="btn btn-sm btn-outline-danger js-purchase-action" data-url="' + text.display(row.void_url) + '" data-label="ยกเลิก" type="button">ยกเลิก</button>'); return actions.join(' '); } }
            ];
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: $table.data('url'), order: [[2, 'desc']], buttons: [window.erpExcelButton($table)], columns: columns }));
            table.on('draw', function () { table.rows({ page: 'current' }).every(function () { var row = this.data(); if (row.delete_url) $(this.node()).find('td:last').append(' <button class="btn btn-sm btn-outline-danger js-purchase-delete" data-url="' + text.display(row.delete_url) + '" type="button">ลบร่าง</button>'); }); });

            $(document).on('click', '.js-purchase-delete', function () { var $button=$(this); Swal.fire({ title:'ลบร่างเอกสารซื้อ?', text:'ลบได้เฉพาะเอกสารที่ยังเป็นร่างก่อนอนุมัติ', icon:'warning', showCancelButton:true, confirmButtonText:'ลบเอกสาร', cancelButtonText:'ยกเลิก' }).then(function(result){ if(!result.isConfirmed)return; $button.prop('disabled',true); $.ajax({url:$button.data('url'),method:'DELETE',data:{_token:$('meta[name="csrf-token"]').attr('content')}}).done(function(response){ Swal.fire({icon:'success',text:response.msg}).then(function(){table.ajax.reload(null,false);}); }).fail(function(xhr){ var errors=(xhr.responseJSON||{}).errors||{}; Swal.fire({icon:'error',text:(errors.status||['ลบเอกสารไม่สำเร็จ'])[0]}); $button.prop('disabled',false); }); }); });

            $(document).on('click', '.js-purchase-action', function () {
                var $button = $(this);
                if ($button.data('submitting')) return;
                var approve = $button.data('label') === 'อนุมัติ';
                var options = { title: $button.data('label') + 'เอกสาร', showCancelButton: true, confirmButtonText: $button.data('label'), cancelButtonText: 'ยกเลิก' };
                if (!approve) { options.input = 'textarea'; options.inputLabel = 'เหตุผล (อย่างน้อย 10 ตัวอักษร)'; options.preConfirm = function (reason) { reason = $.trim(reason || ''); if (reason.length < 10) { Swal.showValidationMessage('กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร'); return false; } return reason; }; }
                Swal.fire(options).then(function (result) {
                    if (!result.isConfirmed) return;
                    $button.data('submitting', true).prop('disabled', true);
                    $.ajax({ url: $button.data('url'), method: 'POST', data: { reason: approve ? '' : result.value }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
                        .done(function (response) { Swal.fire({ icon: 'success', text: response.msg }); table.ajax.reload(null, false); })
                        .fail(function (xhr) { var response = xhr.responseJSON || {}; var errors = response.errors || {}; Swal.fire({ icon: 'error', text: (errors.reason || errors.status || errors.lines || [response.message || 'ไม่สามารถดำเนินการได้'])[0] }); })
                        .always(function () { $button.data('submitting', false).prop('disabled', false); });
                });
            });

            $(document).on('click', '.js-purchase-post', function () {
                var $button = $(this);
                if ($button.data('submitting')) return;
                var today = @json(today()->format('Y-m-d'));
                var minimum = $button.data('document-date');
                var label = $button.data('label') || 'Post';
                Swal.fire({ title: label + ' เอกสาร', input: 'date', inputLabel: 'วันที่ Post', inputValue: today < minimum ? minimum : today, inputAttributes: { min: minimum }, showCancelButton: true, confirmButtonText: label }).then(function (result) {
                    if (!result.isConfirmed) return;
                    $button.data('submitting', true).prop('disabled', true);
                    $.ajax({ url: $button.data('url'), method: 'POST', data: { posting_date: result.value }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } })
                        .done(function (response) { Swal.fire({ icon: 'success', text: response.msg }); table.ajax.reload(null, false); })
                        .fail(function (xhr) { var response=xhr.responseJSON||{}, errors=response.errors||{}; Swal.fire({ icon:'error', text:(errors.posting_date||errors.status||errors.account_mapping||errors.lines||[response.message||'ไม่สามารถ Post ได้'])[0] }); })
                        .always(function () { $button.data('submitting', false).prop('disabled', false); });
                });
            });
            $table.on('draw.dt', function () { $table.DataTable().rows({ page: 'current' }).every(function () { var row = this.data(), $cell=$(this.node()).find('td:last'); if (row.print_url && !$cell.find('.js-pdf-print').length) $cell.prepend('<a class="btn btn-sm btn-app-soft js-pdf-print" target="_blank" title="พิมพ์ PDF" aria-label="พิมพ์ PDF" href="' + text.display(row.print_url) + '"><i class="bx bx-printer"></i></a> '); }); });
        });
    </script>
@endpush
