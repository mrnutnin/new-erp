<?php

namespace App\Modules\Platform\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Models\InternalTransfer;
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Models\PettyCashClearing;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Settings\Support\SettingRegistry;
use App\Modules\Wms\Models\CostRecalculationRequest;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\StockCountDocument;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Services\InventoryPostingPreflightService;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseRequisition;

final class WorkflowRuntimeResolver
{
    public function __construct(
        private readonly GlobalSettings $settings,
        private readonly ?AccountMappingService $accountMappings = null,
    ) {}

    public function snapshot(string $module, User $user, ?int $warehouseId = null): WorkflowRuntimeSnapshot
    {
        return match ($module) {
            'settings' => $this->settingsSnapshot($user),
            'wms' => $this->wmsSnapshot($user, $warehouseId),
            'finance' => $this->financeSnapshot($user, $warehouseId),
            'accounting' => $this->accountingSnapshot($user, $warehouseId),
            'pos' => $this->posSnapshot($user, $warehouseId),
            'asset' => $this->assetSnapshot(),
            default => new WorkflowRuntimeSnapshot($module),
        };
    }

    public function decorate(string $module, array $workflows, User $user, ?int $warehouseId = null): array
    {
        $snapshot = $this->snapshot($module, $user, $warehouseId);
        $readiness = collect($snapshot->readiness)->keyBy('route');
        $eventReadiness = collect($snapshot->readiness)->filter(fn (array $item): bool => ! empty($item['event_code']))->keyBy('event_code');
        $pending = collect($snapshot->pending)->keyBy('route');

        return array_map(function (array $workflow) use ($readiness, $eventReadiness, $pending, $user): array {
            if (! empty($workflow['decision_cards'])) {
                $workflow['decision_cards'] = array_map(function (array $decision) use ($readiness, $eventReadiness, $pending, $user): array {
                    $route = $decision['route'] ?? null;
                    $eventCode = $decision['event_code'] ?? null;
                    if (($eventCode && $eventReadiness->has($eventCode)) || ($route && $readiness->has($route))) {
                        $runtime = $eventCode ? $eventReadiness->get($eventCode) : $readiness->get($route);
                        $decision['readiness'] = $runtime;
                        $decision['runtime_not_ready'] = ($runtime['status'] ?? null) === 'NOT_READY';
                        $decision['configuration_warning'] = (bool) ($runtime['configuration_warning'] ?? false);
                        if (is_array($runtime) && array_key_exists('block_reason', $runtime)) {
                            $decision['block_reason'] = $runtime['block_reason'];
                        }
                        $decision['next_action'] = $runtime['next_action'] ?? $decision['next_action'] ?? null;
                        $decision['recovery_url'] = $runtime['recovery_url'] ?? null;
                        $decision['recovery_label'] = $runtime['recovery_label'] ?? null;
                        $decision['recovery_permission'] = $runtime['recovery_permission'] ?? null;
                    }

                    if ($route && $pending->has($route)) {
                        $decision['pending_count'] = (int) ($pending->get($route)['count'] ?? 0);
                        $decision['pending_label'] = $pending->get($route)['label'] ?? null;
                    }

                    return WorkflowStepPresenter::present($decision, $user);
                }, $workflow['decision_cards']);
            }

            $workflow['steps'] = array_map(function (array $step) use ($readiness, $eventReadiness, $pending): array {
                $route = $step['route'] ?? null;
                $eventCode = $step['event_code'] ?? null;
                if (($eventCode && $eventReadiness->has($eventCode)) || ($route && $readiness->has($route))) {
                    $runtime = $eventCode ? $eventReadiness->get($eventCode) : $readiness->get($route);
                    $step['readiness'] = $runtime;
                    $step['runtime_not_ready'] = ($runtime['status'] ?? null) === 'NOT_READY';
                    $step['configuration_warning'] = (bool) ($runtime['configuration_warning'] ?? false);
                    if (is_array($runtime) && array_key_exists('block_reason', $runtime)) {
                        $step['block_reason'] = $runtime['block_reason'];
                    }
                    $step['next_action'] = $runtime['next_action'] ?? $step['next_action'] ?? null;
                    $step['recovery_url'] = $runtime['recovery_url'] ?? null;
                    $step['recovery_label'] = $runtime['recovery_label'] ?? null;
                    $step['recovery_permission'] = $runtime['recovery_permission'] ?? null;
                }
                if ($route && $pending->has($route)) {
                    $pendingRuntime = $pending->get($route);
                    $step['pending_count'] = (int) ($pendingRuntime['count'] ?? 0);
                    $step['pending_label'] = $pendingRuntime['label'] ?? null;
                    $step['pending'] = $pendingRuntime;
                }

                return $step;
            }, $workflow['steps']);

            return $workflow;
        }, $workflows);
    }

