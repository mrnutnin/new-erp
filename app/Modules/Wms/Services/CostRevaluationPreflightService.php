<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Wms\Models\CostRevaluationRun;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CostRevaluationPreflightService
{
    public function __construct(private readonly CostAccountingProofPolicy $proofPolicy) {}

    /** @param list<int>|null $deltaIds */
    public function check(CostRevaluationRun $run, ?array $deltaIds = null): array
    {
        $checks = [];
        $blockers = [];
        $deltas = $deltaIds === null
            ? $run->loadMissing('deltas.allocation.movement')->deltas
            : $run->deltas()->whereIn('id', $deltaIds)->with('allocation.movement')->orderBy('id')->get();

        $this->addCheck($checks, $blockers, 'delta_count', $deltas->isNotEmpty(), 'Run ต้องมี Planned Delta อย่างน้อยหนึ่งรายการก่อน Approve/Apply/GL');

        $contract = (string) ($run->shadow_snapshot['calculation_contract_version'] ?? '');
        $supportedContracts = [
            'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof',
            'cost-shadow-v2-fifo',
            'cost-shadow-v2-async',
        ];
        $this->addCheck($checks, $blockers, 'calculation_contract', in_array($contract, $supportedContracts, true), 'Run ต้องสร้างจาก calculation contract รุ่นปัจจุบัน; legacy snapshot ต้องคำนวณใหม่');
        $this->addCheck($checks, $blockers, 'variance', ($run->shadow_snapshot['variance_report']['status'] ?? null) === 'READY_FOR_REVIEW', 'Variance Report ต้องไม่มี blocker');
        $postingDate = $run->posting_date?->format('Y-m-d');
        $periodReady = $postingDate !== null && FiscalPeriod::query()->where('start_date', '<=', $postingDate)->where('end_date', '>=', $postingDate)->where('status', 'OPEN')->exists();
        $this->addCheck($checks, $blockers, 'posting_period', $periodReady, 'วันที่ลงบัญชีต้องอยู่ในงวดบัญชีที่เปิดอยู่');

        $events = $deltas->pluck('target_event')->filter()->unique()->values();
        foreach ($events as $event) {
            $mappingReady = app(AccountMappingService::class)->readiness((string) $event)['ready'];
            $this->addCheck($checks, $blockers, 'mapping_'.str_replace('.', '_', (string) $event), $mappingReady, "Account Mapping สำหรับ {$event} ต้องพร้อม");
        }

        foreach ($deltas as $delta) {
            $classified = $delta->impact_bucket !== null
                && $delta->target_warehouse_id !== null
                && $delta->target_branch_id !== null
                && ($delta->target_event !== null || $delta->impact_bucket === 'TRANSFER_BRIDGE');
            $this->addCheck($checks, $blockers, 'classification_'.$delta->allocation_id, $classified, 'Delta ต้องมี Impact bucket, Accounting event และ Warehouse/Branch scope');

            $allocation = $delta->allocation;
            $allocationReady = $allocation && $allocation->status !== 'REVERSED' && (string) $allocation->unit_cost === (string) $delta->old_unit_cost;
            $this->addCheck($checks, $blockers, 'allocation_'.$delta->allocation_id, $allocationReady, $allocationReady ? 'Allocation ยังตรงกับ Snapshot' : 'Allocation ถูกเปลี่ยนแปลงหรือกลับรายการหลังสร้าง Run');

            $movementReady = $allocation?->movement && $allocation->movement->status === 'POSTED';
            $this->addCheck($checks, $blockers, 'movement_'.$delta->allocation_id, $movementReady, 'Movement ต้นทางต้องเป็น POSTED');

            if (! BigDecimal::of((string) $delta->stock_projection_delta_value)->isZero()) {
                $balanceReady = $allocation && DB::table('wms_stock_balances')->where('warehouse_id', $delta->target_warehouse_id)->where('item_id', $allocation->item_id)->where('uom_id', $allocation->uom_id)->exists();
                $this->addCheck($checks, $blockers, 'stock_projection_'.$delta->allocation_id, $balanceReady, 'ไม่พบ Stock Projection ของ Warehouse/Item/UOM ที่มี ending on-hand impact');
            }

            if (Schema::hasTable('wms_cost_allocation_journal_lines') && $delta->impact_bucket !== 'TRANSFER_BRIDGE' && $this->proofPolicy->requiresJournalProof($allocation)) {
                $journalReady = $allocation && $allocation->journal_entry_id !== null && DB::table('wms_cost_allocation_journal_lines as links')->join('journal_entry_lines as lines', 'lines.id', '=', 'links.journal_entry_line_id')->where('links.allocation_id', $allocation->id)->where('links.revision', $allocation->revision)->where('lines.journal_entry_id', $allocation->journal_entry_id)->whereNotNull('links.identity_key')->exists();
                $this->addCheck($checks, $blockers, 'journal_'.$delta->allocation_id, $journalReady, 'ไม่พบ Journal Link ที่ตรงกับ Allocation revision ปัจจุบัน');
            }
        }

        return ['ready' => $blockers === [], 'blockers' => array_values(array_unique($blockers)), 'checks' => $checks, 'read_only' => true];
    }

    private function addCheck(array &$checks, array &$blockers, string $code, bool $ready, string $detail): void
    {
        $checks[] = ['code' => $code, 'status' => $ready ? 'PASS' : 'BLOCKED', 'detail' => $detail];
        if (! $ready) {
            $blockers[] = $code;
        }
    }
}
