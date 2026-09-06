<div class="card border-0 shadow-sm">
    <div class="card-body p-4 p-md-5">
        @php($assignedRoles = $assignedRoles ?? collect())
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
            <div>
                <h2 class="h5 mb-1">สิทธิ์ Feature / Function</h2>
                <p class="small text-secondary mb-0">สิทธิ์ที่ผู้ใช้ได้รับจริงจากบทบาทที่เปิดใช้งาน</p>
            </div>
            <span class="badge text-bg-light border">{{ $effectivePermissions->count() }} สิทธิ์</span>
        </div>
        <div class="small text-secondary mt-2">
            การเพิ่ม/ลบสิทธิ์ให้แก้ที่บทบาท (Role) เพื่อให้ผู้ใช้ทุกคนได้รับสิทธิ์ตามมาตรฐานเดียวกัน
            @if (auth()->user()->hasPermission('settings.roles.manage'))
                <span class="ms-1">เปิดแก้ไขได้จาก:</span>
                @forelse ($assignedRoles as $role)
                    <a class="badge text-bg-light border text-decoration-none ms-1" href="{{ route('settings.roles.edit', $role) }}">{{ $role->name }}</a>
                @empty
                    <span class="ms-1">ยังไม่ได้กำหนด Role</span>
                @endforelse
            @endif
        </div>

        @php($permissionGroups = $effectivePermissions->groupBy(fn ($permission) => strtoupper(explode('.', $permission->code, 2)[0])))
        @if ($permissionGroups->isNotEmpty())
            <div class="overflow-auto mt-3">
                <ul class="nav nav-pills flex-nowrap gap-2" role="tablist">
                    @foreach ($permissionGroups as $feature => $permissions)
                        @php($tabId = 'permission-feature-'.md5($feature))
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if ($loop->first) active @endif" id="{{ $tabId }}-tab" data-bs-toggle="tab" data-bs-target="#{{ $tabId }}" type="button" role="tab" aria-controls="{{ $tabId }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                {{ $feature }} <span class="badge text-bg-light ms-1">{{ $permissions->count() }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="tab-content mt-3">
                @foreach ($permissionGroups as $feature => $permissions)
                    @php($tabId = 'permission-feature-'.md5($feature))
                    <div class="tab-pane fade @if ($loop->first) show active @endif" id="{{ $tabId }}" role="tabpanel" aria-labelledby="{{ $tabId }}-tab" tabindex="0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr><th>Function</th><th>รหัสสิทธิ์</th><th>รายละเอียด</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($permissions as $permission)
                                        <tr>
                                            <td>{{ $permission->name }}</td>
                                            <td><code>{{ $permission->code }}</code></td>
                                            <td class="text-secondary">{{ $permission->description ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-secondary py-3">ยังไม่มีสิทธิ์ Feature / Function จากบทบาทที่เปิดใช้งาน</div>
        @endif
    </div>
</div>
