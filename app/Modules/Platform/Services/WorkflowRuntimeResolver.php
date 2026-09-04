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
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Settings\Support\SettingRegistry;
use App\Modules\Wms\Models\CostRecalculationRequest;
use App\Modules\Wms\Models\Item;
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

        return array_map(function (array $workflow) use ($readiness, $eventReadiness, $pending): array {
            $workflow['steps'] = array_map(function (array $step) use ($readiness, $eventReadiness, $pending): array {
                $route = $step['route'] ?? null;
                $eventCode = $step['event_code'] ?? null;
                if (($eventCode && $eventReadiness->has($eventCode)) || ($route && $readiness->has($route))) {
                    $runtime = $eventCode ? $eventReadiness->get($eventCode) : $readiness->get($route);
                    $step['readiness'] = $runtime;
                    $step['runtime_not_ready'] = ($runtime['status'] ?? null) === 'NOT_READY';
                    $step['configuration_warning'] = (bool) ($runtime['configuration_warning'] ?? false);
                    $step['block_reason'] = $runtime['block_reason'] ?? $step['block_reason'] ?? null;
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
        $readiness[] = [
            'code' => 'inventory.cost_policy',
            'event_code' => 'inventory.cost_policy',
            'status' => $inventoryMissing === [] ? 'READY' : 'NOT_READY',
            'configuration_warning' => $inventoryMissing !== [],
            'missing_count' => count($inventoryMissing),
            'block_reason' => $inventoryMissing === [] ? null : 'ตั้งค่านโยบายต้นทุนและ SLA ของ Inventory ให้ครบ',
            'next_action' => $inventoryMissing === [] ? 'เริ่มงานคลังได้' : 'เปิด Global Settings เพื่อตั้งค่า Inventory',
            'route' => null,
        ];
        if ($user->hasPermission('wms.items.view')) {
            $readiness[] = $this->readiness('wms.items', Item::query()->where('is_active', true)->count(), 'สร้างข้อมูลสินค้าให้พร้อมก่อนทำรายการ', 'wms.items.index', 'wms.items.view', true);
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

        return new WorkflowRuntimeSnapshot('wms', $readiness, $pending);
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
