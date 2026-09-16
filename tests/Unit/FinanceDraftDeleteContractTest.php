<?php

namespace Tests\Unit;

use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Models\InternalTransfer;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Models\PettyCashClearing;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Finance\Models\Settlement;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class FinanceDraftDeleteContractTest extends TestCase
{
    public function test_finance_delete_routes_use_dedicated_permissions(): void
    {
        $routes = [
            'finance.employee-advances.destroy' => 'finance.employee-advances.delete',
            'finance.employee-advance-clearings.destroy' => 'finance.employee-advance-clearings.delete',
            'finance.petty-cash-clearings.destroy' => 'finance.petty-cash-clearings.delete',
            'finance.petty-cash-funds.destroy' => 'finance.petty-cash.manage-funds',
            'finance.petty-cash-top-ups.destroy' => 'finance.petty-cash-top-ups.delete',
            'finance.petty-cash.destroy' => 'finance.petty-cash.delete',
            'finance.bank-accounts.destroy' => 'finance.bank-accounts.delete',
            'finance.payment-terms.destroy' => 'finance.payment-terms.delete',
            'finance.other-categories.destroy' => 'finance.other-categories.delete',
            'finance.settlements.destroy' => 'finance.settlements.delete',
            'finance.internal-transfers.destroy' => 'finance.internal-transfers.delete',
            'finance.payment-vouchers.destroy' => 'finance.payment-vouchers.delete',
        ];

        foreach ($routes as $name => $permission) {
            $route = app('router')->getRoutes()->getByName($name);

            self::assertNotNull($route, $name);
            self::assertContains('DELETE', $route->methods(), $name);
            self::assertContains("permission:{$permission}", $route->gatherMiddleware(), $name);
        }
    }

    public function test_all_finance_models_exposed_to_delete_use_soft_deletes(): void
    {
        foreach ([
            BankAccount::class,
            EmployeeAdvance::class,
            EmployeeAdvanceClearing::class,
            InternalTransfer::class,
            OtherCategory::class,
            PaymentTerm::class,
            PaymentVoucher::class,
            PettyCashClearing::class,
            PettyCashFund::class,
            PettyCashTopUp::class,
            PettyCashVoucher::class,
            Settlement::class,
        ] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_installer_requires_soft_delete_schema_for_all_finance_delete_roots(): void
    {
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach ([
            'finance_bank_accounts',
            'finance_employee_advances',
            'finance_employee_advance_clearings',
            'finance_internal_transfers',
            'finance_other_categories',
            'finance_payment_terms',
            'finance_payment_vouchers',
            'finance_petty_cash_clearings',
            'finance_petty_cash_vouchers',
            'finance_petty_cash_funds',
            'finance_petty_cash_top_ups',
            'finance_settlements',
        ] as $table) {
            self::assertMatchesRegularExpression("/'{$table}'\\s*=>\\s*\\[[^\\]]*'deleted_at'/", $installer);
        }
    }
}
