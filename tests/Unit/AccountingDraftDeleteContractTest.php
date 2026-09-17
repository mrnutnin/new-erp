<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class AccountingDraftDeleteContractTest extends TestCase
{
    public function test_manual_journal_draft_has_guarded_delete_route(): void
    {
        $route = app('router')->getRoutes()->getByName('accounting.journal-entries.destroy');

        self::assertNotNull($route);
        self::assertContains('DELETE', $route->methods());
        self::assertContains('permission:accounting.journal-entries.delete', $route->gatherMiddleware());
    }

    public function test_bank_statement_draft_has_guarded_delete_route(): void
    {
        $route = app('router')->getRoutes()->getByName('accounting.bank-reconciliation.destroy');

        self::assertNotNull($route);
        self::assertContains('DELETE', $route->methods());
        self::assertContains('permission:accounting.bank-reconciliation.delete', $route->gatherMiddleware());
    }

    public function test_manual_journal_draft_exposes_standard_delete_action_on_list_and_detail(): void
    {
        $index = file_get_contents(base_path('app/Modules/Accounting/Views/journal-entries/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Accounting/Views/journal-entries/show.blade.php'));

        self::assertStringContainsString('delete_url', $index);
        self::assertStringContainsString('btn-app-danger', $index);
        self::assertStringContainsString('bx-trash', $index);
        self::assertStringContainsString('ลบร่าง', $index);
        self::assertStringContainsString("source_type === 'MANUAL'", $show);
        self::assertStringContainsString('btn-app-danger', $show);
        self::assertStringContainsString('bx-trash', $show);
        self::assertStringContainsString('ลบร่าง', $show);
    }

    public function test_delete_handler_rechecks_scope_status_source_and_writes_audit(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Accounting/Controllers/JournalEntryController.php'));

        self::assertStringContainsString('ensureWarehouseScope($request, $journalEntry)', $controller);
        self::assertStringContainsString("status === 'DRAFT'", $controller);
        self::assertStringContainsString("source_type === 'MANUAL'", $controller);
        self::assertStringContainsString("'accounting.journal_entry.deleted'", $controller);
        self::assertStringContainsString("'accounting.journal-entries.delete'", $controller);
    }

    public function test_bank_statement_draft_delete_is_available_and_server_guarded(): void
    {
        $index = file_get_contents(base_path('app/Modules/Accounting/Views/bank-reconciliation/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Accounting/Views/bank-reconciliation/show.blade.php'));
        $controller = file_get_contents(base_path('app/Modules/Accounting/Controllers/BankReconciliationController.php'));

        foreach ([$index, $show] as $view) {
            self::assertStringContainsString('accounting.bank-reconciliation.delete', $view);
            self::assertStringContainsString('btn-app-danger', $view);
            self::assertStringContainsString('bx-trash', $view);
            self::assertStringContainsString('ลบร่าง', $view);
        }
        self::assertStringContainsString("status === 'DRAFT'", $controller);
        self::assertStringContainsString('selectedWarehouse', $controller);
        self::assertStringContainsString("'accounting.bank_statement.deleted'", $controller);
    }

    public function test_delete_permission_is_installer_managed(): void
    {
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/SystemDefaultOrchestrator.php'));

        self::assertStringContainsString("'accounting.journal-entries.delete'", $rbac);
        self::assertStringContainsString("'accounting.bank-reconciliation.delete'", $rbac);
        self::assertStringContainsString("'core.rbac' => '2.1'", $installer);
    }

    public function test_all_accounting_models_exposed_to_delete_use_soft_deletes(): void
    {
        foreach ([Account::class, BankStatement::class, JournalEntry::class, TaxCode::class] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_accounting_soft_delete_schema_is_covered_by_migration_and_installer(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_130000_add_soft_deletes_to_accounting_documents.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach (['journal_entries', 'accounting_bank_statements'] as $table) {
            self::assertStringContainsString("'{$table}'", $migration);
        }
        foreach (['accounts', 'tax_codes', 'journal_entries', 'accounting_bank_statements'] as $table) {
            self::assertMatchesRegularExpression("/'{$table}'\\s*=>\\s*\\[[^\\]]*'deleted_at'/", $installer);
        }
    }

    public function test_soft_deleted_accounting_documents_keep_detail_lines(): void
    {
        $journals = file_get_contents(base_path('app/Modules/Accounting/Controllers/JournalEntryController.php'));
        $statements = file_get_contents(base_path('app/Modules/Accounting/Controllers/BankReconciliationController.php'));

        self::assertStringNotContainsString('$entry->lines()->delete();', $journals);
        self::assertStringNotContainsString('$statement->lines()->delete();', $statements);
    }
}
