<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\InventoryPurchaseAtomicWorkflow;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchaseAtomicWorkflowTest extends TestCase
{
    public function test_feature_gate_rejects_before_any_collaborator_runs(): void
    {
        $calls = 0;
        try {
            $this->workflow()->execute(
                $this->readyPlan(), false,
                function () use (&$calls): never {
                    $calls++;
                    throw new \LogicException('must not run');
                },
                function () use (&$calls): array {
                    $calls++;

                    return ['id' => 1];
                },
                function (): array {
                    return ['id' => 2];
                },
                function (): array {
                    return ['id' => 3];
                },
                function (): array {
                    return ['id' => 4];
                },
            );
            $this->fail('Expected feature gate to reject');
        } catch (ValidationException) {
            // Expected: no collaborator is reachable while the gate is closed.
        }

        $this->assertSame(0, $calls);
    }

    public function test_fakes_follow_lock_order_and_return_deterministic_linkage_ids(): void
    {
        $sequence = [];
        $result = $this->workflow()->execute(
            $this->readyPlan(), true,
            fn (callable $callback): array => $callback(),
            function () use (&$sequence): array {
                $sequence[] = 'journal';

                return ['id' => 101];
            },
            function () use (&$sequence): array {
                $sequence[] = 'movement';

                return ['id' => 202];
            },
            function () use (&$sequence): array {
                $sequence[] = 'allocation';

                return ['id' => 303];
            },
            function () use (&$sequence): array {
                $sequence[] = 'linkage';

                return ['linkage_id' => 404];
            },
        );

        $this->assertSame(['journal', 'movement', 'allocation', 'linkage'], $sequence);
        $this->assertSame(['journal_id' => 101, 'movement_id' => 202, 'allocation_id' => 303, 'linkage_id' => 404], $result);
    }

    public function test_transaction_runner_can_rollback_when_linkage_fails(): void
    {
        $writes = [];
        $this->expectException(ValidationException::class);

        $this->workflow()->execute(
            $this->readyPlan(), true,
            function (callable $callback) use (&$writes): never {
                try {
                    $callback();
                } catch (\Throwable $exception) {
                    $writes = [];
                    throw $exception;
                }

                throw new \LogicException('transaction must not return in this test');
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
            function (): array {
                throw ValidationException::withMessages(['linkage' => 'linkage proof failed']);
            },
        );
    }

    private function workflow(): InventoryPurchaseAtomicWorkflow
    {
        return new InventoryPurchaseAtomicWorkflow;
    }

    private function readyPlan(): array
    {
        return [
            'ready' => true,
            'creates_journal' => true,
            'plan' => [
                'lock_order' => ['purchase_document', 'journal_book', 'fiscal_period', 'stock_movement', 'cost_allocations', 'cost_layers', 'stock_balance'],
            ],
            'payload' => ['event_code' => 'supplier_invoice.inventory', 'lines' => []],
        ];
    }
}
