(function ($) {
    'use strict';

    window.erpDataTableDefaults = {
        processing: true,
        serverSide: true,
        paging: true,
        lengthChange: true,
        searching: true,
        searchDelay: 300,
        deferRender: true,
        scrollX: true,
        autoWidth: false,
        orderMulti: false,
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        layout: {
            topStart: 'buttons',
            topEnd: 'search',
            bottomStart: 'pageLength',
            bottomEnd: 'paging'
        },
        language: {
            emptyTable: 'ไม่พบข้อมูล',
            info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
            infoEmpty: 'ไม่มีข้อมูล',
            lengthMenu: 'แสดง _MENU_ รายการ',
            loadingRecords: 'กำลังโหลด...',
            processing: 'กำลังประมวลผล...',
            search: 'ค้นหา:',
            zeroRecords: 'ไม่พบข้อมูลที่ค้นหา'
        }
    };

    window.erpRowNumberColumn = function () {
        return {
            data: null,
            orderable: false,
            searchable: false,
            className: 'text-center',
            render: function (value, type, row, meta) {
                return type === 'display' ? meta.settings._iDisplayStart + meta.row + 1 : '';
            }
        };
    };

    window.erpExcelButton = function ($table) {
        return {
            extend: 'excelHtml5',
            text: '<i class="bx bx-download me-1" aria-hidden="true"></i>ส่งออก Excel (หน้านี้)',
            className: 'btn btn-app-soft dt-export-excel',
            titleAttr: 'ส่งออกเฉพาะรายการที่แสดงในหน้าปัจจุบัน',
            exportOptions: {
                columns: ':visible'
            }
        };
    };

    $.fn.dataTable.ext.errMode = 'none';
    $(document).on('error.dt', 'table.dataTable', function () {
        Swal.fire({
            icon: 'error',
            text: 'ไม่สามารถโหลดข้อมูลตารางได้ กรุณาลองใหม่'
        });
    });
})(jQuery);
