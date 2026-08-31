<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Support\InventoryPurchaseReversalContract;
use Illuminate\Validation\ValidationException;

/** Closed-by-default orchestration boundary for immutable Purchase reversal. */
final class InventoryPurchaseReversalService
{
    public function __construct(private readonly InventoryPurchaseReversalContract $contract = new InventoryPurchaseReversalContract) {}

    public function preflight(array $source, array $request): array
    {
        return $this->contract::plan($source, $request);
    }

    public function execute(
        array $source,
        array $request,
        bool $featureEnabled = false,
        ?callable $transaction = null,
        ?callable $journal = null,
        ?callable $movement = null,
        ?callable $allocation = null,
        ?callable $linkage = null,
        ?callable $reconcile = null,
    ): array {
        $plan = $this->preflight($source, $request);
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['reversal' => 'Inventory Purchase reversal ยังไม่เปิดใช้งาน']);
        }
        if (! $transaction || ! $journal || ! $movement || ! $allocation || ! $linkage || ! $reconcile) {
            throw ValidationException::withMessages(['reversal' => 'Reversal transaction callbacks ยังไม่ครบ']);
        }

        return $transaction(function () use ($plan, $source, $journal, $movement, $allocation, $linkage, $reconcile): array {
            $journalResult = $journal($plan, $source);
            $movementResult = $movement($plan, $source, $journalResult);
            $allocationResult = $allocation($plan, $source, $movementResult);
            $linkageResult = $linkage($plan, $source, $journalResult, $movementResult, $allocationResult);
            if ($reconcile($plan, $source, $journalResult, $movementResult, $allocationResult, $linkageResult) !== true) {
                throw ValidationException::withMessages(['reconciliation' => 'Reversal reconciliation ไม่เป็นศูนย์']);
            }

            return [
                'idempotency_key' => $plan['idempotency_key'],
                'payload_hash' => $plan['payload_hash'],
                'revision' => $plan['revision'],
                'journal_id' => $this->idOf($journalResult),
                'movement_id' => $this->idOf($movementResult),
                'allocation_id' => $this->idOf($allocationResult),
                'linkage_id' => $this->idOf($linkageResult),
                'source_unchanged' => true,
            ];
        });
    }

    private function idOf(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value['id'] ?? $value['linkage_id'] ?? null;
        }

        return is_object($value) ? ($value->id ?? null) : $value;
    }
}