    private function settingsSnapshot(User $user): WorkflowRuntimeSnapshot
    {
        $readiness = [];
        if ($user->hasPermission('settings.company.view')) {
            $missing = array_merge(...array_values(array_map(
                fn (string $module): array => $this->settings->missingFor($module),
                array_keys(SettingRegistry::REQUIRED),
            )));
            $readiness[] = $this->readiness('settings.global', count($missing), 'ตรวจ Global Settings ให้ครบก่อนเริ่มใช้งาน', 'settings.company.edit', 'settings.company.view');
        }
        if ($user->hasPermission('settings.branches.view')) {
            $readiness[] = $this->readiness('settings.branches', Branch::query()->where('is_active', true)->count(), 'สร้างสาขาอย่างน้อยหนึ่งรายการ', 'settings.branches.index', 'settings.branches.view', true);
        }
        if ($user->hasPermission('settings.warehouses.view')) {
            $readiness[] = $this->readiness('settings.warehouses', Warehouse::query()->where('is_active', true)->count(), 'สร้างคลังอย่างน้อยหนึ่งรายการ', 'settings.warehouses.index', 'settings.warehouses.view', true);
        }

        return new WorkflowRuntimeSnapshot('settings', $readiness);
    }

    private function wmsSnapshot(User $user, ?int $warehouseId): WorkflowRuntimeSnapshot
    {
        $pending = [];
        $readiness = $this->postingDefaultReadiness([
            'supplier_invoice.inventory',
            'supplier_invoice.expense',
        ]);
        $mappings = $this->accountMappings ?? app(AccountMappingService::class);
        $mappingBlockers = [];
        foreach (['INVENTORY_DEFAULT', 'COGS_DEFAULT'] as $key) {
            try {
                $mappings->resolve($key);
            } catch (\Throwable $exception) {
                $mappingBlockers[] = $exception->getMessage();
            }
        }
        $adjustmentReadiness = $mappings->readiness('inventory_adjustment');
        if (! $adjustmentReadiness['ready']) {
            $mappingBlockers[] = $adjustmentReadiness['blockers'][0]['message'] ?? 'Inventory Adjustment Mapping ยังไม่พร้อม';
        }
        $readiness[] = [
            'code' => 'inventory.mapping',
            'event_code' => 'inventory.mapping',
            'status' => $mappingBlockers === [] ? 'READY' : 'NOT_READY',
            'configuration_warning' => $mappingBlockers !== [],
            'missing_count' => count($mappingBlockers),
            'block_reason' => $mappingBlockers === [] ? null : $mappingBlockers[0],
            'next_action' => $mappingBlockers === [] ? 'เริ่มขั้นตอน Inventory ได้' : 'เปิด Account Mapping เพื่อตั้งค่าบัญชี Inventory/COGS',
            'route' => null,
        ];
        $inventoryMissing = $this->settings->missingFor('inventory');
        $inventorySettingsRoute = \Route::has('settings.company.edit') ? route('settings.company.edit') : null;
        $readiness[] = [
            'code' => 'inventory.cost_policy',
            'event_code' => 'inventory.cost_policy',
            'status' => $inventoryMissing === [] ? 'READY' : 'NOT_READY',
            'configuration_warning' => $inventoryMissing !== [],
            'missing_count' => count($inventoryMissing),
            'block_reason' => $inventoryMissing === [] ? null : 'ค่าที่ยังขาด: '.implode(', ', $inventoryMissing),
            'next_action' => $inventoryMissing === [] ? 'เริ่มงานคลังได้' : 'เปิด Global Settings เพื่อตั้งค่า Inventory',
            'recovery_url' => $inventorySettingsRoute,
            'recovery_label' => 'เปิด Global Settings',
            'recovery_permission' => 'settings.company.view',
            'route' => $inventorySettingsRoute ? 'settings.company.edit' : null,
        ];
        $this->appendInventoryGateReadiness($readiness, $warehouseId);
        if ($warehouseId !== null && $user->hasPermission('wms.stock-valuation.view')) {
            try {
                $summary = app(InventoryPostingPreflightService::class)->summary($warehouseId);
                $blockers = array_values(array_filter([
                    ((int) ($summary['pending'] ?? 0)) > 0 ? 'pending_cost_allocations' : null,
                    ((int) ($summary['unlinked'] ?? 0)) > 0 ? 'unlinked_allocations' : null,
                    ((int) ($summary['lineUnlinked'] ?? 0)) > 0 ? 'unlinked_journal_line_proof' : null,
                    ((int) ($summary['lineMismatched'] ?? 0)) > 0 ? 'mismatched_journal_line_proof' : null,
                    ((int) ($summary['lineProofMissing'] ?? 0)) > 0 ? 'journal_line_proof_unavailable' : null,
                    ((int) ($summary['missingSource'] ?? 0)) > 0 ? 'missing_source_identity' : null,
                ]));
                $readiness[] = $this->inventoryGateReadiness('inventory.preflight', $blockers, $route = \Route::has('wms.stock-valuation.index') ? route('wms.stock-valuation.index') : null);
            } catch (\Throwable) {
                $readiness[] = $this->inventoryGateReadiness('inventory.preflight', ['preflight_unavailable'], null);
            }
        }
        if ($user->hasPermission('wms.items.view')) {
            $readiness[] = $this->readiness('wms.items', Item::query()->where('is_active', true)->count(), 'สร้างข้อมูลสินค้าให้พร้อมก่อนทำรายการ', 'wms.items.index', 'wms.items.view', true);
        }
        if ($warehouseId !== null && $user->hasPermission('wms.opening-balances.view')) {
            $readiness[] = $this->readiness(
                'wms.opening-balances',
                OpeningBalanceBatch::query()->where('warehouse_id', $warehouseId)->where('status', 'POSTED')->count(),
                'สร้างหรือนำเข้า Opening Balance แล้ว Post ให้เรียบร้อยก่อนเริ่มรับ–จ่าย',
                'wms.opening-balances.index',
                'wms.opening-balances.view',
                true,
            );
        }
        if ($warehouseId !== null && $user->hasPermission('purchasing.purchase-documents.view')) {
            $pending[] = $this->pending('purchasing.purchase-drafts', PurchaseDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(), 'ใบซื้อรอดำเนินการ', 'purchasing.purchase-documents.index', 'purchasing.purchase-documents.view');
        }
        if ($warehouseId !== null && $user->hasPermission('purchasing.purchase-requisitions.view')) {
            $pending[] = $this->pending('purchasing.purchase-requisitions', PurchaseRequisition::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'REJECTED'])->count(), 'ใบขอซื้อที่ยังดำเนินการไม่เสร็จ', 'purchasing.purchase-requisitions.index', 'purchasing.purchase-requisitions.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.stock-valuation.view')) {
            $pending[] = $this->pending('wms.recost', CostRecalculationRequest::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['PENDING', 'PROCESSING', 'FAILED', 'STALE'])->count(), 'รายการ Recost ที่ต้องดำเนินการ/ตรวจสอบ', 'wms.stock-valuation.index', 'wms.stock-valuation.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.opening-balances.view')) {
            $pending[] = $this->pending('wms.opening-balances', OpeningBalanceBatch::query()->where('warehouse_id', $warehouseId)->where('status', 'DRAFT')->count(), 'Opening Balance ที่รอ Post', 'wms.opening-balances.index', 'wms.opening-balances.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.issues.view')) {
            $pending[] = $this->pending('wms.issues', IssueDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(), 'ใบเบิกที่ยังดำเนินการไม่เสร็จ', 'wms.issues.index', 'wms.issues.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.issue-returns.view')) {
            $pending[] = $this->pending('wms.issue-returns', IssueReturn::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(), 'ใบรับคืนที่ยังดำเนินการไม่เสร็จ', 'wms.issue-returns.index', 'wms.issue-returns.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.stock-counts.view')) {
            $pending[] = $this->pending('wms.stock-counts', StockCountDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'COUNTED', 'APPROVED'])->count(), 'Stock Count ที่ยังดำเนินการไม่เสร็จ', 'wms.stock-counts.index', 'wms.stock-counts.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.inventory-adjustments.view')) {
            $pending[] = $this->pending('wms.inventory-adjustments', InventoryAdjustmentDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(), 'Inventory Adjustment ที่ยังดำเนินการไม่เสร็จ', 'wms.inventory-adjustments.index', 'wms.inventory-adjustments.view');
        }
        if ($warehouseId !== null && $user->hasPermission('wms.transfers.view')) {
            $pending[] = $this->pending('wms.transfers', Transfer::query()->where(fn ($query) => $query->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId))->whereIn('status', ['DRAFT', 'DISPATCHED', 'PARTIALLY_ACCEPTED', 'REJECTED'])->count(), 'Transfer ที่ยังดำเนินการไม่เสร็จ', 'wms.transfers.index', 'wms.transfers.view');
        }

        return new WorkflowRuntimeSnapshot('wms', $readiness, $pending);
    }

    /** @param array<int, array<string, mixed>> $readiness */
    private function appendInventoryGateReadiness(array &$readiness, ?int $warehouseId): void
    {
        $codes = ['inventory_purchase_event_wiring', 'atomic_journal_movement_allocation_linkage', 'reconciliation_zero_gate', 'inventory_reconciliation_resolve'];
        $route = \Route::has('wms.stock-valuation.index') ? route('wms.stock-valuation.index') : null;

        if ($warehouseId === null) {
            foreach ($codes as $code) {
                $readiness[] = $this->inventoryGateReadiness($code, ['warehouse_scope_required'], $route);
            }

            return;
        }

        try {
            $summary = app(InventoryPostingPreflightService::class)->summary($warehouseId);
            $gates = [
                'inventory_purchase_event_wiring' => array_values(array_filter([
                    ((int) ($summary['missingSource'] ?? 0)) > 0 ? 'missing_source_identity' : null,
                    ((int) ($summary['missingInventory'] ?? 0)) > 0 ? 'missing_inventory_mapping' : null,
                ])),
                'atomic_journal_movement_allocation_linkage' => array_values(array_filter([
                    ((int) ($summary['unlinked'] ?? 0)) > 0 ? 'unlinked_allocations' : null,
                    ((int) ($summary['lineUnlinked'] ?? 0)) > 0 ? 'unlinked_journal_line_proof' : null,
                    ((int) ($summary['lineMismatched'] ?? 0)) > 0 ? 'mismatched_journal_line_proof' : null,
                    ((int) ($summary['lineProofMissing'] ?? 0)) > 0 ? 'journal_line_proof_unavailable' : null,
                ])),
                'reconciliation_zero_gate' => ($summary['reconciliation_ready'] ?? false) === true
                    ? [] : ($summary['reconciliation_blockers'] ?? ['reconciliation_not_ready']),
                'inventory_reconciliation_resolve' => ($summary['reconciliation_ready'] ?? false) === true
                    ? [] : ($summary['reconciliation_blockers'] ?? ['reconciliation_not_ready']),
            ];
            foreach ($gates as $code => $blockers) {
                $recoveryRoute = $code === 'inventory_reconciliation_resolve' && \Route::has('accounting.reports.reconciliation.index')
                    ? route('accounting.reports.reconciliation.index') : $route;
                $readiness[] = $this->inventoryGateReadiness($code, $blockers, $recoveryRoute);
            }
        } catch (\Throwable) {
            foreach ($codes as $code) {
                $readiness[] = $this->inventoryGateReadiness($code, ['preflight_unavailable'], $route);
            }
        }
    }

    /** @param array<int, string> $blockers */
    private function inventoryGateReadiness(string $code, array $blockers, ?string $route): array
    {
        $ready = $blockers === [];

        return [
            'code' => $code, 'event_code' => $code,
            'status' => $ready ? 'READY' : 'NOT_READY', 'configuration_warning' => false,
            'missing_count' => count($blockers),
            'block_reason' => $ready ? null : 'ตรวจพบ blocker: '.implode(', ', $blockers),
            'next_action' => $ready ? 'ผ่าน gate แล้ว สามารถตรวจขั้นตอนถัดไป' : 'เปิด Stock Valuation เพื่อตรวจและแก้ blocker',
            'recovery_url' => $route, 'recovery_label' => 'เปิดหน้าตรวจสอบ',
            'recovery_permission' => 'wms.stock-valuation.view', 'route' => null,
        ];
    }

    private function financeSnapshot(User $user, ?int $warehouseId): WorkflowRuntimeSnapshot
    {
        $readiness = $this->postingDefaultReadiness([
            'customer_payment',
            'customer_advance',
            'supplier_payment',
        ]);
        $pending = [];
        if ($warehouseId !== null && $user->hasPermission('finance.bank-accounts.view')) {
            $readiness[] = $this->readiness('finance.bank-accounts', BankAccount::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->count(), 'ตั้งค่าบัญชีเงินสดหรือธนาคารก่อนรับจ่าย', 'finance.bank-accounts.index', 'finance.bank-accounts.view', true);
        }
        if ($warehouseId !== null && $user->hasPermission('finance.payment-vouchers.view')) {
            $pending[] = $this->pending('finance.vouchers', PaymentVoucher::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'ใบขอจ่ายที่ยังดำเนินการไม่เสร็จ', 'finance.payment-vouchers.index', 'finance.payment-vouchers.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.settlements.view')) {
            $pending[] = $this->pending('finance.settlements', Settlement::query()->whereHas('bankAccount', fn ($query) => $query->where('warehouse_id', $warehouseId))->whereIn('status', ['DRAFT', 'APPROVED'])->count(), 'รายการรับจ่ายที่ยังไม่ Post', 'finance.settlements.index', 'finance.settlements.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.petty-cash.view')) {
            $pending[] = $this->pending('finance.petty-cash', PettyCashVoucher::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'ใบสำคัญเงินสดย่อยที่ยังดำเนินการไม่เสร็จ', 'finance.petty-cash.index', 'finance.petty-cash.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.petty-cash-top-ups.view')) {
            $pending[] = $this->pending('finance.petty-cash-top-ups', PettyCashTopUp::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'รายการเติมเงินสดย่อยที่ยังไม่ Post', 'finance.petty-cash-top-ups.index', 'finance.petty-cash-top-ups.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.petty-cash-clearings.view')) {
            $pending[] = $this->pending('finance.petty-cash-clearings', PettyCashClearing::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'รายการเคลียร์เงินสดย่อยที่ยังไม่ Post', 'finance.petty-cash-clearings.index', 'finance.petty-cash-clearings.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.employee-advances.view')) {
            $pending[] = $this->pending('finance.employee-advances', EmployeeAdvance::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'ใบเงินทดรองที่ยังดำเนินการไม่เสร็จ', 'finance.employee-advances.index', 'finance.employee-advances.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.employee-advance-clearings.view')) {
            $pending[] = $this->pending('finance.employee-advance-clearings', EmployeeAdvanceClearing::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'รายการเคลียร์เงินทดรองที่ยังไม่ Post', 'finance.employee-advance-clearings.index', 'finance.employee-advance-clearings.view');
        }
        if ($warehouseId !== null && $user->hasPermission('finance.internal-transfers.view')) {
            $pending[] = $this->pending('finance.internal-transfers', InternalTransfer::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])->count(), 'รายการโอนเงินภายในที่ยังไม่ Post', 'finance.internal-transfers.index', 'finance.internal-transfers.view');
        }

        return new WorkflowRuntimeSnapshot('finance', $readiness, $pending);
    }

    private function accountingSnapshot(User $user, ?int $warehouseId): WorkflowRuntimeSnapshot
    {
        $readiness = [];
        $pending = [];
        if ($user->hasPermission('accounting.accounts.view')) {
            $readiness[] = $this->readiness('accounting.accounts.index', Account::query()->where('is_active', true)->where('is_postable', true)->count(), 'สร้างผังบัญชีที่ลงรายการได้ก่อนเริ่มบันทึก', 'accounting.accounts.index', 'accounting.accounts.view', true);
        }
        if ($user->hasPermission('accounting.account-mappings.view')) {
            $readiness[] = $this->readiness('accounting.account-mappings.index', AccountMapping::query()->where('is_active', true)->count(), 'ตั้งค่า Account Mapping ที่จำเป็น', 'accounting.account-mappings.index', 'accounting.account-mappings.view', true);
        }
        if ($user->hasPermission('accounting.periods.view')) {
            $readiness[] = $this->readiness('accounting.fiscal-years.index', FiscalPeriod::query()->where('status', 'OPEN')->count(), 'เปิดงวดบัญชีสำหรับบันทึกรายการ', 'accounting.fiscal-years.index', 'accounting.periods.view', true);
        }
        if ($warehouseId !== null && $user->hasPermission('accounting.journal-entries.view')) {
            $pending[] = $this->pending('accounting.journals', JournalEntry::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'VALIDATED'])->count(), 'รายการบัญชีที่รออนุมัติหรือ Post', 'accounting.journal-entries.index', 'accounting.journal-entries.view');
        }

        return new WorkflowRuntimeSnapshot('accounting', $readiness, $pending);
    }

    private function posSnapshot(User $user, ?int $warehouseId): WorkflowRuntimeSnapshot
    {
        $pending = [];
        if ($warehouseId !== null && $user->hasPermission('pos.sales-documents.view')) {
            $pending[] = $this->pending('pos.sales-documents', SalesDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(), 'เอกสารขายที่รออนุมัติหรือ Post', 'pos.sales-documents.index', 'pos.sales-documents.view');
        }

        return new WorkflowRuntimeSnapshot('pos', $this->postingDefaultReadiness(['sales_invoice']), $pending);
    }

    private function assetSnapshot(): WorkflowRuntimeSnapshot
    {
        return new WorkflowRuntimeSnapshot('asset', $this->postingDefaultReadiness([
            'asset.capitalization',
            'asset.addition',
            'asset.depreciation',
            'asset.impairment',
            'asset.disposal',
            'asset.write_off',
        ]));
    }

    private function postingDefaultReadiness(array $eventCodes): array
    {
        $mappings = $this->accountMappings ?? app(AccountMappingService::class);

        return collect($eventCodes)->map(function (string $eventCode) use ($mappings): array {
            $result = $mappings->readiness($eventCode);
            $blocker = $result['blockers'][0] ?? null;

            return [
                'code' => "posting.{$eventCode}",
                'event_code' => $eventCode,
                'status' => $result['ready'] ? 'READY' : 'WARNING',
                'configuration_warning' => ! $result['ready'],
                'missing_count' => count($result['blockers']),
                'block_reason' => $blocker['message'] ?? null,
                'next_action' => $result['ready'] ? 'เปิดรายการเพื่อดำเนินการต่อ' : ($blocker['recovery_label'] ?? 'ตั้งค่าการลงบัญชี'),
                'recovery_url' => $blocker['recovery_url'] ?? null,
                'recovery_label' => $blocker['recovery_label'] ?? null,
                'recovery_permission' => 'accounting.account-mappings.view',
                'route' => null,
            ];
        })->all();
    }

    private function readiness(string $code, int $count, string $nextAction, string $route, string $permission, bool $countIsReady = false): array
    {
        $missing = $countIsReady ? ($count > 0 ? 0 : 1) : $count;

        return ['code' => $code, 'status' => $missing === 0 ? 'READY' : 'NOT_READY', 'missing_count' => $missing, 'block_reason' => $missing === 0 ? null : 'ยังไม่มีข้อมูลที่จำเป็น', 'next_action' => $nextAction, 'permission' => $permission, 'route' => $route];
    }

    private function pending(string $code, int $count, string $label, string $route, string $permission): array
    {
        return ['code' => $code, 'count' => $count, 'label' => $label, 'route' => $route, 'permission' => $permission];
    }
}
