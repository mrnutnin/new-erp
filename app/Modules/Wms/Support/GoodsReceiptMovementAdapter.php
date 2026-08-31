<?php

namespace App\Modules\Wms\Support;

use App\Modules\Wms\Models\GoodsReceipt;

/**
 * Converts an approved Goods Receipt snapshot into normalized movement
 * intents. The caller must still own the future posting transaction; this
 * adapter deliberately has no database writes.
 */
final class GoodsReceiptMovementAdapter
{
    /** @return array<int, array<string, mixed>> */
    public static function map(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing('lines.goodsReceipt');

        return array_map(
            static fn (array $intent): array => StockMovementContract::normalize($intent),
            GoodsReceiptInventoryPostingContract::movementIntents($receipt),
        );
    }
}
