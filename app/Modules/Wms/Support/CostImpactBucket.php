<?php

namespace App\Modules\Wms\Support;

enum CostImpactBucket: string
{
    case InventoryOnHand = 'INVENTORY_ON_HAND';
    case CogsConsumed = 'COGS_CONSUMED';
    case IssueExpenseConsumed = 'ISSUE_EXPENSE_CONSUMED';
    case WipConsumed = 'WIP_CONSUMED';
    case FinishedGoodsBridge = 'FINISHED_GOODS_BRIDGE';
    case TransferBridge = 'TRANSFER_BRIDGE';
    case ReturnBridge = 'RETURN_BRIDGE';
    case PurchaseReturnConsumed = 'PURCHASE_RETURN_CONSUMED';
    case RoundingResidual = 'ROUNDING_RESIDUAL';

    public function targetEvent(): ?string
    {
        return match ($this) {
            self::InventoryOnHand => 'inventory.recost',
            self::CogsConsumed => 'inventory.revaluation.cogs',
            self::IssueExpenseConsumed => 'inventory.revaluation.issue_expense',
            self::WipConsumed => 'production.revaluation.wip',
            self::FinishedGoodsBridge => 'production.revaluation.finished_goods',
            self::TransferBridge => null,
            self::ReturnBridge => 'inventory.revaluation.return',
            self::PurchaseReturnConsumed => 'purchasing.revaluation.return_cost',
            self::RoundingResidual => 'inventory.revaluation.rounding',
        };
    }
}
