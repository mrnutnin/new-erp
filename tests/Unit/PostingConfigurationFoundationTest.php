<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Asset\Support\AssetPostingAccountResolver;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Services\InventoryCostPostingContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostingConfigurationFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('accounting_account_mappings');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');

        Schema::create('account_types', function (Blueprint $table) {
            $table->id();
            $table->string('code');
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_type_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('control_account_type')->nullable();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('accounting_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50);
            $table->string('event_code', 80)->nullable();
            $table->unsignedBigInteger('account_id');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['event_code', 'key']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('accounting_account_mappings');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');
        parent::tearDown();
    }

    public function test_event_specific_mapping_returns_account_and_immutable_provenance(): void
    {
        $accountId = DB::table('accounts')->insertGetId([
            'code' => '21000', 'name' => 'เจ้าหนี้', 'control_account_type' => 'AP', 'is_postable' => true, 'is_active' => true,
        ]);
        DB::table('accounting_account_mappings')->insert([
            'event_code' => 'supplier_invoice.inventory', 'key' => 'ACCOUNTS_PAYABLE', 'account_id' => $accountId, 'is_active' => true, 'version' => 3,
        ]);

        $resolved = app(AccountMappingService::class)->resolveForEvent('supplier_invoice.inventory', 'ACCOUNTS_PAYABLE');

        self::assertSame($accountId, $resolved['account']->id);
        self::assertSame([
            'event_code' => 'supplier_invoice.inventory', 'account_role' => 'ACCOUNTS_PAYABLE', 'account_id' => $accountId,
            'source' => 'MAPPING', 'source_type' => 'ACCOUNT_MAPPING', 'source_id' => '1', 'mapping_id' => 1, 'mapping_version' => 3,
        ], $resolved['provenance']);
    }

    public function test_readiness_reports_missing_event_role_with_accounting_recovery(): void
    {
        $accountId = DB::table('accounts')->insertGetId([
            'code' => '21000', 'name' => 'เจ้าหนี้', 'control_account_type' => 'AP', 'is_postable' => true, 'is_active' => true,
        ]);
        DB::table('accounting_account_mappings')->insert([
            'event_code' => 'supplier_invoice.inventory', 'key' => 'ACCOUNTS_PAYABLE', 'account_id' => $accountId, 'is_active' => true, 'version' => 1,
        ]);

        $readiness = app(AccountMappingService::class)->readiness('supplier_invoice.inventory');

        self::assertFalse($readiness['ready']);
        self::assertSame(['INVENTORY', 'ACCOUNTS_PAYABLE'], $readiness['required_roles']);
        self::assertSame('INVENTORY', $readiness['blockers'][0]['account_role']);
        self::assertSame('ACCOUNT_MAPPING_NOT_READY', $readiness['blockers'][0]['code']);
        self::assertStringContainsString('/accounting/account-mappings', $readiness['blockers'][0]['recovery_url']);
    }

    public function test_supplier_advance_is_resolved_from_the_supplier_payment_event(): void
    {
        $typeId = DB::table('account_types')->insertGetId(['code' => 'ASSET']);
        $accountId = DB::table('accounts')->insertGetId([
            'account_type_id' => $typeId,
            'code' => '12500',
            'name' => 'เงินจ่ายล่วงหน้าผู้ขาย',
            'is_postable' => true,
            'is_active' => true,
        ]);
        DB::table('accounting_account_mappings')->insert([
            'event_code' => 'supplier_payment',
            'key' => 'SUPPLIER_ADVANCE',
            'account_id' => $accountId,
            'is_active' => true,
            'version' => 2,
        ]);

        $resolved = app(AccountMappingService::class)->resolveForEvent('supplier_payment', 'SUPPLIER_ADVANCE');

        self::assertSame($accountId, $resolved['account']->id);
        self::assertSame('supplier_payment', $resolved['provenance']['event_code']);
        self::assertSame('SUPPLIER_ADVANCE', $resolved['provenance']['account_role']);
        self::assertSame(2, $resolved['provenance']['mapping_version']);
    }

    public function test_mapping_version_changes_only_when_posting_behavior_changes(): void
    {
        $mapping = new AccountMapping(['account_id' => 11, 'is_active' => true, 'version' => 4]);
        $service = app(AccountMappingService::class);

        self::assertSame(4, $service->nextVersion($mapping, 11, true));
        self::assertSame(5, $service->nextVersion($mapping, 12, true));
        self::assertSame(5, $service->nextVersion($mapping, 11, false));
    }

    public function test_configuration_catalog_exposes_only_configurable_event_roles(): void
    {
        $events = app(AccountMappingService::class)->configurationEvents();

        self::assertArrayHasKey('asset.depreciation', $events);
        self::assertSame('Asset', $events['asset.depreciation']['module']);
        self::assertSame('DEPRECIATION_EXPENSE', $events['asset.depreciation']['roles'][0]['account_role']);
        self::assertSame(
            ['ACCOUNTS_RECEIVABLE', 'DEFERRED_OUTPUT_VAT', 'WHT_RECEIVABLE', 'CUSTOMER_ADVANCE'],
            array_column($events['sales_invoice']['roles'], 'account_role'),
        );
        self::assertSame(
            ['OUTPUT_VAT', 'WHT_RECEIVABLE', 'CUSTOMER_ADVANCE'],
            array_column($events['customer_payment']['roles'], 'account_role'),
        );
        self::assertSame(
            ['CUSTOMER_ADVANCE', 'WHT_RECEIVABLE'],
            array_column($events['customer_advance']['roles'], 'account_role'),
        );
        self::assertSame(
            ['INPUT_VAT', 'WHT_PAYABLE', 'SUPPLIER_ADVANCE'],
            array_column($events['supplier_payment']['roles'], 'account_role'),
        );
        self::assertSame(
            ['COMMISSION_EXPENSE'],
            array_column($events['sales_commission_payout']['roles'], 'account_role'),
        );
        self::assertArrayNotHasKey('asset.branch_transfer', $events);
    }

    public function test_asset_category_override_has_provenance_and_mapping_is_its_fallback(): void
    {
        $expenseType = DB::table('account_types')->insertGetId(['code' => 'EXPENSE']);
        $categoryAccount = DB::table('accounts')->insertGetId([
            'code' => '57000', 'name' => 'ขาดทุนด้อยค่าหมวด', 'account_type_id' => $expenseType, 'is_postable' => true, 'is_active' => true,
        ]);
        $mappingAccount = DB::table('accounts')->insertGetId([
            'code' => '57001', 'name' => 'ขาดทุนด้อยค่ากลาง', 'account_type_id' => $expenseType, 'is_postable' => true, 'is_active' => true,
        ]);
        DB::table('accounting_account_mappings')->insert([
            'event_code' => 'asset.impairment', 'key' => 'IMPAIRMENT_LOSS', 'account_id' => $mappingAccount, 'is_active' => true, 'version' => 2,
        ]);
        $resolver = new AssetPostingAccountResolver(app(AccountMappingService::class));

        $category = $resolver->resolve('asset.impairment', 'IMPAIRMENT_LOSS', $categoryAccount, 'ASSET_CATEGORY', '9');
        $fallback = $resolver->resolve('asset.impairment', 'IMPAIRMENT_LOSS');

        self::assertSame($categoryAccount, $category['account']->id);
        self::assertSame('MASTER', $category['provenance']['source']);
        self::assertSame('ASSET_CATEGORY', $category['provenance']['source_type']);
        self::assertSame('9', $category['provenance']['source_id']);
        self::assertSame($mappingAccount, $fallback['account']->id);
        self::assertSame('MAPPING', $fallback['provenance']['source']);
        self::assertSame(2, $fallback['provenance']['mapping_version']);
    }

    public function test_account_option_constraint_uses_the_selected_role_contract(): void
    {
        $assetType = DB::table('account_types')->insertGetId(['code' => 'ASSET']);
        DB::table('accounts')->insert(['code' => '11000', 'name' => 'สินทรัพย์', 'account_type_id' => $assetType, 'is_postable' => true, 'is_active' => true]);
        DB::table('accounts')->insert(['code' => '21000', 'name' => 'เจ้าหนี้', 'control_account_type' => 'AP', 'is_postable' => true, 'is_active' => true]);
        $query = Account::query()->where('is_active', true)->where('is_postable', true);
        app(AccountMappingService::class)->applyCompatibleAccountConstraint($query, 'ACCOUNTS_PAYABLE');

        self::assertSame(['21000'], $query->pluck('code')->all());
    }

    public function test_legacy_resolution_fails_closed_when_active_legacy_rows_are_duplicated(): void
    {
        foreach ([1, 2] as $id) {
            DB::table('accounts')->insert([
                'id' => $id, 'code' => "2100{$id}", 'name' => 'เจ้าหนี้', 'control_account_type' => 'AP', 'is_postable' => true, 'is_active' => true,
            ]);
            DB::table('accounting_account_mappings')->insert([
                'event_code' => null, 'key' => 'PURCHASE_AP', 'account_id' => $id, 'is_active' => true, 'version' => 1,
            ]);
        }

        $this->expectException(ValidationException::class);
        app(AccountMappingService::class)->resolve('PURCHASE_AP');
    }

    public function test_inventory_adjustment_uses_event_scoped_mappings_and_returns_metadata_provenance(): void
    {
        $expenseType = DB::table('account_types')->insertGetId(['code' => 'EXPENSE']);
        $revenueType = DB::table('account_types')->insertGetId(['code' => 'REVENUE']);
        $inventory = DB::table('accounts')->insertGetId(['code' => '13100', 'name' => 'สินค้า', 'control_account_type' => 'INVENTORY', 'is_postable' => true, 'is_active' => true]);
        $gain = DB::table('accounts')->insertGetId(['code' => '41900', 'name' => 'กำไรปรับปรุง', 'account_type_id' => $revenueType, 'is_postable' => true, 'is_active' => true]);
        DB::table('accounts')->insertGetId(['code' => '51900', 'name' => 'ขาดทุนปรับปรุง', 'account_type_id' => $expenseType, 'is_postable' => true, 'is_active' => true]);
        foreach ([['INVENTORY', $inventory], ['ADJUSTMENT_GAIN', $gain]] as [$role, $accountId]) {
            DB::table('accounting_account_mappings')->insert(['event_code' => 'inventory_adjustment', 'key' => $role, 'account_id' => $accountId, 'is_active' => true, 'version' => 2]);
        }

        $result = (new InventoryCostPostingContract)->dryRun(new CostAllocation(['id' => 7, 'allocation_type' => 'ADJUSTMENT', 'direction' => 'IN', 'status' => 'PENDING', 'cost_status' => 'FINAL', 'value' => '125.00']), 'inventory_adjustment', app(AccountMappingService::class));

        self::assertSame($inventory, $result['accounts']['INVENTORY_DEFAULT']);
        self::assertSame($gain, $result['accounts']['INVENTORY_ADJUSTMENT_GAIN']);
        self::assertSame(['INVENTORY', 'ADJUSTMENT_GAIN'], array_column($result['provenance'], 'account_role'));
    }
}
