<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Support\CreditPurchaseInventoryReversalContract;
use Illuminate\Validation\ValidationException;

/** Closed-by-default orchestration boundary for full-line Credit Purchase reversal. */
final class CreditPurchaseInventoryReversalService
{
    public function preflight(array $source, array $request): array
    {
        return CreditPurchaseInventoryReversalContract::plan($source, $request);
    }

    public function execute(
        array $source,
        array $request,
        bool $featureEnabled = false,
        ?callable $transaction = null,
        ?callable $movement = null,
        ?callable $allocation = null,
        ?callable $linkage = null,
        ?callable $reconcile = null,
    ): array {
        $plan = $this->preflight($source, $request);
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['reversal' => 'Credit Purchase → GR Inventory reversal ยังไม่เปิดใช้งาน']);
        }
        if (! $transaction || ! $movement || ! $allocation || ! $linkage || ! $reconcile) {
            throw ValidationException::withMessages(['reversal' => 'Credit Purchase reversal callbacks ยังไม่ครบ']);
        }

        return $transaction(function () use ($plan, $source, $movement, $allocation, $linkage, $reconcile): array {
            $movementResult = $movement($plan, $source);
            $allocationResult = $allocation($plan, $source, $movementResult);
            $linkageResult = $linkage($plan, $source, $allocationResult, $movementResult);
            if ($reconcile($plan, $source, $movementResult, $allocationResult, $linkageResult) !== true) {
                throw ValidationException::withMessages(['reconciliation' => 'Credit Purchase reversal reconciliation ไม่เป็นศูนย์']);
            }

            return [
                'idempotency_key' => $plan['idempotency_key'],
                'payload_hash' => $plan['payload_hash'],
                'revision' => $plan['revision'],
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
