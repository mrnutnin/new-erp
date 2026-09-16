<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SettingsUiConsistencyContractTest extends TestCase
{
    public function test_settings_master_lists_use_shared_filters_statuses_and_actions(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Settings/Views/';

        foreach (['branches' => 'branch-filter-reset', 'warehouses' => 'warehouse-filter-reset', 'roles' => 'role-filter-reset', 'users' => 'user-filter-reset'] as $resource => $resetId) {
            $view = file_get_contents($root.$resource.'/index.blade.php');

            $this->assertStringContainsString($resetId, $view);
            $this->assertStringContainsString('bx bx-reset', $view);
            $this->assertStringContainsString('btn-app-primary', $view);
            $this->assertStringContainsString('app-status-success', $view);
            $this->assertStringContainsString('app-status-neutral', $view);
            $this->assertStringContainsString('title="แก้ไข" aria-label="แก้ไข"', $view);
            $this->assertStringContainsString('title="ลบ" aria-label="ลบ"', $view);
        }
    }

    public function test_settings_master_forms_use_standard_return_and_primary_actions(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Settings/Views/';

        foreach (['branches', 'warehouses', 'roles', 'users'] as $resource) {
            $view = file_get_contents($root.$resource.'/form.blade.php');

            $this->assertStringContainsString('กลับหน้ารายการ', $view);
            $this->assertStringContainsString('btn-app-soft', $view);
            $this->assertStringContainsString('btn-app-primary', $view);
        }
    }

    public function test_audit_log_has_a_server_side_date_range_filter(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Settings/Views/audit/index.blade.php');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Settings/Controllers/AuditLogController.php');

        $this->assertStringContainsString('audit-filter-date-from', $view);
        $this->assertStringContainsString('audit-filter-date-to', $view);
        $this->assertStringContainsString('audit-filter-reset', $view);
        $this->assertStringContainsString("whereDate('audit_logs.created_at'", $controller);
    }

    public function test_document_sequences_under_settings_use_standard_filter_status_and_action(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Finance/Views/document-sequences/index.blade.php');

        $this->assertStringContainsString('document-sequence-filter-reset', $view);
        $this->assertStringContainsString('app-status-success', $view);
        $this->assertStringContainsString('app-status-neutral', $view);
        $this->assertStringContainsString('title="แก้ไข" aria-label="แก้ไข"', $view);
    }
}
