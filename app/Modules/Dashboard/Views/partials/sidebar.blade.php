<p class="eyebrow px-3 mb-2">การนำทาง</p>
<div class="list-group mb-4">
    <a class="list-group-item list-group-item-action {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="bx bx-grid-alt me-2" aria-hidden="true"></i>Executive Dashboard</a>
    <a class="list-group-item list-group-item-action" href="{{ route('programs.index') }}"><i class="bx bx-collection me-2" aria-hidden="true"></i>เลือกโปรแกรม</a>
    <a class="list-group-item list-group-item-action" href="{{ route('branches.index') }}"><i class="bx bx-map me-2" aria-hidden="true"></i>เปลี่ยนสาขา</a>
</div>

<p class="eyebrow px-3 mb-2">มุมมองผู้บริหาร</p>
<div class="list-group mb-4">
    <div class="list-group-item small text-secondary"><i class="bx bx-line-chart me-2" aria-hidden="true"></i>ภาพรวมธุรกิจ</div>
    <div class="list-group-item small text-secondary"><i class="bx bx-error-circle me-2" aria-hidden="true"></i>สิ่งที่ต้องให้ความสนใจ</div>
    <div class="list-group-item small text-secondary"><i class="bx bx-bulb me-2" aria-hidden="true"></i>เรื่องที่ควรตัดสินใจ</div>
</div>
