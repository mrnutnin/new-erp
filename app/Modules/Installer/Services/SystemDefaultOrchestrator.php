<?php

namespace App\Modules\Installer\Services;

use App\Models\Permission;
use App\Models\Program;
use App\Models\Role;
use App\Modules\Installer\Models\InstallationSession;
use App\Modules\Installer\Models\SystemSeedVersion;
use Database\Seeders\JournalBookSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\StandardChartOfAccountsSeeder;
use Database\Seeders\SystemDocumentSequenceSeeder;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemDefaultOrchestrator
{
    /** @var array<string, string> */
    private const SEED_VERSIONS = [
        'core.rbac' => '1.0',
        'core.programs' => '1.0',
        'accounting.journal_books' => '1.0',
        'accounting.chart_of_accounts' => '1.1',
        'core.document_sequences' => '1.0',
        'core.role_templates' => '1.0',
    ];

    public function __construct(private readonly InstallerStateStore $stateStore) {}

    /** @return array<int, array{seed_code:string, current:?string, latest:string}> */
    public function availableUpdates(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('system_seed_versions')) {
            return [];
        }

        return collect(self::SEED_VERSIONS)->map(function (string $latest, string $code): ?array {
            $current = SystemSeedVersion::query()->where('seed_code', $code)->value('version');

            return $current === null || version_compare((string) $current, $latest, '<')
                ? ['seed_code' => $code, 'current' => $current, 'latest' => $latest]
                : null;
        })->filter()->values()->all();
    }

    /** @return array{status:string, message:string, seed_count:int} */
    public function run(): array
    {
        $session = InstallationSession::query()->latest('id')->firstOrCreate([], [
            'status' => 'DATABASE_READY',
            'progress' => 10,
            'started_at' => now(),
            'metadata' => ['created_by' => 'web_installer'],
        ]);

        try {
            $result = DB::transaction(function () use ($session): array {
                $this->markStep($session, 'system-defaults', 'IN_PROGRESS');

                app(RbacSeeder::class)->run();
                $rbac = $this->version('core.rbac');

                $this->seedPrograms();
                $programs = $this->version('core.programs');

                app(JournalBookSeeder::class)->run();
                $journalBooks = $this->version('accounting.journal_books');

                app(StandardChartOfAccountsSeeder::class)->run();
                $chartOfAccounts = $this->version('accounting.chart_of_accounts');

                app(SystemDocumentSequenceSeeder::class)->run();
                $documentSequences = $this->version('core.document_sequences');

                $roles = $this->seedRoleTemplates();
                $roleTemplates = $this->version('core.role_templates');

                $this->markStep($session, 'system-defaults', 'COMPLETED', [
                    'seed_versions' => [$rbac, $programs, $journalBooks, $chartOfAccounts, $documentSequences, $roleTemplates],
                    'role_templates' => $roles,
                ]);

                $session->forceFill(['status' => 'DEFAULTS_READY', 'progress' => max(30, (int) $session->progress)])->save();

                return ['seed_count' => 6];
            });

            $this->stateStore->write([
                'status' => 'DEFAULTS_COMPLETED',
                'step_code' => 'system-defaults',
                'message' => 'ติดตั้ง System Defaults สำเร็จ',
                'installation_session_id' => $session->id,
            ]);

            return ['status' => 'success', 'message' => 'ติดตั้ง System Defaults สำเร็จแล้ว', ...$result];
        } catch (Throwable $exception) {
            report($exception);
            $message = 'ไม่สามารถติดตั้ง System Defaults ได้ กรุณาตรวจสอบฐานข้อมูลและลองใหม่';

            $this->stateStore->write([
                'status' => 'FAILED',
                'step_code' => 'system-defaults',
                'message' => $message,
                'technical_detail' => $exception->getMessage(),
            ]);

            try {
                $session->steps()->updateOrCreate(['step_code' => 'system-defaults'], [
                    'status' => 'FAILED',
                    'error_message' => $message,
                    'metadata' => ['technical_detail' => $exception->getMessage()],
                ]);
                $session->forceFill(['status' => 'FAILED'])->save();
            } catch (Throwable) {
                // The file state remains the source of truth until the database is healthy.
            }

            return ['status' => 'failed', 'message' => $message, 'seed_count' => 0];
        }
    }

    private function seedPrograms(): void
    {
        collect([
            ['code' => 'dashboard', 'name' => 'Dashboard', 'description' => 'ภาพรวมองค์กรสำหรับผู้บริหาร', 'requires_branch' => false, 'requires_warehouse' => false, 'entry_route' => 'dashboard', 'is_enabled' => true, 'sort_order' => 1],
            ['code' => 'settings', 'name' => 'Global Setting', 'description' => 'ตั้งค่าระบบและข้อมูลบริษัท', 'requires_branch' => false, 'requires_warehouse' => false, 'entry_route' => 'settings.index', 'is_enabled' => true, 'sort_order' => 2],
            ['code' => 'purchasing', 'name' => 'Purchasing', 'description' => 'บริหารจัดซื้อ', 'requires_branch' => true, 'requires_warehouse' => true, 'entry_route' => 'purchasing.index', 'is_enabled' => true, 'sort_order' => 3],
            ['code' => 'wms', 'name' => 'WMS', 'description' => 'บริหารคลังสินค้าและสต็อก', 'requires_branch' => true, 'requires_warehouse' => true, 'entry_route' => 'wms.index', 'is_enabled' => true, 'sort_order' => 4],
            ['code' => 'pos', 'name' => 'POS', 'description' => 'ขายและคำสั่งซื้อ', 'requires_branch' => true, 'requires_warehouse' => true, 'entry_route' => 'pos.index', 'is_enabled' => true, 'sort_order' => 5],
            ['code' => 'finance', 'name' => 'Finance', 'description' => 'บริหารการเงิน', 'requires_branch' => true, 'requires_warehouse' => true, 'entry_route' => 'finance.index', 'is_enabled' => true, 'sort_order' => 6],
            ['code' => 'accounting', 'name' => 'Accounting', 'description' => 'บัญชีและรายงานการเงิน', 'requires_branch' => true, 'requires_warehouse' => true, 'entry_route' => 'accounting.index', 'is_enabled' => true, 'sort_order' => 7],
            ['code' => 'asset', 'name' => 'Asset', 'description' => 'บริหารสินทรัพย์', 'requires_branch' => true, 'requires_warehouse' => false, 'entry_route' => 'asset.index', 'is_enabled' => true, 'sort_order' => 8],
            ['code' => 'logistics', 'name' => 'Logistics', 'description' => 'บริหารการขนส่ง', 'requires_branch' => true, 'requires_warehouse' => true, 'entry_route' => 'dashboard', 'is_enabled' => false, 'sort_order' => 9],
        ])->each(function (array $definition): void {
            $program = Program::query()->withTrashed()->firstOrNew(['code' => $definition['code']]);
            $program->fill($definition);
            $program->deleted_at = null;
            $program->save();
        });
    }

    /** @return array<string, bool> */
    private function seedRoleTemplates(): array
    {
        $permissions = Permission::query()->get(['id', 'code']);
        $definitions = [
            'manager' => ['name' => 'ผู้จัดการ', 'description' => 'จัดการงานตาม Module ที่ได้รับมอบหมาย', 'filter' => fn (string $code): bool => ! str_ends_with($code, '.delete') && ! str_ends_with($code, '.reverse')],
            'approver' => ['name' => 'ผู้อนุมัติ', 'description' => 'อนุมัติเอกสารตามสิทธิ์ที่กำหนด', 'filter' => fn (string $code): bool => str_ends_with($code, '.approve') || str_ends_with($code, '.reject') || str_ends_with($code, '.view')],
            'accountant' => ['name' => 'นักบัญชี', 'description' => 'งาน Accounting และ Finance', 'filter' => fn (string $code): bool => str_starts_with($code, 'accounting.') || str_starts_with($code, 'finance.')],
            'warehouse_staff' => ['name' => 'พนักงานคลัง', 'description' => 'งานรับ จ่าย โอน และตรวจสอบคลัง', 'filter' => fn (string $code): bool => str_starts_with($code, 'wms.') && ! str_ends_with($code, '.delete')],
            'sales' => ['name' => 'พนักงานขาย', 'description' => 'งานขายและ POS', 'filter' => fn (string $code): bool => str_starts_with($code, 'pos.') && ! str_ends_with($code, '.delete')],
            'purchasing' => ['name' => 'พนักงานจัดซื้อ', 'description' => 'งานจัดซื้อ', 'filter' => fn (string $code): bool => str_starts_with($code, 'purchasing.') && ! str_ends_with($code, '.delete')],
            'viewer' => ['name' => 'ผู้ดูข้อมูล', 'description' => 'ดูข้อมูลและรายงานเท่านั้น', 'filter' => fn (string $code): bool => str_ends_with($code, '.view')],
        ];

        $created = [];
        foreach ($definitions as $code => $definition) {
            $role = Role::query()->withTrashed()->firstOrNew(['code' => $code]);
            $wasRecentlyCreated = ! $role->exists;
            $role->fill([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_active' => true,
            ]);
            $role->deleted_at = null;
            $role->save();

            if ($wasRecentlyCreated) {
                $role->permissions()->sync($permissions->filter(fn (Permission $permission): bool => ($definition['filter'])($permission->code))->modelKeys());
            }

            $created[$code] = $wasRecentlyCreated;
        }

        return $created;
    }

    private function version(string $code): string
    {
        $version = self::SEED_VERSIONS[$code];
        SystemSeedVersion::query()->updateOrCreate(['seed_code' => $code], [
            'version' => $version,
            'installed_at' => now(),
            'updated_at' => now(),
        ]);

        return $code.'@'.$version;
    }

    /** @param array<string, mixed> $metadata */
    private function markStep(InstallationSession $session, string $code, string $status, array $metadata = []): void
    {
        $session->steps()->updateOrCreate(['step_code' => $code], [
            'status' => $status,
            'started_at' => $status === 'IN_PROGRESS' ? now() : null,
            'completed_at' => $status === 'COMPLETED' ? now() : null,
            'metadata' => $metadata,
        ]);
    }
}
