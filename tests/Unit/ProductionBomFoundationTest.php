<?php

namespace Tests\Unit;

use App\Modules\Production\Support\BomCycleDetector;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProductionBomFoundationTest extends TestCase
{
    public function test_cycle_detector_rejects_direct_and_transitive_cycles(): void
    {
        BomCycleDetector::assertAcyclic(10, [20], [20 => [30], 30 => [40]]);
        self::assertTrue(true);

        $this->expectException(ValidationException::class);
        BomCycleDetector::assertAcyclic(10, [20], [20 => [30], 30 => [10]]);
    }

    public function test_substitute_permissions_and_installer_schema_are_seeded(): void
    {
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        foreach (['production.boms.substitute.view', 'production.boms.substitute.manage', 'production.orders.substitute.use'] as $permission) self::assertStringContainsString("'{$permission}' =>", $rbac);
        self::assertStringContainsString("'production_bom_line_substitutes' =>", $installer);
        self::assertStringContainsString("'production_bom_line_substitutes'", $installer);
    }

    public function test_bom_schema_keeps_revision_snapshot_and_uniqueness_contracts(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_18_040000_create_production_bom_tables.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach (["Schema::create('production_boms'", "Schema::create('production_bom_revisions'", "Schema::create('production_bom_lines'", 'production_boms_branch_code_uq', 'production_bom_revisions_number_uq', 'production_bom_lines_component_uq', "['DRAFT', 'ACTIVE', 'INACTIVE']"] as $contract) {
            self::assertStringContainsString($contract, $migration);
        }
        self::assertStringContainsString("'production_boms' =>", $installer);
        self::assertStringContainsString("'production_bom_revisions' =>", $installer);
        self::assertStringContainsString("'production_bom_lines' =>", $installer);
    }

    public function test_bom_ui_is_capability_guarded_branch_scoped_and_server_side(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/BomController.php'));
        $index = file_get_contents(base_path('app/Modules/Production/Views/boms/index.blade.php'));
        $form = file_get_contents(base_path('app/Modules/Production/Views/boms/form.blade.php'));

        foreach (['boms.index', 'boms.data', 'boms.create', 'boms.store', 'boms.show', 'boms.revisions.update', 'boms.revisions.copy', 'boms.revisions.activate'] as $route) {
            self::assertStringContainsString("name('{$route}')", $routes);
        }
        self::assertStringContainsString("where('production_boms.branch_id', \$branchId)", $controller);
        self::assertStringContainsString('DataTables::eloquent($query)', $controller);
        self::assertStringContainsString('window.erpDataTableDefaults', $index);
        self::assertStringContainsString('window.erpExcelButton($table)', $index);
        self::assertStringContainsString('window.erpRowNumberColumn()', $index);
        self::assertStringContainsString('bx bx-file-find', $index);
        self::assertStringContainsString('window.erpAjaxForm', $form);
        self::assertStringContainsString('data-error-for="lines.', $form);
    }

    public function test_bom_service_activates_only_drafts_and_preserves_active_revisions(): void
    {
        $service = file_get_contents(base_path('app/Modules/Production/Services/BomService.php'));
        $revision = file_get_contents(base_path('app/Modules/Production/Models/BomRevision.php'));
        $line = file_get_contents(base_path('app/Modules/Production/Models/BomLine.php'));

        foreach (['public function create(', 'public function updateDraft(', 'public function copyRevision(', 'public function activate(', 'BomCycleDetector::assertAcyclic', "where('status', 'ACTIVE')", 'lockForUpdate()', 'production.bom_revision.activated'] as $contract) {
            self::assertStringContainsString($contract, $service);
        }
        self::assertStringContainsString("getOriginal('status') === 'ACTIVE'", $revision);
        self::assertStringContainsString("where('status', '!=', 'DRAFT')", $line);
        self::assertStringContainsString('Copy a new revision instead', $revision);
        self::assertStringContainsString('Copy a new revision instead', $line);
    }
}
