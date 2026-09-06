@php($filterId = $filterId ?? 'wms-document-filters')
<section id="{{ $filterId }}" class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div><h2 class="h5 mb-1">ตัวกรอง</h2><p class="text-secondary small mb-0">กรองข้อมูลก่อนค้นหาในรายการ</p></div>
            <button type="button" class="btn btn-sm btn-app-soft js-wms-reset-filter">ล้างตัวกรอง</button>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-12 {{ isset($issueTypeOptions) ? 'col-md-3' : 'col-md-4' }}"><label class="form-label" for="{{ $filterId }}-status">สถานะ</label><select id="{{ $filterId }}-status" class="form-select js-wms-filter-status"><option value="">ทุกสถานะ</option>@foreach(($statusOptions ?? []) as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            @if(isset($issueTypeOptions))
            <div class="col-12 col-md-3"><label class="form-label" for="{{ $filterId }}-issue-type">ประเภทการเบิก</label><select id="{{ $filterId }}-issue-type" class="form-select js-wms-filter-issue-type"><option value="">ทุกประเภท</option>@foreach($issueTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }} ({{ $value }})</option>@endforeach</select></div>
            @endif
            @if(isset($branchOptions))
            <div class="col-12 col-md-4"><label class="form-label" for="{{ $filterId }}-source-branch">สาขาต้นทาง</label><select id="{{ $filterId }}-source-branch" class="form-select js-wms-filter-source-branch"><option value="">ทุกสาขา</option>@foreach($branchOptions as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
            <div class="col-12 col-md-4"><label class="form-label" for="{{ $filterId }}-destination-branch">สาขาปลายทาง</label><select id="{{ $filterId }}-destination-branch" class="form-select js-wms-filter-destination-branch"><option value="">ทุกสาขา</option>@foreach($branchOptions as $branch)<option value="{{ $branch->id }}">{{ $branch->code }} · {{ $branch->name }}</option>@endforeach</select></div>
            @endif
            <div class="col-12 {{ isset($issueTypeOptions) ? 'col-md-2' : 'col-md-3' }}"><label class="form-label" for="{{ $filterId }}-from">วันที่เริ่มต้น</label><input id="{{ $filterId }}-from" class="form-control js-wms-filter-from" type="date"></div>
            <div class="col-12 {{ isset($issueTypeOptions) ? 'col-md-2' : 'col-md-3' }}"><label class="form-label" for="{{ $filterId }}-to">วันที่สิ้นสุด</label><input id="{{ $filterId }}-to" class="form-control js-wms-filter-to" type="date"></div>
            <div class="col-12 col-md-2"><button type="button" class="btn btn-dark w-100 js-wms-apply-filter">ค้นหา</button></div>
        </div>
    </div>
</section>
