<?php

namespace App\Modules\Installer\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Program;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Installer\Services\DatabasePreparationService;
use App\Modules\Installer\Services\CustomerSetupService;
use App\Modules\Installer\Services\InstallerStateStore;
use App\Modules\Installer\Services\InstallationValidationService;
use App\Modules\Installer\Services\PartyImportService;
use App\Modules\Installer\Services\ItemImportService;
use App\Modules\Installer\Services\EmployeeImportService;
use App\Modules\Installer\Services\OpenItemImportService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Services\SpreadsheetService;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Wms\Services\OpeningBalanceImportService;
use App\Modules\Wms\Services\OpeningBalanceService;
use App\Modules\Wms\Support\OpeningBalanceTemplate;
use App\Modules\Installer\Services\SystemDefaultOrchestrator;
use App\Modules\Installer\Services\GoLiveService;
use App\Modules\Installer\Models\InstallationSession;
use Illuminate\Database\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SetupController extends Controller
{
    public function index(Request $request): View
    {
        return $this->render($request);
    }

    public function prepareDatabase(Request $request, DatabasePreparationService $preparation): View|JsonResponse
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();

        $result = $preparation->prepare();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'],
                'error' => $result['error'],
            ], $result['status'] === 'success' ? 200 : 422);
        }

        return $this->render($request, $result['message'].($result['error'] ? ' รายละเอียด: '.$result['error'] : ''));
    }

    public function initializeDefaults(Request $request, SystemDefaultOrchestrator $orchestrator): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();

        if (! $this->hasInstallerTables()) {
            return $this->render($request, 'กรุณาเตรียมฐานข้อมูลก่อนติดตั้ง System Defaults');
        }

        $result = $orchestrator->run();

        return $this->render($request, $result['message']);
    }

    public function saveCompany(Request $request, CustomerSetupService $setup): View
    {
        return $this->runCustomerAction($request, function () use ($request, $setup): string {
            $data = Validator::make($request->all(), [
                'company_name' => ['required', 'string', 'max:255'],
                'company_address' => ['nullable', 'string', 'max:1000'],
                'tax_id' => ['nullable', 'string', 'max:30'],
                'locale' => ['required', 'in:th,en'],
                'timezone' => ['required', 'timezone'],
                'base_currency' => ['required', 'string', 'size:3'],
            ])->validate();
            $setup->saveCompany($data);

            return 'บันทึกข้อมูลบริษัทสำเร็จแล้ว';
        });
    }

    public function selectModules(Request $request, CustomerSetupService $setup): View
    {
        return $this->runCustomerAction($request, function () use ($request, $setup): string {
            $data = Validator::make($request->all(), ['module_codes' => ['array'], 'module_codes.*' => ['string', 'max:50']])->validate();
            $setup->selectModules($data['module_codes'] ?? []);

            return 'บันทึก Module ที่เลือกสำเร็จแล้ว';
        });
    }

    public function ensureOrganization(Request $request, CustomerSetupService $setup): View
    {
        return $this->runCustomerAction($request, function () use ($setup): string {
            $setup->ensureDefaultOrganization();

            return 'สร้างสำนักงานใหญ่และคลังหลักสำเร็จแล้ว';
        });
    }

    public function createAdministrator(Request $request, CustomerSetupService $setup): View
    {
        return $this->runCustomerAction($request, function () use ($request, $setup): string {
            $data = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ])->validate();
            $setup->createAdministrator($data);

            return 'สร้าง Administrator สำเร็จแล้ว';
        });
    }

    public function validateSetup(Request $request, InstallationValidationService $validation): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();

        if (! $this->hasInstallerTables()) {
            return $this->render($request, 'กรุณาเตรียมฐานข้อมูลก่อนตรวจสอบ Setup');
        }

        try {
            $result = $validation->run();

            return $this->render($request, $result['can_go_live'] ? 'Validation ผ่านครบทุก Required Checklist' : 'Validation พบรายการที่ต้องแก้ไขก่อน Go Live', $result);
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ไม่สามารถตรวจสอบ Setup ได้ กรุณาตรวจสอบรายละเอียดสำหรับ Developer แล้วลองใหม่: '.$this->safeExceptionDetail($exception));
        }
    }

    public function goLive(Request $request, InstallationValidationService $validation, GoLiveService $goLive): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();

        try {
            $result = $validation->run();
            if (! $result['can_go_live']) {
                return $this->render($request, 'ยังไม่สามารถ Go Live ได้ กรุณาแก้ Required Checklist ให้ครบก่อน', $result);
            }

            $adminId = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->value('id');
            $session = $goLive->execute($adminId ? (int) $adminId : null, (string) $request->ip(), [
                'required_passed' => $result['required_passed'],
                'required_total' => $result['required_total'],
                'company' => CompanySetting::query()->first()?->company_name,
                'modules' => Program::query()->where('is_enabled', true)->pluck('code')->values()->all(),
            ]);

            app(InstallerStateStore::class)->write([
                ...app(InstallerStateStore::class)->read(),
                'status' => 'LIVE',
                'go_live_at' => $session->go_live_at?->toIso8601String(),
                'go_live_by' => $adminId,
            ]);

            return $this->render($request, 'เปิดระบบใช้งานจริงเรียบร้อยแล้ว ระบบ Installer ถูกล็อกและเปลี่ยนเป็น Configuration Center', $result);
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ไม่สามารถเปิดระบบใช้งานจริงได้ กรุณาตรวจสอบ Required Checklist แล้วลองใหม่');
        }
    }

    public function applySystemUpdates(Request $request, SystemDefaultOrchestrator $orchestrator): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        abort_unless($this->hasInstallerTables(), 409, 'กรุณา Prepare Database ก่อนใช้ System Update');

        try {
            $updates = $orchestrator->availableUpdates();
            if ($updates === []) {
                return $this->render($request, 'System Defaults เป็นเวอร์ชันล่าสุดแล้ว');
            }

            $orchestrator->run();
            $session = InstallationSession::query()->latest('id')->first();
            $session?->forceFill(['status' => 'LIVE', 'progress' => 100, 'completed_at' => $session->completed_at ?? now(), 'go_live_at' => $session->go_live_at ?? now()])->save();
            DB::table('installation_logs')->insert([
                'installation_session_id' => $session?->id,
                'step_code' => 'system-updates',
                'action' => 'APPLY_SYSTEM_UPDATES',
                'old_value' => json_encode($updates),
                'new_value' => json_encode(['status' => 'LIVE']),
                'status' => 'SUCCESS',
                'user_id' => User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->value('id'),
                'ip_address' => (string) $request->ip(),
                'created_at' => now(),
            ]);

            app(InstallerStateStore::class)->write([...app(InstallerStateStore::class)->read(), 'status' => 'LIVE']);

            return $this->render($request, 'อัปเดต System Defaults เรียบร้อยแล้ว และระบบยังอยู่ในสถานะ LIVE');
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ไม่สามารถอัปเดต System Defaults ได้ กรุณาลองใหม่');
        }
    }

    public function resetDefaultVersionMarkers(Request $request): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        abort_unless($this->installerIsLive(), 409, 'การกู้คืน Default Version ใช้ได้หลังระบบเป็น LIVE เท่านั้น');

        try {
            $data = Validator::make($request->all(), [
                'admin_username' => ['required', 'string', 'max:100'],
                'admin_password' => ['required', 'string'],
                'confirmation' => ['required', 'in:RESET DEFAULT VERSIONS'],
            ])->validate();
            $admin = User::query()->where('username', $data['admin_username'])->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin')->where('roles.is_active', true))->first();
            abort_unless($admin && Hash::check($data['admin_password'], (string) $admin->password), 403, 'ยืนยันตัวตน Super Admin ไม่สำเร็จ');

            $deleted = DB::table('system_seed_versions')->delete();
            $session = InstallationSession::query()->latest('id')->first();
            DB::table('installation_logs')->insert([
                'installation_session_id' => $session?->id,
                'step_code' => 'system-updates',
                'action' => 'RESET_DEFAULT_VERSION_MARKERS',
                'old_value' => json_encode(['marker_count' => $deleted]),
                'new_value' => json_encode(['status' => 'LIVE', 'requires_apply' => true]),
                'status' => 'SUCCESS',
                'user_id' => $admin->id,
                'ip_address' => (string) $request->ip(),
                'created_at' => now(),
            ]);

            return $this->render($request, 'รีเซ็ตตัวบ่งชี้ Default Version แล้ว ข้อมูลธุรกรรมไม่ถูกลบ กรุณา Apply System Updates ต่อ');
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ไม่สามารถรีเซ็ต Default Version ได้ กรุณาตรวจสอบสิทธิ์และข้อความยืนยัน');
        }
    }

    public function importOpeningBalance(Request $request, OpeningBalanceImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();

        try {
            $data = Validator::make($request->all(), [
                'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
                'cutover_date' => ['required', 'date'],
                'costing_method' => ['required', 'in:AVG,FIFO'],
                'source_reference' => ['nullable', 'string', 'max:100'],
            ])->validate();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $batch = $imports->stage($request->file('file'), $admin, $data);

            return $this->render($request, 'ตรวจสอบไฟล์ยอดยกมาแล้ว: ผ่าน '.$batch->valid_rows.' รายการ ผิดพลาด '.$batch->error_rows.' รายการ');
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ไม่สามารถตรวจสอบไฟล์ยอดยกมาได้ กรุณาใช้ Template ที่ถูกต้องและลองใหม่');
        }
    }

    public function commitOpeningBalance(Request $request, OpeningBalanceImportService $imports, OpeningBalanceService $openingBalances): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);

        try {
            $batch = MigrationImportBatch::query()->where('type', OpeningBalanceTemplate::TYPE)->latest('id')->firstOrFail();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $created = $imports->commit($batch, $admin, $openingBalances);

            return $this->render($request, 'สร้างยอดยกมาเป็นเอกสารร่างแล้ว '.count($created).' คลัง กรุณาตรวจสอบและ Post ตามขั้นตอน WMS');
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ยังไม่สามารถยืนยันยอดยกมาได้ กรุณาตรวจสอบไฟล์และรายการที่ผิดพลาด');
        }
    }

    public function importParty(Request $request, PartyImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $data = Validator::make($request->all(), ['party_type' => ['required', 'in:CUSTOMER,SUPPLIER'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']])->validate();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $batch = $imports->stage($request->file('file'), $data['party_type'], $admin);
            return $this->render($request, 'ตรวจสอบไฟล์ '.($data['party_type'] === 'CUSTOMER' ? 'ลูกค้า' : 'Supplier').' แล้ว: ผ่าน '.$batch->valid_rows.' รายการ ผิดพลาด '.$batch->error_rows.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ไม่สามารถตรวจสอบไฟล์คู่ค้าได้ กรุณาตรวจสอบหัวตาราง CSV และลองใหม่');
        }
    }

    public function commitParty(Request $request, PartyImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $batch = MigrationImportBatch::query()->whereIn('type', ['INSTALLER_PARTY_CUSTOMER', 'INSTALLER_PARTY_SUPPLIER'])->latest('id')->firstOrFail();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $count = $imports->commit($batch, $admin);
            return $this->render($request, 'นำเข้าคู่ค้าเรียบร้อยแล้ว '.$count.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ยังไม่สามารถยืนยันไฟล์คู่ค้าได้ กรุณาตรวจสอบรายการผิดพลาด');
        }
    }

    public function importItems(Request $request, ItemImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $data = Validator::make($request->all(), ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']])->validate();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $batch = $imports->stage($request->file('file'), $admin);
            return $this->render($request, 'ตรวจสอบไฟล์สินค้าแล้ว: ผ่าน '.$batch->valid_rows.' รายการ ผิดพลาด '.$batch->error_rows.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ไม่สามารถตรวจสอบไฟล์สินค้าได้ กรุณาตรวจสอบหัวตารางและ Master Data ก่อนลองใหม่');
        }
    }

    public function commitItems(Request $request, ItemImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $batch = MigrationImportBatch::query()->where('type', 'INSTALLER_ITEMS')->latest('id')->firstOrFail();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $count = $imports->commit($batch, $admin);
            return $this->render($request, 'นำเข้าสินค้าเรียบร้อยแล้ว '.$count.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ยังไม่สามารถยืนยันไฟล์สินค้าได้ กรุณาตรวจสอบรายการผิดพลาด');
        }
    }

    public function importEmployees(Request $request, EmployeeImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $data = Validator::make($request->all(), ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']])->validate();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $batch = $imports->stage($request->file('file'), $admin);
            return $this->render($request, 'ตรวจสอบไฟล์พนักงานแล้ว: ผ่าน '.$batch->valid_rows.' รายการ ผิดพลาด '.$batch->error_rows.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ไม่สามารถตรวจสอบไฟล์พนักงานได้ กรุณาตรวจสอบหัวตารางและลองใหม่');
        }
    }

    public function commitEmployees(Request $request, EmployeeImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $batch = MigrationImportBatch::query()->where('type', 'INSTALLER_EMPLOYEES')->latest('id')->firstOrFail();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $count = $imports->commit($batch, $admin);
            return $this->render($request, 'นำเข้าพนักงานเรียบร้อยแล้ว '.$count.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ยังไม่สามารถยืนยันไฟล์พนักงานได้ กรุณาตรวจสอบรายการผิดพลาด');
        }
    }

    public function importOpenItems(Request $request, OpenItemImportService $imports): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $data = Validator::make($request->all(), ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']])->validate();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $batch = $imports->stage($request->file('file'), $admin);
            return $this->render($request, 'ตรวจสอบไฟล์ Opening AR/AP แล้ว: ผ่าน '.$batch->valid_rows.' รายการ ผิดพลาด '.$batch->error_rows.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ไม่สามารถตรวจสอบไฟล์ Opening AR/AP ได้ กรุณาตรวจสอบ Master Data และหัวตาราง');
        }
    }

    public function commitOpenItems(Request $request, OpenItemImportService $imports, JournalPostingService $posting, OpenItemService $openItems): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();
        try {
            $batch = MigrationImportBatch::query()->where('type', 'INSTALLER_OPEN_ITEMS')->latest('id')->firstOrFail();
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->firstOrFail();
            $count = $imports->commit($batch, $admin, $posting, $openItems);
            return $this->render($request, 'นำเข้า Opening AR/AP และสร้าง Open Item เรียบร้อยแล้ว '.$count.' รายการ');
        } catch (Throwable $exception) {
            report($exception);
            return $this->render($request, 'ยังไม่สามารถยืนยัน Opening AR/AP ได้ กรุณาตรวจสอบงวดบัญชี สมุดบัญชี และ Account Mapping');
        }
    }

    public function importErrors(Request $request, SpreadsheetService $spreadsheets): BinaryFileResponse
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $type = $request->string('type')->toString();
        abort_unless(in_array($type, ['INSTALLER_PARTY_CUSTOMER', 'INSTALLER_PARTY_SUPPLIER', 'INSTALLER_ITEMS', 'INSTALLER_EMPLOYEES', 'INSTALLER_OPEN_ITEMS', 'WMS_OPENING_BALANCE'], true), 404);
        $batch = MigrationImportBatch::query()->where('type', $type)->latest('id')->firstOrFail();
        $rows = collect($batch->staged_rows)->filter(fn (array $row) => $row['errors'] !== [])->map(fn (array $row) => [$row['row_number'], implode(' | ', $row['errors']), json_encode($row['source'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->values()->all();

        return $spreadsheets->download('installer-errors-'.$batch->id.'.xlsx', [['title' => 'Errors', 'headings' => ['row_number', 'errors', 'source'], 'rows' => $rows]]);
    }

    private function render(Request $request, ?string $notice = null, ?array $validation = null): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);

        $authorized = $this->authorized($request);
        $state = $authorized ? app(InstallerStateStore::class)->read() : [];
        $databasePrepared = $authorized && $this->hasInstallerTables();
        $defaultsInitialized = $authorized && $this->hasSeedVersionTable();
        $session = $databasePrepared ? InstallationSession::query()->latest('id')->first() : null;
        $isLive = $session?->status === 'LIVE';
        $availableUpdates = [];
        if ($databasePrepared) {
            try {
                $availableUpdates = app(SystemDefaultOrchestrator::class)->availableUpdates();
            } catch (Throwable) {
                $availableUpdates = [];
            }
        }
        $customer = $authorized && $databasePrepared ? $this->customerSnapshot() : [];

        return view('Installer::setup.index', [
            'authorized' => $authorized,
            'checks' => $authorized ? $this->checks() : [],
            'databaseReady' => $authorized && $this->databaseIsReachable(),
            'databasePrepared' => $databasePrepared,
            'defaultsInitialized' => $defaultsInitialized,
            'installationState' => $state,
            'notice' => $notice,
            'token' => (string) $request->query('token', ''),
            ...$customer,
            'validation' => $validation,
            'isLive' => $isLive,
            'liveSession' => $session,
            'availableUpdates' => $availableUpdates,
        ]);
    }

    private function installerIsLive(): bool
    {
        try {
            return InstallationSession::query()->latest('id')->value('status') === 'LIVE';
        } catch (Throwable) {
            return false;
        }
    }

    private function assertInstallerOpen(): void
    {
        abort_if($this->installerIsLive(), 409, 'ระบบเปิดใช้งานจริงแล้ว การแก้ไขผ่าน Installer ถูกล็อก');
    }

    private function safeExceptionDetail(Throwable $exception): string
    {
        $message = trim((string) $exception->getMessage());
        $message = preg_replace('/(password|passwd|pwd)\s*[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $message) ?: $message;
        $message = $message !== '' ? $message : 'ไม่พบรายละเอียดจากระบบ';

        return str(sprintf('[%s] %s (at %s:%d)', $exception::class, $message, basename($exception->getFile()), $exception->getLine()))->limit(4000)->toString();
    }

    /** @param callable():string $action */
    private function runCustomerAction(Request $request, callable $action): View
    {
        abort_unless((bool) config('erp.setup.enabled'), 404);
        abort_unless($this->authorized($request), 403);
        $this->assertInstallerOpen();

        if (! $this->hasInstallerTables()) {
            return $this->render($request, 'กรุณาเตรียมฐานข้อมูลก่อนตั้งค่าลูกค้า');
        }

        try {
            return $this->render($request, $action());
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, 'ไม่สามารถบันทึกการตั้งค่าได้ กรุณาตรวจสอบข้อมูลและลองใหม่');
        }
    }

    /** @return array<string, mixed> */
    private function customerSnapshot(): array
    {
        try {
            $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->latest('id')->first();

            return [
                'company' => CompanySetting::query()->first(),
                'programs' => Program::query()->orderBy('sort_order')->get(),
                'defaultBranch' => Branch::query()->where('code', '00000')->first(),
                'defaultWarehouse' => Warehouse::query()->where('code', 'WH001')->first(),
                'administrator' => $admin,
                'openingImport' => MigrationImportBatch::query()->where('type', OpeningBalanceTemplate::TYPE)->latest('id')->first(),
                'partyImport' => MigrationImportBatch::query()->whereIn('type', ['INSTALLER_PARTY_CUSTOMER', 'INSTALLER_PARTY_SUPPLIER'])->latest('id')->first(),
                'itemImport' => MigrationImportBatch::query()->where('type', 'INSTALLER_ITEMS')->latest('id')->first(),
                'employeeImport' => MigrationImportBatch::query()->where('type', 'INSTALLER_EMPLOYEES')->latest('id')->first(),
                'openItemImport' => MigrationImportBatch::query()->where('type', 'INSTALLER_OPEN_ITEMS')->latest('id')->first(),
            ];
        } catch (Throwable) {
            return ['company' => null, 'programs' => collect(), 'defaultBranch' => null, 'defaultWarehouse' => null, 'administrator' => null, 'openingImport' => null, 'partyImport' => null, 'itemImport' => null, 'employeeImport' => null, 'openItemImport' => null];
        }
    }

    private function authorized(Request $request): bool
    {
        $configuredToken = (string) config('erp.setup.token');
        $providedToken = (string) $request->query('token', '');

        return $configuredToken !== '' && $providedToken !== '' && hash_equals($configuredToken, $providedToken);
    }

    private function hasInstallerTables(): bool
    {
        try {
            return Schema::hasTable('installation_sessions');
        } catch (Throwable) {
            return false;
        }
    }

    private function hasSeedVersionTable(): bool
    {
        try {
            return Schema::hasTable('system_seed_versions') && DB::table('system_seed_versions')->whereIn('seed_code', ['core.rbac', 'core.programs', 'accounting.journal_books', 'core.role_templates'])->count() >= 4;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, array{label:string, status:string, detail:string, critical:bool}> */
    private function checks(): array
    {
        $database = $this->databaseCheck();
        $storagePath = storage_path();
        $cachePath = base_path('bootstrap/cache');

        return [
            [
                'label' => 'PHP >= 8.2',
                'status' => PHP_VERSION_ID >= 80200 ? 'pass' : 'fail',
                'detail' => PHP_VERSION,
                'critical' => true,
            ],
            [
                'label' => 'Laravel',
                'status' => 'pass',
                'detail' => app()->version(),
                'critical' => true,
            ],
            [
                'label' => 'Database connection',
                'status' => $database['status'],
                'detail' => $database['detail'],
                'critical' => true,
            ],
            [
                'label' => 'Required PHP extensions',
                'status' => $this->hasRequiredExtensions() ? 'pass' : 'fail',
                'detail' => 'pdo, mbstring, openssl, tokenizer, xml, ctype, json',
                'critical' => true,
            ],
            [
                'label' => 'Storage writable',
                'status' => is_writable($storagePath) ? 'pass' : 'fail',
                'detail' => $storagePath,
                'critical' => true,
            ],
            [
                'label' => 'Bootstrap cache writable',
                'status' => is_writable($cachePath) ? 'pass' : 'fail',
                'detail' => $cachePath,
                'critical' => true,
            ],
            [
                'label' => 'Application URL',
                'status' => filled(config('app.url')) ? 'pass' : 'fail',
                'detail' => (string) config('app.url'),
                'critical' => true,
            ],
            [
                'label' => 'HTTPS',
                'status' => request()->isSecure() ? 'pass' : 'warning',
                'detail' => request()->isSecure() ? 'Enabled' : 'Local/non-HTTPS request',
                'critical' => false,
            ],
            [
                'label' => 'Timezone',
                'status' => in_array(config('app.timezone'), timezone_identifiers_list(), true) ? 'pass' : 'fail',
                'detail' => (string) config('app.timezone'),
                'critical' => true,
            ],
            [
                'label' => 'Redis',
                'status' => 'warning',
                'detail' => 'Optional; verify only when selected by production configuration',
                'critical' => false,
            ],
            [
                'label' => 'Mail',
                'status' => config('mail.default') === 'log' ? 'warning' : 'pass',
                'detail' => 'Mailer: '.config('mail.default'),
                'critical' => false,
            ],
            [
                'label' => 'Disk space',
                'status' => $this->diskHasSpace() ? 'pass' : 'warning',
                'detail' => 'Available: '.number_format((float) disk_free_space(base_path()) / 1024 / 1024 / 1024, 2).' GB',
                'critical' => false,
            ],
        ];
    }

    /** @return array{status:string, detail:string} */
    private function databaseCheck(): array
    {
        try {
            $version = DB::connection()->selectOne('select version() as version');
            $config = DB::connection()->getConfig();
            $driver = (string) ($config['driver'] ?? config('database.default'));
            $host = (string) ($config['host'] ?? '-');
            $port = (string) ($config['port'] ?? '-');
            $database = (string) ($config['database'] ?? '-');

            return ['status' => 'pass', 'detail' => sprintf('Connected · Driver: %s · Host: %s · Port: %s · Database: %s · Server: %s', $driver, $host, $port, $database, $version->version ?? '-')];
        } catch (ConnectionException|Throwable $exception) {
            report($exception);

            return ['status' => 'fail', 'detail' => 'Unable to connect using configured database settings'];
        }
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasRequiredExtensions(): bool
    {
        return collect(['pdo', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json'])
            ->every(fn (string $extension): bool => extension_loaded($extension));
    }

    private function diskHasSpace(): bool
    {
        $free = @disk_free_space(base_path());

        return $free === false || $free >= 1024 * 1024 * 1024;
    }
}
