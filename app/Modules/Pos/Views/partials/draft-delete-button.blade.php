@if($document->status === 'DRAFT' && auth()->user()->hasPermission($permission))
    <button class="btn btn-app-danger d-inline-flex align-items-center gap-1 js-delete-pos-draft"
        type="button" data-url="{{ $url }}">
        <i class="bx bx-trash" aria-hidden="true"></i>ลบร่าง
    </button>
    @once
        @push('scripts')
            <script>
                $(function () {
                    window.erpAjaxDelete({
                        button: '.js-delete-pos-draft',
                        redirect: true,
                        confirm: 'ยืนยันการลบร่างนี้หรือไม่? ข้อมูลร่างจะถูกลบและไม่สามารถเรียกคืนได้',
                        confirmButtonText: 'ลบร่าง',
                        cancelButtonText: 'กลับ'
                    });
                });
            </script>
        @endpush
    @endonce
@endif
