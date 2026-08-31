<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Accounting\Services\AccountMappingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountMappingServiceTest extends TestCase
{
    public function test_mapping_resolution_fails_closed_on_duplicate_active_keys(): void
    {
        $source = file_get_contents(base_path('app/Modules/Accounting/Services/AccountMappingService.php'));

        $this->assertStringContainsString('if ($mappings->count() !== 1)', $source);
        $this->assertStringContainsString('$mappings->sole()->account_id', $source);
        $this->assertStringNotContainsString("->where('key', \$key)->where('is_active', true)->sharedLock()->first()", $source);
    }

    public function test_purchase_default_accepts_non_control_expense_or_asset_only(): void
    {
        $service = new AccountMappingService;
        $expense = new Account(['is_active' => true, 'is_postable' => true]);
        $expense->setRelation('type', new AccountType(['code' => 'EXPENSE']));
        $service->assertCompatible('PURCHASE_EXPENSE_DEFAULT', $expense);
        $this->addToAssertionCount(1);

        $expense->control_account_type = 'AP';
        $this->expectException(ValidationException::class);
        $service->assertCompatible('PURCHASE_EXPENSE_DEFAULT', $expense);
    }

    public function test_tax_and_wht_mappings_require_their_typed_control_accounts(): void
    {
        $service = new AccountMappingService;
        foreach (['DEFERRED_INPUT_VAT' => 'INPUT_VAT', 'DEFERRED_OUTPUT_VAT' => 'OUTPUT_VAT', 'WHT_PAYABLE' => 'WITHHOLDING_TAX'] as $key => $type) {
            $account = new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => $type]);
            $service->assertCompatible($key, $account);
        }
        $this->addToAssertionCount(3);
    }

    public function test_inventory_mappings_require_typed_accounts(): void
    {
        $service = new AccountMappingService;
        $inventory = new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']);
        $service->assertCompatible('INVENTORY_DEFAULT', $inventory);

        foreach (['COGS_DEFAULT' => 'EXPENSE', 'INVENTORY_ADJUSTMENT_GAIN' => 'REVENUE', 'INVENTORY_ADJUSTMENT_LOSS' => 'EXPENSE'] as $key => $type) {
            $account = new Account(['is_active' => true, 'is_postable' => true]);
            $account->setRelation('type', new AccountType(['code' => $type]));
            $service->assertCompatible($key, $account);
        }

        $this->addToAssertionCount(4);
    }

    public function test_advance_mappings_require_explicit_asset_or_liability_accounts(): void
    {
        $service = new AccountMappingService;
        foreach (['CUSTOMER_ADVANCE' => 'LIABILITY', 'SUPPLIER_ADVANCE' => 'ASSET'] as $key => $type) {
            $account = new Account(['is_active' => true, 'is_postable' => true]);
            $account->setRelation('type', new AccountType(['code' => $type]));
            $service->assertCompatible($key, $account);
        }
        $this->addToAssertionCount(2);
    }
}
