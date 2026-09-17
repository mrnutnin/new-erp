@forelse($customers as $customer)
<div class="col-12 col-lg-6">
    <article class="card h-100 crm-customer-card"><div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div class="min-w-0"><a class="crm-card-title text-body text-decoration-none text-break" href="{{ route('crm.customers.show',$customer) }}">{{ $customer->name }}</a><div class="small text-secondary mt-1">{{ $customer->code }} @if($customer->contact_name)· {{ $customer->contact_name }}@endif</div></div><span class="badge {{ $customer->customerRole?->is_active ? 'app-status-success' : 'app-status-neutral' }}">{{ $customer->customerRole?->is_active ? 'ใช้งาน' : 'ปิดใช้งาน' }}</span></div>
        <div class="crm-customer-contact mb-3"><span><i class="bx bx-phone" aria-hidden="true"></i>{{ $customer->phone ?: '-' }}</span><span><i class="bx bx-envelope" aria-hidden="true"></i>{{ $customer->email ?: '-' }}</span></div>
        <div class="row g-2 mb-3"><div class="col-6"><div class="crm-customer-metric"><small>Opportunity เปิด</small><strong>{{ number_format((int)$customer->open_opportunities_count) }}</strong></div></div><div class="col-6"><div class="crm-customer-metric is-value"><small>มูลค่า Pipeline</small><strong>{{ number_format((float)$customer->pipeline_value,2) }}</strong></div></div></div>
        <div class="d-flex justify-content-end gap-2 mt-auto"><a class="btn btn-sm btn-app-soft" href="{{ route('crm.customers.show',$customer) }}"><i class="bx bx-user-circle me-1" aria-hidden="true"></i>ดู Customer 360°</a>@if($customer->customerRole?->is_active && auth()->user()->hasPermission('crm.opportunities.create'))<a class="btn btn-sm btn-app-primary" href="{{ route('crm.opportunities.create',['party_id'=>$customer->id]) }}"><i class="bx bx-plus me-1" aria-hidden="true"></i>สร้าง Opportunity</a>@endif</div>
    </div></article>
</div>
@empty
<div class="col-12"><div class="card"><div class="card-body text-center py-5"><i class="bx bx-user-x fs-1 text-secondary" aria-hidden="true"></i><h2 class="h5 mt-3">ไม่พบลูกค้า</h2><p class="text-secondary mb-0">ลองเปลี่ยนคำค้นหา</p></div></div></div>
@endforelse
