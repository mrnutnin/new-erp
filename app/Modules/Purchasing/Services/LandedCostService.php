<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\LandedCostAllocationCalculator;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LandedCostService
{
    public function createDraft(array $attributes, ?User $actor = null): LandedCost
    {
        return DB::transaction(function () use ($attributes, $actor): LandedCost {
            $key = trim((string) ($attributes['idempotency_key'] ?? ''));
            if ($key === '') {
                throw ValidationException::withMessages(['idempotency_key' => 'ต้องระบุ idempotency key']);
            }

            $existing = LandedCost::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load(['lines', 'receipts', 'allocations']);
            }

            $warehouseId = (int) ($attributes['warehouse_id'] ?? 0);
            $basis = strtoupper(trim((string) ($attributes['allocation_basis'] ?? 'VALUE')));
            $basisKey = strtolower($basis);
            $date = (string) ($attributes['business_date'] ?? '');
            $receipts = $this->loadReceipts($warehouseId, $attributes['receipt_ids'] ?? []);
            $charges = $this->normalizeCharges($attributes['lines'] ?? []);
            $targets = $this->receiptLineTargets($receipts, $basis);
            $preview = LandedCostAllocationCalculator::preview($targets, $charges, $basis);

            $landedCost = LandedCost::query()->create([
                'warehouse_id' => $warehouseId,
                'document_number' => trim((string) ($attributes['document_number'] ?? '')),
                'business_date' => $date,
                'status' => 'DRAFT',
                'allocation_basis' => $basis,
                'currency_code' => strtoupper((string) ($attributes['currency_code'] ?? 'THB')),
                'total_amount' => $preview['total'],
                'idempotency_key' => $key,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'metadata' => ['allocation_basis' => $basis, 'calculator_version' => 1],
            ]);

            foreach ($charges as $charge) {
                $line = $landedCost->lines()->create([
                    'expense_source_type' => $charge['source_type'],
                    'expense_source_id' => $charge['source_id'],
                    'account_id' => $charge['account_id'],
                    'amount' => $charge['amount'],
                    'tax_code_id' => $charge['tax_code_id'],
                    'description' => $charge['description'],
                ]);
                foreach (array_values(array_filter($preview['allocations'], fn (array $allocation): bool => $allocation['charge_id'] === $charge['id'])) as $allocation) {
                    $target = collect($targets)->firstWhere('id', $allocation['target_id']);
                    $landedCost->allocations()->create([
                        'landed_cost_line_id' => $line->id,
                        'goods_receipt_line_id' => $allocation['target_id'],
                        'item_id' => $target['item_id'],
                        'uom_id' => $target['uom_id'],
                        'basis_amount' => $allocation['basis'],
                        'allocation_ratio' => $allocation['ratio'],
                        'allocated_amount' => $allocation['amount'],
                        'idempotency_key' => "landed-cost:{$key}:charge:{$charge['id']}:target:{$allocation['target_id']}",
                        'metadata' => ['basis' => $basis],
                    ]);
                }
            }

            foreach ($receipts as $receipt) {
                $selected = collect($targets)->where('receipt_id', $receipt->id)->reduce(fn (BigDecimal $sum, array $target): BigDecimal => $sum->plus($target[$basisKey]), BigDecimal::zero());
                $allocated = collect($preview['allocations'])->filter(fn (array $allocation): bool => collect($targets)->firstWhere('id', $allocation['target_id'])['receipt_id'] === $receipt->id)->reduce(fn (BigDecimal $sum, array $allocation): BigDecimal => $sum->plus($allocation['amount']), BigDecimal::zero());
                $landedCost->receipts()->create(['goods_receipt_id' => $receipt->id, 'selected_value' => $selected->toScale(8)->__toString(), 'allocated_amount' => $allocated->toScale(8)->__toString()]);
            }

            return $landedCost->load(['lines', 'receipts', 'allocations']);
        }, 3);
    }

    public function submit(LandedCost $landedCost, User $actor): LandedCost
    {
        return $this->transition($landedCost, 'DRAFT', 'SUBMITTED', $actor);
    }

    public function approve(LandedCost $landedCost, User $actor): LandedCost
    {
        return $this->transition($landedCost, 'SUBMITTED', 'APPROVED', $actor);
    }

    public function void(LandedCost $landedCost, User $actor): LandedCost
    {
        return DB::transaction(function () use ($landedCost, $actor): LandedCost {
            $locked = LandedCost::query()->lockForUpdate()->findOrFail($landedCost->id);
            if (! in_array($locked->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true)) {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะ Landed Cost ที่ยังไม่ Post']);
            }
            $locked->update(['status' => 'VOID', 'updated_by' => $actor->id]);

            return $locked->fresh();
        }, 3);
    }

    private function transition(LandedCost $landedCost, string $from, string $to, User $actor): LandedCost
    {
        return DB::transaction(function () use ($landedCost, $from, $to, $actor): LandedCost {
            $locked = LandedCost::query()->lockForUpdate()->findOrFail($landedCost->id);
            if ($locked->status !== $from) {
                throw ValidationException::withMessages(['status' => "เปลี่ยนสถานะเป็น {$to} ได้จาก {$from} เท่านั้น"]);
            }
            $locked->update(['status' => $to, 'updated_by' => $actor->id]);

            return $locked->fresh();
        }, 3);
    }

    /** @return array<int, GoodsReceipt> */
    private function loadReceipts(int $warehouseId, mixed $ids): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($warehouseId < 1 || $ids->isEmpty()) {
            throw ValidationException::withMessages(['receipts' => 'ต้องเลือก Warehouse และ Goods Receipt อย่างน้อยหนึ่งรายการ']);
        }
        $receipts = GoodsReceipt::query()->with('lines')->where('warehouse_id', $warehouseId)->whereIn('id', $ids)->where('status', 'APPROVED')->lockForUpdate()->get();
        if ($receipts->count() !== $ids->count()) {
            throw ValidationException::withMessages(['receipts' => 'Goods Receipt ต้องอยู่ในคลังเดียวกันและมีสถานะ Approved']);
        }
        foreach ($receipts as $receipt) {
            if (! DB::table('wms_stock_movements')->where('source_type', 'GOODS_RECEIPT')->where('source_id', $receipt->id)->where('status', 'POSTED')->exists()) {
                throw ValidationException::withMessages(['receipts' => "Goods Receipt {$receipt->receipt_number} ยังไม่ถูก Post เข้า Stock"]);
            }
        }

        return $receipts->all();
    }

    /** @return array<int, array{id:int,source_type:string,source_id:int|null,account_id:int,amount:string,tax_code_id:int|null,description:string|null}> */
    private function normalizeCharges(mixed $input): array
    {
        if (! is_array($input) || $input === []) {
            throw ValidationException::withMessages(['lines' => 'ต้องมีรายการค่าใช้จ่ายอย่างน้อยหนึ่งรายการ']);
        }
        return collect($input)->values()->map(function (array $line, int $index): array {
            $amount = BigDecimal::of((string) ($line['amount'] ?? '0'));
            if ((int) ($line['account_id'] ?? 0) < 1 || $amount->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(["lines.{$index}" => 'รายการค่าใช้จ่ายต้องมีบัญชีและจำนวนเงินมากกว่าศูนย์']);
            }
            return ['id' => $index + 1, 'source_type' => strtoupper((string) ($line['source_type'] ?? 'MANUAL')), 'source_id' => ($line['source_id'] ?? null) ? (int) $line['source_id'] : null, 'account_id' => (int) $line['account_id'], 'amount' => $amount->toScale(8)->__toString(), 'tax_code_id' => ($line['tax_code_id'] ?? null) ? (int) $line['tax_code_id'] : null, 'description' => $line['description'] ?? null];
        })->all();
    }

    /** @return array<int, array{id:int,receipt_id:int,item_id:int,uom_id:int,value:string,quantity:string,weight:string}> */
    private function receiptLineTargets(array $receipts, string $basis): array
    {
        $targets = [];
        foreach ($receipts as $receipt) {
            foreach ($receipt->lines as $line) {
                $value = (string) $line->total_cost;
                $quantity = (string) $line->stock_quantity;
                $weight = (string) ($line->conversion_snapshot['weight'] ?? '0');
                if ($basis === 'WEIGHT' && BigDecimal::of($weight)->isLessThanOrEqualTo(0)) {
                    throw ValidationException::withMessages(['allocation_basis' => 'ยังไม่มีน้ำหนักใน Goods Receipt line สำหรับกระจายตามน้ำหนัก']);
                }
                $targets[] = ['id' => (int) $line->id, 'receipt_id' => (int) $receipt->id, 'item_id' => (int) $line->item_id, 'uom_id' => (int) $line->stock_uom_id, 'value' => $value, 'quantity' => $quantity, 'weight' => $weight];
            }
        }
        return $targets;
    }
}
