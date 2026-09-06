<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ERP Setup | {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <style>
        body { background: #f3f5f8; color: #20252b; }
        .setup-shell { max-width: 1040px; margin: 4rem auto; }
        .setup-card { border: 0; border-radius: 1.25rem; box-shadow: 0 1rem 3rem rgba(31, 41, 55, .08); }
        .setup-check { border-bottom: 1px solid #edf0f3; padding: .9rem 0; }
        .setup-check:last-child { border-bottom: 0; }
        .status { min-width: 82px; text-align: center; }
        .installer-progress { height: .7rem; background: #e9eef3; border-radius: 999px; overflow: hidden; }
        .installer-progress-bar { width: 0; height: 100%; background: linear-gradient(90deg, #0d6efd, #20c997); transition: width .35s ease; }
    </style>
</head>
<body>
<main class="setup-shell px-3">
    <div class="mb-4">
        <p class="text-uppercase text-secondary small fw-semibold mb-2">New ERP · Implementation</p>
        <h1 class="display-6 fw-semibold mb-2">System Setup</h1>
        <p class="text-secondary mb-0">ตรวจสอบ environment ก่อนเริ่มเตรียมฐานข้อมูลและติดตั้ง ERP Defaults</p>
    </div>

    @if (! $authorized)
        <section class="setup-card bg-white p-4 p-md-5">
            <h2 class="h5">ต้องใช้ Installation Key</h2>
            <p class="text-secondary">เปิดหน้านี้ด้วย URL ที่ได้รับจาก Developer/DevOps เช่น <code>/setup?token=…</code></p>
            <div class="alert alert-warning mb-0">ยังไม่สามารถดำเนินการติดตั้งได้จนกว่าจะตรวจสอบ key สำเร็จ</div>
        </section>
    @else
        @php($failed = collect($checks)->where('status', 'fail')->count())
        @if ($notice)
            <div class="alert {{ str_contains($notice, 'สำเร็จ') ? 'alert-success' : 'alert-warning' }}">
                @if (str_contains($notice, 'สำหรับ Developer แล้วลองใหม่: '))
                    @php([$summary, $technical] = explode('สำหรับ Developer แล้วลองใหม่: ', $notice, 2))
                    <div>{{ $summary }}สำหรับ Developer แล้วลองใหม่</div>
                    <details class="mt-3" open><summary class="fw-semibold">SQL / Technical Error สำหรับ Developer</summary><pre class="small text-wrap bg-dark text-light rounded p-3 mt-2 mb-0">{{ $technical }}</pre></details>
                @else
                    {{ $notice }}
                @endif
            </div>
        @endif
        <section class="setup-card bg-white p-4 p-md-5">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <h2 class="h4 mb-1">1. System Check</h2>
                    <p class="text-secondary mb-0">ฐานข้อมูลยังสามารถเป็น Empty Database ได้ในขั้นตอนนี้</p>
                </div>
                <span class="badge {{ $failed ? 'text-bg-danger' : 'text-bg-success' }} px-3 py-2">
                    {{ $failed ? 'มีรายการที่ต้องแก้ไข' : 'พร้อมตรวจสอบต่อ' }}
                </span>
            </div>
            <div>
                @foreach ($checks as $check)
                    <div class="setup-check d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">{{ $check['label'] }}</div>
                            <div class="small text-secondary">{{ $check['detail'] }}</div>
                        </div>
                        <span class="status badge {{ $check['status'] === 'pass' ? 'text-bg-success' : ($check['status'] === 'warning' ? 'text-bg-warning' : 'text-bg-danger') }}">
                            {{ $check['status'] === 'pass' ? 'ผ่าน' : ($check['status'] === 'warning' ? 'เตือน' : 'ไม่ผ่าน') }}
                        </span>
                    </div>
                @endforeach
            </div>
            <div class="alert alert-info mt-4 mb-0">
                @if ($databasePrepared)
                    <strong>Database Ready</strong> — ฐานข้อมูลพร้อมสำหรับขั้นตอน Initialize System Defaults ใน Phase 3
                    @if (($installationState['installation_session_id'] ?? null))
                        <span class="d-block small mt-1">Installation session #{{ $installationState['installation_session_id'] }}</span>
                    @endif
                    @if (! $defaultsInitialized)
                        <form class="mt-3" action="{{ route('installer.initialize-defaults', ['token' => $token]) }}" method="post">
                            <button class="btn btn-dark" type="submit">Initialize System Defaults</button>
                        </form>
                    @else
                        <span class="d-block small text-success mt-2">System Defaults ติดตั้งแล้ว และสามารถทำซ้ำได้โดยไม่สร้างข้อมูลซ้ำ</span>
                        @if ($availableUpdates)
                            <div class="border rounded-3 p-3 mt-3 bg-white">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                    <div><strong>มี System Default Update</strong><div class="small text-secondary">พบ Default Data version ใหม่ที่ควรติดตั้งก่อนตรวจสอบ Setup</div></div>
                                    <form action="{{ route('installer.apply-system-updates', ['token' => $token]) }}" method="post" onsubmit="return confirm('ยืนยัน Apply System Updates หรือไม่?')"><button class="btn btn-outline-primary" type="submit">Apply System Updates ({{ count($availableUpdates) }})</button></form>
                                </div>
                                <div class="small text-secondary mt-2">{{ collect($availableUpdates)->map(fn ($update) => $update['seed_code'].' '.($update['current'] ?? 'ยังไม่ติดตั้ง').' → '.$update['latest'])->join(' · ') }}</div>
                            </div>
                        @endif
                    @endif
                @else
                    ขั้นถัดไปคือ <strong>Prepare Database</strong> ระบบจะทำ migration ผ่าน Web UI แบบ retry-safe โดยไม่ต้องให้ผู้ใช้รัน Artisan เอง
                    @if ($databaseReady && ! $failed)
                        <form id="prepare-database-form" class="mt-3" action="{{ route('installer.prepare-database', ['token' => $token]) }}" method="post">
                            <button id="prepare-database-button" class="btn btn-dark" type="submit"><span class="js-button-label">Prepare Database</span><span class="js-button-loading d-none"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังเตรียมฐานข้อมูล…</span></button>
                        </form>
                        <div id="prepare-database-progress" class="mt-3 d-none">
                            <div class="d-flex justify-content-between small text-secondary mb-1"><span id="prepare-database-stage">กำลังเริ่มต้น…</span><span id="prepare-database-percent">0%</span></div>
                            <div class="installer-progress" role="progressbar" aria-label="Prepare Database progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div id="prepare-database-progress-bar" class="installer-progress-bar"></div></div>
                        </div>
                        <div id="prepare-database-result" class="mt-3 d-none" role="alert"></div>
                    @endif
                @endif
            </div>
        </section>

        @if ($isLive)
            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                    <div>
                        <p class="text-uppercase text-success small fw-semibold mb-2">System is Live</p>
                        <h2 class="h4 mb-1">Implementation &amp; Configuration Center</h2>
                        <p class="text-secondary mb-0">ติดตั้งระบบเสร็จแล้ว การแก้ไขผ่าน Installer ถูกล็อกเพื่อป้องกันการเปลี่ยนข้อมูลสำคัญโดยไม่ตั้งใจ</p>
                    </div>
                    <span class="badge text-bg-success px-3 py-2">LIVE · 100%</span>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-4"><div class="border rounded-3 p-3"><div class="small text-secondary">Go Live Date</div><div class="fw-semibold">{{ $liveSession?->go_live_at?->format('d/m/Y H:i') ?? '-' }}</div></div></div>
                    <div class="col-12 col-md-4"><div class="border rounded-3 p-3"><div class="small text-secondary">Installation Session</div><div class="fw-semibold">#{{ $liveSession?->id ?? '-' }}</div></div></div>
                    <div class="col-12 col-md-4"><div class="border rounded-3 p-3"><div class="small text-secondary">สถานะ</div><div class="fw-semibold text-success">พร้อมใช้งานจริง</div></div></div>
                </div>
                <h3 class="h5 mb-3">Configuration Shortcuts</h3>
                <div class="row g-2">
                    @foreach ([
                        ['label' => 'Company / Global Settings', 'route' => 'settings.company.edit'],
                        ['label' => 'Branches', 'route' => 'settings.branches.index'],
                        ['label' => 'Warehouses', 'route' => 'settings.warehouses.index'],
                        ['label' => 'Users & Roles', 'route' => 'settings.users.index'],
                        ['label' => 'Document Numbering', 'route' => 'settings.document-sequences.index'],
                        ['label' => 'Accounting Mapping', 'route' => 'accounting.account-mappings.index'],
                        ['label' => 'Approval Workflow', 'route' => 'settings.workflow.index'],
                    ] as $shortcut)
                        @if (app('router')->has($shortcut['route']))
                            <div class="col-12 col-md-6 col-lg-4"><a class="btn btn-outline-primary w-100 text-start" href="{{ route($shortcut['route']) }}">{{ $shortcut['label'] }} <span class="float-end">→</span></a></div>
                        @endif
                    @endforeach
                </div>
                <div class="alert alert-warning mt-4 mb-0">การ Reset Installation, Default Data หรือ Database ไม่เปิดจากหน้านี้ ต้องดำเนินการโดย Super Admin ผ่านขั้นตอนที่มี Audit Log</div>
                <div class="border rounded-3 p-3 mt-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div><h3 class="h6 mb-1">System Default Updates</h3><div class="small text-secondary">ตรวจสอบเวอร์ชัน Permission, Role, Program และ Journal Book ที่ติดตั้งไว้</div></div>
                        @if ($availableUpdates)
                            <form action="{{ route('installer.apply-system-updates', ['token' => $token]) }}" method="post" onsubmit="return confirm('ยืนยัน Apply System Updates หรือไม่?')"><button class="btn btn-outline-primary" type="submit">Apply System Updates ({{ count($availableUpdates) }})</button></form>
                        @else
                            <span class="badge text-bg-success">เป็นเวอร์ชันล่าสุด</span>
                        @endif
                    </div>
                    @if ($availableUpdates)
                        <div class="small text-secondary mt-2">{{ collect($availableUpdates)->map(fn ($update) => $update['seed_code'].' '.$update['current'].' → '.$update['latest'])->join(' · ') }}</div>
                    @endif
                </div>
                <details class="border rounded-3 p-3 mt-3">
                    <summary class="fw-semibold text-danger">Dangerous Operations · Super Admin only</summary>
                    <p class="small text-secondary mt-2 mb-3">การดำเนินการนี้ไม่ลบข้อมูลธุรกรรม จะลบเฉพาะตัวบ่งชี้เวอร์ชันเพื่อให้ระบบติดตั้ง Default ใหม่ได้อีกครั้งเท่านั้น</p>
                    <form action="{{ route('installer.reset-default-version-markers', ['token' => $token]) }}" method="post" class="row g-2">
                        <div class="col-12 col-md-4"><input name="admin_username" class="form-control" placeholder="Super Admin username" autocomplete="username" required></div>
                        <div class="col-12 col-md-4"><input name="admin_password" type="password" class="form-control" placeholder="Super Admin password" autocomplete="current-password" required></div>
                        <div class="col-12 col-md-4"><input name="confirmation" class="form-control" placeholder="พิมพ์ RESET DEFAULT VERSIONS" required></div>
                        <div class="col-12"><button class="btn btn-outline-danger" type="submit" onclick="return confirm('ยืนยันการรีเซ็ต Default Version Markers หรือไม่? ข้อมูลธุรกรรมจะไม่ถูกลบ')">Reset Default Version Markers</button></div>
                    </form>
                </details>
            </section>
        @elseif ($databasePrepared && $defaultsInitialized)
            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                    <div>
                        <h2 class="h4 mb-1">2. Customer Configuration</h2>
                        <p class="text-secondary mb-0">กรอกเฉพาะข้อมูลที่แตกต่างกันของลูกค้า ระบบจะตั้งค่าเริ่มต้นที่ปลอดภัยให้แล้ว</p>
                    </div>
                    <span class="badge text-bg-primary px-3 py-2">ทำต่อได้ทุกครั้ง</span>
                </div>

                <div class="row g-4">
                    <div class="col-12 col-lg-6">
                        <div class="border rounded-4 p-4 h-100">
                            <h3 class="h5">Company Information</h3>
                            <form action="{{ route('installer.company', ['token' => $token]) }}" method="post" class="row g-3">
                                <div class="col-12"><label class="form-label">ชื่อบริษัท <span class="text-danger">*</span></label><input name="company_name" required class="form-control" value="{{ old('company_name', $company?->company_name) }}"></div>
                                <div class="col-12"><label class="form-label">ที่อยู่</label><textarea name="company_address" class="form-control" rows="2">{{ old('company_address', $company?->company_address) }}</textarea></div>
                                <div class="col-md-6"><label class="form-label">เลขประจำตัวผู้เสียภาษี</label><input name="tax_id" class="form-control" value="{{ old('tax_id', $company?->tax_id) }}"></div>
                                <div class="col-md-6"><label class="form-label">สกุลเงิน</label><input name="base_currency" maxlength="3" class="form-control" value="{{ old('base_currency', $company?->base_currency ?? 'THB') }}"></div>
                                <div class="col-md-6"><label class="form-label">ภาษา</label><select name="locale" class="form-select"><option value="th" @selected(($company?->locale ?? 'th') === 'th')>ไทย</option><option value="en" @selected($company?->locale === 'en')>English</option></select></div>
                                <div class="col-md-6"><label class="form-label">Timezone</label><select name="timezone" class="form-select"><option value="Asia/Bangkok" @selected(($company?->timezone ?? 'Asia/Bangkok') === 'Asia/Bangkok')>Asia/Bangkok</option><option value="UTC" @selected($company?->timezone === 'UTC')>UTC</option></select></div>
                                <div class="col-12"><button class="btn btn-dark" type="submit">บันทึกข้อมูลบริษัท</button></div>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="border rounded-4 p-4 h-100">
                            <h3 class="h5">Modules</h3>
                            <p class="small text-secondary">Dashboard และ Global Setting เปิดไว้เสมอเพื่อให้บริหารระบบได้</p>
                            <form action="{{ route('installer.modules', ['token' => $token]) }}" method="post">
                                <div class="row g-2 mb-3">
                                    @foreach ($programs as $program)
                                        <div class="col-12 col-sm-6"><label class="form-check border rounded-3 p-2"><input class="form-check-input ms-0 me-2" type="checkbox" name="module_codes[]" value="{{ $program->code }}" @checked($program->is_enabled) @disabled(in_array($program->code, ['dashboard', 'settings'], true))><span>{{ $program->name }}</span></label></div>
                                    @endforeach
                                </div>
                                <button class="btn btn-dark" type="submit">บันทึก Modules</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="border rounded-4 p-4 h-100">
                            <h3 class="h5">Default Organization</h3>
                            <p class="text-secondary small">ระบบจะสร้างข้อมูลมาตรฐานให้แบบ idempotent</p>
                            @if ($defaultBranch && $defaultWarehouse)
                                <div class="alert alert-success py-2">สำนักงานใหญ่ {{ $defaultBranch->code }} · {{ $defaultBranch->name }}<br>คลังหลัก {{ $defaultWarehouse->code }} · {{ $defaultWarehouse->name }}</div>
                            @endif
                            <form action="{{ route('installer.organization', ['token' => $token]) }}" method="post"><button class="btn btn-outline-dark" type="submit">สร้าง/ตรวจสอบ Organization</button></form>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <div class="border rounded-4 p-4 h-100">
                            <h3 class="h5">Administrator</h3>
                            @if ($administrator)
                                <div class="alert alert-success py-2 mb-0">พร้อมใช้งาน: {{ $administrator->name }} ({{ $administrator->username }})</div>
                            @else
                                <form action="{{ route('installer.administrator', ['token' => $token]) }}" method="post" class="row g-3">
                                    <div class="col-12"><input name="name" required class="form-control" placeholder="ชื่อผู้ดูแลระบบ"></div>
                                    <div class="col-md-6"><input name="username" required class="form-control" placeholder="Username"></div>
                                    <div class="col-md-6"><input name="email" type="email" required class="form-control" placeholder="Email"></div>
                                    <div class="col-md-6"><input name="password" type="password" required minlength="8" class="form-control" placeholder="รหัสผ่านอย่างน้อย 8 ตัวอักษร"></div>
                                    <div class="col-md-6"><input name="password_confirmation" type="password" required minlength="8" class="form-control" placeholder="ยืนยันรหัสผ่าน"></div>
                                    <div class="col-12"><button class="btn btn-dark" type="submit">สร้าง Administrator</button></div>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                    <div>
                        <h2 class="h4 mb-1">3. Review & Validation</h2>
                        <p class="text-secondary mb-0">ตรวจสอบความพร้อมก่อนนำระบบไปใช้งานจริง</p>
                    </div>
                    <form action="{{ route('installer.validate', ['token' => $token]) }}" method="post"><button class="btn btn-dark" type="submit">ตรวจสอบ Setup</button></form>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3"><div class="border rounded-3 p-3"><div class="small text-secondary">Modules</div><div class="h4 mb-0">{{ $programs->where('is_enabled', true)->count() }}</div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded-3 p-3"><div class="small text-secondary">Default Data</div><div class="h4 mb-0 text-success">พร้อม</div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded-3 p-3"><div class="small text-secondary">Branch</div><div class="h4 mb-0">{{ $defaultBranch ? '1' : '0' }}</div></div></div>
                    <div class="col-6 col-md-3"><div class="border rounded-3 p-3"><div class="small text-secondary">Warehouse</div><div class="h4 mb-0">{{ $defaultWarehouse ? '1' : '0' }}</div></div></div>
                </div>

                @if ($validation)
                    <div class="alert {{ $validation['can_go_live'] ? 'alert-success' : 'alert-warning' }} d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <span>Required ผ่าน {{ $validation['required_passed'] }} / {{ $validation['required_total'] }} รายการ</span>
                        @if ($validation['can_go_live'])
                            <form action="{{ route('installer.go-live', ['token' => $token]) }}" method="post" onsubmit="return confirm('ยืนยันเปิดระบบใช้งานจริงและล็อก Installer หรือไม่?')"><button class="btn btn-success" type="submit">ยืนยัน Go Live</button></form>
                        @endif
                    </div>
                    <div class="row g-2">
                        @foreach ($validation['checks'] as $check)
                            <div class="col-12 col-md-6"><div class="border rounded-3 p-3 d-flex justify-content-between gap-3"><div><div class="fw-semibold">{{ $check['label'] }}</div><div class="small text-secondary">{{ $check['type'] }} · {{ $check['status'] === 'pass' ? 'ผ่าน' : $check['fix'] }}</div>@if($check['status'] !== 'pass' && app('router')->has($check['route']))<a class="btn btn-sm btn-outline-primary mt-2" href="{{ route($check['route'], $check['route'] === 'installer.index' ? ['token' => $token] : []) }}">ไปแก้ไขการตั้งค่า</a>@endif</div><span class="badge {{ $check['status'] === 'pass' ? 'text-bg-success' : ($check['status'] === 'warning' ? 'text-bg-warning' : 'text-bg-danger') }} align-self-start">{{ $check['status'] === 'pass' ? 'ผ่าน' : 'ต้องตรวจสอบ' }}</span></div></div>
                        @endforeach
                    </div>
                @else
                    <div class="alert alert-info mb-0">กด “ตรวจสอบ Setup” เพื่อสร้าง Required / Recommended / Optional Checklist</div>
                @endif
            </section>

            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <h2 class="h4 mb-1">4. Initial Data Import</h2>
                <p class="text-secondary">เริ่มจากยอดยกมาสินค้า ระบบจะตรวจสอบและสร้างเป็นเอกสารร่างให้ตรวจสอบก่อน Post</p>
                <form action="{{ route('installer.import.opening-balance', ['token' => $token]) }}" method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-12 col-md-3"><label class="form-label">วันที่เริ่มต้น</label><input type="date" name="cutover_date" class="form-control" value="{{ now()->toDateString() }}" required></div>
                    <div class="col-12 col-md-2"><label class="form-label">วิธีต้นทุน</label><select name="costing_method" class="form-select"><option value="AVG">AVG</option><option value="FIFO">FIFO</option></select></div>
                    <div class="col-12 col-md-3"><label class="form-label">เลขอ้างอิง</label><input name="source_reference" class="form-control" maxlength="100"></div>
                    <div class="col-12 col-md-4"><label class="form-label">ไฟล์ Template (.xlsx)</label><input type="file" name="file" accept=".xlsx" class="form-control" required></div>
                    <div class="col-12 d-flex justify-content-between align-items-center gap-3"><small class="text-secondary">ใช้ Template เดียวกับ WMS Opening Balance และต้องมี Sheet Opening Balance กับ _meta</small><button class="btn btn-dark" type="submit">อัปโหลดและตรวจสอบ</button></div>
                </form>
                @if ($openingImport)
                    <div class="border rounded-3 p-3 mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div><strong>{{ $openingImport->original_filename }}</strong><div class="small text-secondary">ผ่าน {{ $openingImport->valid_rows }} · ผิดพลาด {{ $openingImport->error_rows }} · สถานะ {{ $openingImport->status }}</div></div>
                        @if ($openingImport->status === 'VALIDATED' && (int) $openingImport->error_rows === 0)
                            <form action="{{ route('installer.import.opening-balance.commit', ['token' => $token]) }}" method="post"><button class="btn btn-outline-success" type="submit">ยืนยันและสร้างเอกสารร่าง</button></form>
                        @else
                            <span class="badge text-bg-warning">แก้ไขไฟล์แล้วอัปโหลดใหม่</span> @if($openingImport->error_rows)<a class="btn btn-sm btn-outline-danger" href="{{ route('installer.import.errors', ['token' => $token, 'type' => 'WMS_OPENING_BALANCE']) }}">ดาวน์โหลด Error</a>@endif
                        @endif
                    </div>
                @endif
            </section>

            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <h2 class="h4 mb-1">5. Customers & Suppliers</h2>
                <p class="text-secondary">นำเข้าข้อมูลคู่ค้าจาก CSV โดยตรวจสอบก่อน และยืนยันเป็นชุดเดียวแบบปลอดภัย</p>
                <form action="{{ route('installer.import.party', ['token' => $token]) }}" method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-12 col-md-3"><label class="form-label">ประเภทข้อมูล</label><select name="party_type" class="form-select"><option value="CUSTOMER">Customer</option><option value="SUPPLIER">Supplier</option></select></div>
                    <div class="col-12 col-md-5"><label class="form-label">ไฟล์ CSV</label><input type="file" name="file" accept=".csv,.txt" class="form-control" required></div>
                    <div class="col-12 col-md-4"><button class="btn btn-dark" type="submit">อัปโหลดและตรวจสอบ</button></div>
                </form>
                <div class="small text-secondary mt-3">หัวตารางที่รองรับ: code,name,type,tax_id,branch_code,contact_name,phone,email,address</div>
                @if ($partyImport)
                    <div class="border rounded-3 p-3 mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div><strong>{{ $partyImport->original_filename }}</strong><div class="small text-secondary">{{ str_ends_with($partyImport->type, '_CUSTOMER') ? 'Customer' : 'Supplier' }} · ผ่าน {{ $partyImport->valid_rows }} · ผิดพลาด {{ $partyImport->error_rows }} · {{ $partyImport->status }}</div></div>
                        @if ($partyImport->status === 'VALIDATED' && (int) $partyImport->error_rows === 0)
                            <form action="{{ route('installer.import.party.commit', ['token' => $token]) }}" method="post"><button class="btn btn-outline-success" type="submit">ยืนยันนำเข้าคู่ค้า</button></form>
                        @else
                            <span class="badge text-bg-warning">แก้ไขไฟล์แล้วอัปโหลดใหม่</span> @if($partyImport->error_rows)<a class="btn btn-sm btn-outline-danger" href="{{ route('installer.import.errors', ['token' => $token, 'type' => $partyImport->type]) }}">ดาวน์โหลด Error</a>@endif
                        @endif
                    </div>
                @endif
            </section>

            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <h2 class="h4 mb-1">6. Products / Items</h2>
                <p class="text-secondary">นำเข้าสินค้าหลังจากสร้าง Category, UOM และ Account Mapping ที่จำเป็นแล้ว</p>
                <form action="{{ route('installer.import.items', ['token' => $token]) }}" method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-12 col-md-8"><label class="form-label">ไฟล์สินค้า CSV</label><input type="file" name="file" accept=".csv,.txt" class="form-control" required></div>
                    <div class="col-12 col-md-4"><button class="btn btn-dark" type="submit">อัปโหลดและตรวจสอบ</button></div>
                </form>
                <div class="small text-secondary mt-3">หัวตาราง: code,name,item_type,category_code,base_uom_code,is_stock_item,inventory_account_code,sales_account_code,cogs_account_code</div>
                @if ($itemImport)
                    <div class="border rounded-3 p-3 mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div><strong>{{ $itemImport->original_filename }}</strong><div class="small text-secondary">ผ่าน {{ $itemImport->valid_rows }} · ผิดพลาด {{ $itemImport->error_rows }} · {{ $itemImport->status }}</div></div>
                        @if ($itemImport->status === 'VALIDATED' && (int) $itemImport->error_rows === 0)
                            <form action="{{ route('installer.import.items.commit', ['token' => $token]) }}" method="post"><button class="btn btn-outline-success" type="submit">ยืนยันนำเข้าสินค้า</button></form>
                        @else
                            <span class="badge text-bg-warning">แก้ไข Master Data หรือไฟล์แล้วอัปโหลดใหม่</span> @if($itemImport->error_rows)<a class="btn btn-sm btn-outline-danger" href="{{ route('installer.import.errors', ['token' => $token, 'type' => 'INSTALLER_ITEMS']) }}">ดาวน์โหลด Error</a>@endif
                        @endif
                    </div>
                @endif
            </section>

            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <h2 class="h4 mb-1">7. Employees / Users</h2>
                <p class="text-secondary">นำเข้าพนักงานพร้อมบัญชีผู้ใช้ โดยกำหนดสิทธิ์เริ่มต้นเป็น Viewer</p>
                <form action="{{ route('installer.import.employees', ['token' => $token]) }}" method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-12 col-md-8"><label class="form-label">ไฟล์พนักงาน CSV</label><input type="file" name="file" accept=".csv,.txt" class="form-control" required></div>
                    <div class="col-12 col-md-4"><button class="btn btn-dark" type="submit">อัปโหลดและตรวจสอบ</button></div>
                </form>
                <div class="small text-secondary mt-3">หัวตาราง: employee_code,name,username,email,password · รหัสผ่านต้องไม่น้อยกว่า 8 ตัวอักษร</div>
                @if ($employeeImport)
                    <div class="border rounded-3 p-3 mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div><strong>{{ $employeeImport->original_filename }}</strong><div class="small text-secondary">ผ่าน {{ $employeeImport->valid_rows }} · ผิดพลาด {{ $employeeImport->error_rows }} · {{ $employeeImport->status }}</div></div>
                        @if ($employeeImport->status === 'VALIDATED' && (int) $employeeImport->error_rows === 0)
                            <form action="{{ route('installer.import.employees.commit', ['token' => $token]) }}" method="post"><button class="btn btn-outline-success" type="submit">ยืนยันนำเข้าพนักงาน</button></form>
                        @else
                            <span class="badge text-bg-warning">แก้ไขไฟล์แล้วอัปโหลดใหม่</span> @if($employeeImport->error_rows)<a class="btn btn-sm btn-outline-danger" href="{{ route('installer.import.errors', ['token' => $token, 'type' => 'INSTALLER_EMPLOYEES']) }}">ดาวน์โหลด Error</a>@endif
                        @endif
                    </div>
                @endif
            </section>

            <section class="setup-card bg-white p-4 p-md-5 mt-4">
                <h2 class="h4 mb-1">8. Opening AR / AP</h2>
                <p class="text-secondary">นำเข้ายอดลูกหนี้/เจ้าหนี้โดยสร้าง Journal และ Open Item ให้สัมพันธ์กันอัตโนมัติ</p>
                <form action="{{ route('installer.import.open-items', ['token' => $token]) }}" method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                    <div class="col-12 col-md-8"><label class="form-label">ไฟล์ Opening AR/AP CSV</label><input type="file" name="file" accept=".csv,.txt" class="form-control" required></div>
                    <div class="col-12 col-md-4"><button class="btn btn-dark" type="submit">อัปโหลดและตรวจสอบ</button></div>
                </form>
                <div class="small text-secondary mt-3">หัวตาราง: ledger_type,party_code,warehouse_code,account_code,document_number,document_date,posting_date,due_date,amount,offset_account_code</div>
                @if ($openItemImport)
                    <div class="border rounded-3 p-3 mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div><strong>{{ $openItemImport->original_filename }}</strong><div class="small text-secondary">ผ่าน {{ $openItemImport->valid_rows }} · ผิดพลาด {{ $openItemImport->error_rows }} · {{ $openItemImport->status }}</div></div>
                        @if ($openItemImport->status === 'VALIDATED' && (int) $openItemImport->error_rows === 0)
                            <form action="{{ route('installer.import.open-items.commit', ['token' => $token]) }}" method="post"><button class="btn btn-outline-success" type="submit">ยืนยันและ Post Opening AR/AP</button></form>
                        @else
                            <span class="badge text-bg-warning">แก้ไข Master Data หรือไฟล์แล้วอัปโหลดใหม่</span> @if($openItemImport->error_rows)<a class="btn btn-sm btn-outline-danger" href="{{ route('installer.import.errors', ['token' => $token, 'type' => 'INSTALLER_OPEN_ITEMS']) }}">ดาวน์โหลด Error</a>@endif
                        @endif
                    </div>
                @endif
            </section>
        @endif
    @endif
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('prepare-database-form');
    if (!form) return;
    const button = document.getElementById('prepare-database-button');
    const label = form.querySelector('.js-button-label');
    const loading = form.querySelector('.js-button-loading');
    const result = document.getElementById('prepare-database-result');
    const progress = document.getElementById('prepare-database-progress');
    const progressBar = document.getElementById('prepare-database-progress-bar');
    const progressPercent = document.getElementById('prepare-database-percent');
    const progressStage = document.getElementById('prepare-database-stage');
    const progressTrack = progress.querySelector('[role="progressbar"]');
    let progressTimer;
    let progressValue = 0;
    const stages = [[8, 'กำลังตรวจสอบคำขอ…'], [25, 'กำลังตรวจสอบการเชื่อมต่อ…'], [55, 'กำลังทำ Migration…'], [78, 'กำลังตรวจสอบตารางระบบ…'], [92, 'กำลังสรุปผล…']];
    function setProgress(value, stage) {
        progressValue = Math.max(progressValue, value);
        progressBar.style.width = progressValue + '%';
        progressPercent.textContent = progressValue + '%';
        progressStage.textContent = stage;
        progressTrack.setAttribute('aria-valuenow', progressValue);
    }
    function startProgress() {
        progress.classList.remove('d-none');
        let index = 0;
        setProgress(stages[0][0], stages[0][1]);
        progressTimer = window.setInterval(function () {
            if (index < stages.length - 1) {
                index += 1;
                setProgress(stages[index][0], stages[index][1]);
            }
        }, 900);
    }
    function stopProgress() { if (progressTimer) window.clearInterval(progressTimer); }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        button.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');
        result.className = 'mt-3 d-none';
        startProgress();
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const payload = await response.json();
            if (!response.ok || payload.status !== 'success') {
                stopProgress();
                progressStage.textContent = 'Prepare Database ไม่สำเร็จ';
                result.className = 'alert alert-danger mt-3';
                result.innerHTML = '<strong>เตรียมฐานข้อมูลไม่สำเร็จ</strong><div class="mt-1">' + (payload.message || 'เกิดข้อผิดพลาด') + '</div>' + (payload.error ? '<details class="mt-3" open><summary class="fw-semibold">SQL / Technical Error สำหรับ Developer</summary><pre class="small text-wrap bg-dark text-light rounded p-3 mt-2 mb-0">' + String(payload.error).replace(/[&<>]/g, function (char) { return {'&':'&amp;','<':'&lt;','>':'&gt;'}[char]; }) + '</pre></details>' : '');
                return;
            }
            stopProgress();
            setProgress(100, 'เตรียมฐานข้อมูลสำเร็จ');
            result.className = 'alert alert-success mt-3';
            result.textContent = payload.message || 'เตรียมฐานข้อมูลสำเร็จแล้ว กำลังโหลดขั้นตอนถัดไป…';
            window.setTimeout(function () { window.location.reload(); }, 700);
        } catch (error) {
            stopProgress();
            progressStage.textContent = 'หยุดการทำงานเนื่องจากไม่สามารถรับผลลัพธ์จาก Server ได้';
            result.className = 'alert alert-danger mt-3';
            result.textContent = 'ไม่สามารถรับผลลัพธ์จาก Installer ได้ กรุณาตรวจสอบ Server Log แล้วลองใหม่';
        } finally {
            stopProgress();
            button.disabled = false;
            label.classList.remove('d-none');
            loading.classList.add('d-none');
        }
    });
});
</script>
</body>
</html>
