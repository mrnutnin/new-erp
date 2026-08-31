<?php

namespace App\Modules\Wms\Support;

/** Explicit source boundary for the local Inventory → GL MVP. */
final class InventoryGlScope
{
    public const LOCAL_MVP_SOURCES = ['PURCHASING', 'GOODS_RECEIPT', 'INVENTORY'];

    public const DEFERRED_SOURCES = ['ISSUE_DOCUMENT', 'ISSUE_RETURN', 'WMS_TRANSFER'];
}
