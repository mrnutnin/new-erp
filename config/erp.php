<?php

return [
    'inventory' => [
        // Closed by default until production integration/reconciliation QA is approved.
        'purchase_posting_enabled' => (bool) env('ERP_INVENTORY_PURCHASE_POSTING_ENABLED', false),
        'adjustment_posting_enabled' => (bool) env('ERP_INVENTORY_ADJUSTMENT_POSTING_ENABLED', false),
    ],
    'pdf' => [
        'profiles' => [
            'a4' => [
                'format' => 'A4',
                'orientation' => 'P',
                'margin_top' => 16,
                'margin_right' => 12,
                'margin_bottom' => 16,
                'margin_left' => 12,
            ],
            // 9.5 x 5.5 inch continuous form; keep markup table-like.
            'dot_matrix' => [
                'format' => [241.3, 139.7],
                'orientation' => 'P',
                'margin_top' => 8,
                'margin_right' => 8,
                'margin_bottom' => 8,
                'margin_left' => 8,
            ],
        ],
    ],
];
