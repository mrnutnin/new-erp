<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\InventoryPurchaseReversalService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchaseReversalServiceTest extends TestCase
{
    public function test_gate_rejects_without_running_any_callback(): void
    {
        $calls = 0;
        try {
            $this->service()->execute($this->source(), ['reason' => 'แก้ไขรายการ'], false, null, function () use (&$calls): void {
                $calls++;
            }, function (): array {
                return ['id' => 2];
            }, function (): array {
                return ['id' => 3];
            }, function (): array {
                return ['id' => 4];
            }, function (): bool {
                return true;
            });
            $this->fail('Expected reversal gate');
        } catch (ValidationException) {
            $this->assertSame(0, $calls);
        }
    }

    public function test_fake_callbacks_create_reversal_delta_and_reconciliation(): void
    {
        $sequence = [];
        $result = $this->service()->execute(
            $this->source(), ['reason' => 'แก้ไขรายการ'], true,
            fn (callable $callback): array => $callback(),
            function () use (&$sequence): array {
                $sequence[] = 'journal';

                return ['id' => 501];
            },
            function () use (&$sequence): array {
                $sequence[] = 'movement';

                return ['id' => 502];
            },
            function () use (&$sequence): array {
                $sequence[] = 'allocation';

                return ['id' => 503];
            },
            function () use (&$sequence): array {
                $sequence[] = 'linkage';

                return ['linkage_id' => 504];
            },
            function () use (&$sequence): bool {
                $sequence[] = 'reconcile';

                return true;
            },
        );

        $this->assertSame(['journal', 'movement', 'allocation', 'linkage', 'reconcile'], $sequence);
        $this->assertSame(1, $result['revision']);
        $this->assertTrue($result['source_unchanged']);
    }

    public function test_failed_reconciliation_rolls_back_fake_transaction(): void
    {
        $writes = [];
        $this->expectException(ValidationException::class);
        $this->service()->execute(
            $this->source(), ['reason' => 'แก้ไขรายการ'], true,
            function (callable $callback) use (&$writes): never {
                try {
                    $callback();
                } catch (\Throwable $exception) {
                    $writes = [];
                    throw $exception;
                } throw new \LogicException('must fail');
            },
            function () use (&$writes): array {
                $writes[] = 'journal';

                return ['id' => 1];
            },
            function () use (&$writes): array {
                $writes[] = 'movement';

                return ['id' => 2];
            },
            function () use (&$writes): array {
                $writes[] = 'allocation';

                return ['id' => 3];
            },
            function () use (&$writes): array {
                $writes[] = 'linkage';

                return ['linkage_id' => 4];
            },
            function (): bool {
                return false;
            },
        );
    }

    private function service(): InventoryPurchaseReversalService
    {
        return new InventoryPurchaseReversalService;
    }

    private function source(): array
    {
        return ['document_id' => 9, 'journal_id' => 101, 'movement_id' => 202, 'allocation_id' => 303, 'revision' => 0, 'document_status' => 'POSTED', 'movement_status' => 'POSTED', 'allocation_status' => 'PENDING'];
    }
}
