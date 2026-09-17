<?php

namespace Tests\Unit;

use App\Modules\Wms\Controllers\ItemController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class WmsItemAccountValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');
        Schema::create('account_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_type_id');
            $table->string('code');
            $table->string('name');
            $table->string('control_account_type')->nullable();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        foreach (['ASSET', 'REVENUE', 'EXPENSE'] as $id => $code) {
            DB::table('account_types')->insert(['id' => $id + 1, 'code' => $code]);
        }
        DB::table('accounts')->insert([
            ['id' => 1, 'account_type_id' => 1, 'code' => '13000', 'name' => 'สินค้าคงเหลือ', 'control_account_type' => 'INVENTORY'],
            ['id' => 2, 'account_type_id' => 2, 'code' => '41000', 'name' => 'รายได้จากการขาย', 'control_account_type' => null],
            ['id' => 3, 'account_type_id' => 3, 'code' => '52000', 'name' => 'ต้นทุนขาย', 'control_account_type' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_types');
        parent::tearDown();
    }

    public function test_inventory_control_account_is_selectable_and_valid_for_a_stock_item(): void
    {
        $controller = new ItemController;
        $options = $controller->accountOptions(Request::create('/', 'GET', ['type' => 'ASSET']))->getData(true);
        $this->assertSame([1], collect($options['results'])->pluck('id')->all());

        $method = new ReflectionMethod($controller, 'assertAccounts');
        $method->invoke($controller, [
            'item_type' => 'GOODS',
            'is_stock_item' => true,
            'inventory_account_id' => 1,
            'sales_account_id' => 2,
            'cogs_account_id' => 3,
        ]);

        $this->addToAssertionCount(1);
    }
}
