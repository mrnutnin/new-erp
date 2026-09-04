<div class="card border-0 shadow-sm mb-4" id="report-filters">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h6 mb-0">ตัวกรองสาขาและคลัง</h2><button type="button" class="btn btn-outline-secondary btn-sm" id="report-filter-reset"><i class="bx bx-reset me-1" aria-hidden="true"></i>ล้างตัวกรอง</button></div>
        <div class="row g-3">
            <div class="col-12 col-md-6"><label class="form-label" for="report-branch">สาขา</label><select class="form-select" id="report-branch"><option value="current" @selected(request('branch_scope', 'current') === 'current')>สาขาปัจจุบัน</option><option value="all" @selected(request('branch_scope') === 'all')>ทุกสาขาที่มีสิทธิ์</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) request('branch_scope') === (string) $branch->id)>{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
            <div class="col-12 col-md-6"><label class="form-label" for="report-warehouse">คลัง</label><select class="form-select" id="report-warehouse"><option value="">ทุกคลังที่มีสิทธิ์</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" data-branch="{{ $warehouse->branch_id }}" @selected((int) request('warehouse_id') === (int) $warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></div>
        </div>
    </div>
</div>
