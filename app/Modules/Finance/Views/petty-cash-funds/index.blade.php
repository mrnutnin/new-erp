@extends('Finance::layout')

@section('title', 'วงเงินสดย่อย | Finance')

@section('content')
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div><p class="eyebrow mb-2">FINANCE / PETTY CASH</p><h1 class="h3 mb-2">วงเงินสดย่อย</h1><p class="text-secondary mb-0">กำหนดบัญชีเงินสด ผู้ดูแล และวงเงินสำหรับคลังปัจจุบัน</p></div>
            @if (auth()->user()->hasPermission('finance.petty-cash.manage-funds'))<a class="btn btn-dark" href="{{ route('finance.petty-cash-funds.create') }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>เพิ่มวงเงินสดย่อย</a>@endif
        </div>

        <div class="card border-0 shadow-sm mb-4" aria-labelledby="petty-cash-fund-filter-title"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 id="petty-cash-fund-filter-title" class="h5 mb-1">ตัวกรอง</h2><p class="small text-secondary mb-0">กรองข้อมูลก่อนดูรายการ</p></div><button type="button" id="petty-cash-fund-filter-reset" class="btn btn-sm btn-outline-secondary">ล้างตัวกรอง</button></div><div class="row g-3"><div class="col-sm-6 col-lg-4"><label class="form-label" for="petty-cash-fund-status">สถานะ</label><select id="petty-cash-fund-status" class="form-select"><option value="">ทุกสถานะ</option><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div></div></div></div>

        <div class="card border-0 shadow-sm"><div class="card-body p-3 p-lg-4"><div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="h5 mb-1">รายการวงเงินสดย่อย</h2><p class="small text-secondary mb-0">ค้นหา เรียงลำดับ ส่งออก และจัดการวงเงินได้จากตารางนี้</p></div></div><div class="table-responsive"><table id="petty-cash-funds-table" class="table table-hover align-middle w-100" data-url="{{ route('finance.petty-cash-funds.data') }}" data-can-manage="{{ auth()->user()->hasPermission('finance.petty-cash.manage-funds') ? 1 : 0 }}"><thead><tr><th>ชื่อวงเงิน</th><th>บัญชีเงินสด</th><th>ผู้ดูแล</th><th class="text-end">วงเงิน</th><th>สถานะ</th>@if (auth()->user()->hasPermission('finance.petty-cash.manage-funds'))<th class="text-end">จัดการ</th>@endif</tr></thead></table></div></div></div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var $table = $('#petty-cash-funds-table'), text = $.fn.dataTable.render.text();
            var columns = [
                { data: 'name', name: 'name', render: text.display },
                { data: 'bank_account_label', name: 'bank_account_id', render: text.display },
                { data: 'custodian_label', name: 'custodian_user_id', render: text.display },
                { data: 'fund_limit', name: 'fund_limit', className: 'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
                { data: 'is_active', name: 'is_active', render: function (value, type) { return type === 'display' ? '<span class="badge ' + (value ? 'text-bg-success">ใช้งาน' : 'text-bg-secondary">ปิดใช้งาน') + '</span>' : value; } }
            ];
            if ($table.data('can-manage')) columns.push({ data: null, orderable: false, searchable: false, className: 'text-end text-nowrap', render: function (_, type, row) { if (type !== 'display') return ''; return '<a class="btn btn-sm btn-app-soft" href="' + text.display(row.edit_url) + '" title="แก้ไข" aria-label="แก้ไข"><i class="bx bx-edit" aria-hidden="true"></i></a> <button class="btn btn-sm btn-outline-danger js-delete-fund" data-url="' + text.display(row.delete_url) + '" type="button" title="ลบ" aria-label="ลบ"><i class="bx bx-trash" aria-hidden="true"></i></button>'; } });
            var table = $table.DataTable($.extend(true, {}, window.erpDataTableDefaults, { ajax: { url: $table.data('url'), data: function (data) { data.is_active = $('#petty-cash-fund-status').val(); } }, order: [[4, 'desc'], [0, 'asc']], buttons: [window.erpExcelButton($table)], columns: columns }));
            $('#petty-cash-fund-status').on('change', function () { table.ajax.reload(); });
            $('#petty-cash-fund-filter-reset').on('click', function () { $('#petty-cash-fund-status').val(''); table.ajax.reload(); });
            window.erpAjaxDelete({ button: '.js-delete-fund', reload: '#petty-cash-funds-table', confirm: 'ยืนยันการลบวงเงินสดย่อยที่ยังไม่เคยถูกอ้างอิงหรือไม่?' });
        });
    </script>
@endpush
