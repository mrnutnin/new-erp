<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\StockReservation;
use App\Modules\Wms\Services\StockReservationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StockReservationConsumptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('wms_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('on_hand', 20, 8);
            $table->decimal('reserved', 20, 8);
            $table->decimal('available', 20, 8);
            $table->decimal('inventory_value', 20, 8)->default(0);
            $table->decimal('average_unit_cost', 20, 8)->default(0);
            $table->timestamps();
        });
        Schema::create('wms_stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('quantity', 20, 8);
            $table->decimal('consumed_quantity', 20, 8)->default(0);
            $table->string('status');
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('wms_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            $table->string('movement_type');
            $table->string('direction');
            $table->string('status');
            $table->decimal('quantity', 20, 8);
            $table->decimal('base_quantity', 20, 8);
            $table->date('business_date');
            $table->string('idempotency_key')->unique();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wms_stock_reservation_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_reservation_id');
            $table->unsignedBigInteger('stock_movement_id')->unique();
            $table->decimal('quantity', 20, 8);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wms_stock_reservation_consumptions');
        Schema::dropIfExists('wms_stock_movements');
        Schema::dropIfExists('wms_stock_reservations');
        Schema::dropIfExists('wms_stock_balances');

        parent::tearDown();
    }

    public function test_schema_and_installer_require_consumption_ledger(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_18_020000_add_consumption_to_wms_stock_reservations.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        self::assertStringContainsString("consumed_quantity', 20, 8", $migration);
        self::assertStringContainsString("Schema::create('wms_stock_reservation_consumptions'", $migration);
        self::assertStringContainsString("unique('stock_movement_id'", $migration);
        self::assertStringContainsString("'wms_stock_reservations' => ['quantity', 'consumed_quantity'", $installer);
        self::assertStringContainsString("'wms_stock_reservation_consumptions' =>", $installer);
    }

    public function test_finished_goods_reservation_is_consumed_by_pos_source_line_atomically(): void
    {
        $balance = StockBalance::query()->create(['warehouse_id' => 1, 'item_id' => 9, 'uom_id' => 1, 'on_hand' => '5', 'reserved' => '0', 'available' => '5']);
        $service = app(StockReservationService::class);
        $reservation = $service->reserve(['warehouse_id' => 1, 'item_id' => 9, 'uom_id' => 1, 'quantity' => '5', 'source_type' => StockReservation::SOURCE_SALES_ORDER_LINE, 'source_id' => '77', 'idempotency_key' => 'sales-order-line:77:finished-goods', 'created_by' => 1]);
        $movement = StockMovement::query()->create(['warehouse_id' => 1, 'item_id' => 9, 'uom_id' => 1, 'movement_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'DRAFT', 'quantity' => '5', 'base_quantity' => '5', 'business_date' => today(), 'idempotency_key' => 'pos:77:line:1']);

        $service->consume($reservation, '5', $movement, function (StockMovement $movement) use ($balance): StockMovement {
            $balance->refresh()->update(['on_hand' => '0', 'available' => '0']);
            $movement->update(['status' => 'POSTED', 'posted_at' => now()]);
            return $movement->fresh();
        });

        self::assertSame('CONSUMED', $reservation->fresh()->status);
        self::assertSame('0.00000000', (string) $balance->fresh()->reserved);
        self::assertDatabaseHas('wms_stock_reservation_consumptions', ['stock_reservation_id' => $reservation->id, 'stock_movement_id' => $movement->id, 'quantity' => '5.00000000']);
    }

    public function test_release_of_other_warehouse_reservation_rolls_back_with_failed_sale(): void
    {
        $balance = StockBalance::query()->create(['warehouse_id' => 1, 'item_id' => 9, 'uom_id' => 1, 'on_hand' => '5', 'reserved' => '0', 'available' => '5']);
        $service = app(StockReservationService::class);
        $reservation = $service->reserve(['warehouse_id' => 1, 'item_id' => 9, 'uom_id' => 1, 'quantity' => '5', 'source_type' => StockReservation::SOURCE_SALES_ORDER_LINE, 'source_id' => '77', 'idempotency_key' => 'sales-order-line:77:finished-goods', 'created_by' => 1]);

        try {
            DB::transaction(function () use ($service, $reservation): void {
                $service->release($reservation);
                throw new \RuntimeException('sale posting failed');
            });
            self::fail('sale must roll back');
        } catch (\RuntimeException $exception) {
            self::assertSame('sale posting failed', $exception->getMessage());
        }
        self::assertSame('OPEN', $reservation->fresh()->status);
        self::assertSame('5.00000000', (string) $balance->fresh()->reserved);

        DB::transaction(fn () => $service->release($reservation));
        $service->release($reservation);
        self::assertSame('RELEASED', $reservation->fresh()->status);
        self::assertSame('0.00000000', (string) $balance->fresh()->reserved);
        self::assertSame('5.00000000', (string) $balance->fresh()->available);
    }

    public function test_partial_and_full_consumption_are_atomic_and_idempotent(): void
    {
        $balance = StockBalance::query()->create(['warehouse_id' => 1, 'item_id' => 2, 'uom_id' => 3, 'on_hand' => '10', 'reserved' => '10', 'available' => '0']);
        $reservation = StockReservation::query()->create(['warehouse_id' => 1, 'item_id' => 2, 'uom_id' => 3, 'quantity' => '10', 'consumed_quantity' => '0', 'status' => 'OPEN', 'idempotency_key' => 'reservation-1']);
        $service = app(StockReservationService::class);
        $post = function (StockMovement $movement) use ($balance): StockMovement {
            $balance->refresh();
            $onHand = (string) ((float) $balance->on_hand - (float) $movement->base_quantity);
            $balance->update(['on_hand' => $onHand, 'available' => (string) ((float) $onHand - (float) $balance->reserved)]);
            $movement->update(['status' => 'POSTED', 'posted_at' => now()]);

            return $movement->fresh();
        };
        $movement = fn (string $quantity, string $key) => StockMovement::query()->create([
            'warehouse_id' => 1, 'item_id' => 2, 'uom_id' => 3, 'movement_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'DRAFT',
            'quantity' => $quantity, 'base_quantity' => $quantity, 'business_date' => today(), 'idempotency_key' => $key,
        ]);

        $firstMovement = $movement('4', 'movement-1');
        $partial = $service->consume($reservation, '4', $firstMovement, $post);
        self::assertSame('OPEN', $partial->status);
        self::assertSame('4.00000000', (string) $partial->consumed_quantity);
        self::assertSame('6.00000000', (string) $balance->fresh()->reserved);

        $secondMovement = $movement('6', 'movement-2');
        $complete = $service->consume($partial, '6', $secondMovement, $post);
        self::assertSame('CONSUMED', $complete->status);
        self::assertSame('10.00000000', (string) $complete->consumed_quantity);
        self::assertSame('0.00000000', (string) $balance->fresh()->available);

        $retried = $service->consume($complete, '6', $secondMovement, fn () => self::fail('retry must not post stock twice'));
        self::assertSame('CONSUMED', $retried->status);
    }
}
