@extends('Accounting::layout')

@section('title', 'สมุดบัญชี | New ERP')

@section('content')
    @php($canUpdate = auth()->user()->hasPermission('accounting.journal-books.update'))
    <div class="container-fluid px-3 px-lg-4 py-4">
        <div class="page-heading mb-4">
            <p class="eyebrow mb-2">ACCOUNTING</p>
            <h1 class="h3 mb-2">สมุดบัญชี</h1>
            <p class="text-secondary mb-0">สมุดระบบ 5 เล่มสำหรับซื้อ ขาย รับ จ่าย และรายการทั่วไป</p>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form id="journal-books-form" action="{{ route('accounting.journal-books.update') }}" method="post" novalidate>
                    @csrf
                    @method('PUT')

                    <div class="table-responsive">
                        <table class="table table-hover align-middle w-100" id="journal-books-table">
                            <thead>
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>รหัส</th>
                                    <th>ชื่อสมุด</th>
                                    <th>ประเภท</th>
                                    <th>Prefix</th>
                                    <th class="text-center">เปิดใช้งาน</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($books as $book)
                                    <tr>
                                        <td>{{ $book->sort_order }}</td>
                                        <td><span class="badge text-bg-dark">{{ $book->code }}</span></td>
                                        <td>{{ $book->name }}</td>
                                        <td>{{ ['PURCHASE' => 'ซื้อ', 'SALES' => 'ขาย', 'RECEIPT' => 'รับ', 'PAYMENT' => 'จ่าย', 'GENERAL' => 'ทั่วไป'][$book->type] }}</td>
                                        <td>{{ $book->sequence_prefix }}</td>
                                        <td class="text-center">
                                            <input type="hidden" name="books[{{ $book->id }}][is_active]" value="0">
                                            <input class="form-check-input" type="checkbox" name="books[{{ $book->id }}][is_active]" value="1" @checked($book->is_active) @disabled(! $canUpdate) aria-label="เปิดใช้งาน {{ $book->name }}">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($canUpdate)
                        <div class="row g-3 align-items-end mt-3">
                            <div class="col-12 col-lg-8">
                                <label class="form-label" for="change_reason">เหตุผลการเปลี่ยนแปลง</label>
                                <input class="form-control" id="change_reason" name="change_reason" maxlength="500" required>
                                <div class="invalid-feedback" data-error-for="change_reason"></div>
                            </div>
                            <div class="col-12 col-lg-4 text-lg-end">
                                <button class="btn btn-dark" type="submit" data-busy-text="กำลังบันทึก...">
                                    <i class="bx bx-save me-1" aria-hidden="true"></i>บันทึกสถานะ
                                </button>
                            </div>
                        </div>
                    @endif
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('#journal-books-table').DataTable($.extend(true, {}, window.erpDataTableDefaults, {
                processing: false,
                serverSide: false,
                pageLength: 10,
                buttons: [window.erpExcelButton($('#journal-books-table'))],
                order: [[0, 'asc']],
                columnDefs: [{ targets: 5, orderable: false, searchable: false }]
            }));

            window.erpAjaxForm({
                form: '#journal-books-form',
                reload: true
            });
        });
    </script>
@endpush
