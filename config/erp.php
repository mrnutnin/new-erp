<?php

return [
    'crm' => [
        'digest_time' => env('CRM_DIGEST_TIME', '08:00'),
        'reminder_minutes' => (int) env('CRM_REMINDER_MINUTES', 30),
        'risk_alert_time' => env('CRM_RISK_ALERT_TIME', '08:15'),
        'stale_opportunity_days' => (int) env('CRM_STALE_OPPORTUNITY_DAYS', 14),
    ],
    'setup' => [
        'enabled' => (bool) env('ERP_SETUP_ENABLED', false),
        'token' => (string) env('ERP_SETUP_TOKEN', ''),
        'uat_seed_enabled' => (bool) env('ERP_SETUP_UAT_SEED_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
    ],
    'inventory' => [
        // Manual WMS Finished Receipt is a supported MVP flow. Set the env
        // value to false to close the posting gate for a customer.
        'purchase_posting_enabled' => (bool) env('ERP_INVENTORY_PURCHASE_POSTING_ENABLED', false),
        'adjustment_posting_enabled' => (bool) env('ERP_INVENTORY_ADJUSTMENT_POSTING_ENABLED', false),
        'manual_production_receipt_posting_enabled' => (bool) env('ERP_MANUAL_PRODUCTION_RECEIPT_POSTING_ENABLED', true),
        // Phase 3 Apply remains closed until approval, stock and GL reconciliation gates are complete.
        'revaluation_apply_enabled' => (bool) env('ERP_REVALUATION_APPLY_ENABLED', false),
        'revaluation_gl_posting_enabled' => (bool) env('ERP_REVALUATION_GL_POSTING_ENABLED', false),
        'revaluation_auto_trigger_enabled' => (bool) env('ERP_REVALUATION_AUTO_TRIGGER_ENABLED', false),
        'revaluation_queue' => (string) env('ERP_REVALUATION_QUEUE', 'cost-propagation'),
        'revaluation_scope_partition_chunk' => (int) env('ERP_REVALUATION_SCOPE_PARTITION_CHUNK', 50),
        'revaluation_chunk_size' => (int) env('ERP_REVALUATION_CHUNK_SIZE', 250),
        'revaluation_chunk_seconds' => (int) env('ERP_REVALUATION_CHUNK_SECONDS', 20),
        'revaluation_calculation_lease_seconds' => (int) env('ERP_REVALUATION_CALCULATION_LEASE_SECONDS', 120),
        'revaluation_calculation_node_budget' => (int) env('ERP_REVALUATION_CALCULATION_NODE_BUDGET', 1000),
        'revaluation_memory_budget_mb' => (int) env('ERP_REVALUATION_MEMORY_BUDGET_MB', 128),
    ],
    'pdf' => [
        'profiles' => [
            'a4' => [
                'format' => 'A4',
                'orientation' => 'P',
                'fontDir' => [resource_path('fonts')],
                'fontdata' => [
                    'notosansthai' => [
                        'R' => 'NotoSansThai-Variable.ttf',
                    ],
                ],
                'default_font' => 'notosansthai',
                'margin_top' => 16,
                'margin_right' => 12,
                'margin_bottom' => 16,
                'margin_left' => 12,
            ],
            'label_40x30' => ['format' => [40, 30], 'margin_top' => 2.5, 'margin_right' => 2.5, 'margin_bottom' => 2.5, 'margin_left' => 2.5],
            'label_50x30' => ['format' => [50, 30], 'margin_top' => 2.5, 'margin_right' => 2.5, 'margin_bottom' => 2.5, 'margin_left' => 2.5],
            'label_60x40' => ['format' => [60, 40], 'margin_top' => 3, 'margin_right' => 3, 'margin_bottom' => 3, 'margin_left' => 3],
            'label_100x50' => ['format' => [100, 50], 'margin_top' => 4, 'margin_right' => 4, 'margin_bottom' => 4, 'margin_left' => 4],
            'label_a4' => ['format' => 'A4', 'orientation' => 'P', 'margin_top' => 7, 'margin_right' => 6, 'margin_bottom' => 7, 'margin_left' => 6],
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
