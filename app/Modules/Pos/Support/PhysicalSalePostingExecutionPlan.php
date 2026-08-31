<?php

namespace App\Modules\Pos\Support;

use Illuminate\Validation\ValidationException;

/**
 * Side-effect-free execution contract for a future physical-sale posting.
 *
 * This class only describes the order and inputs an application service must
 * execute inside one outer transaction. It deliberately does not lock rows,
 * write movements, allocate cost, post journals, or change sale status.
 */
final class PhysicalSalePostingExecutionPlan
{
    /** @var list<string> */
    private const STAGE_ORDER = [
        'source_lock',
        'stock_issue',
        'cost_allocation',
        'cogs_journal',
        'revenue_journal',
        'linkage_and_status',
    ];

    /**
     * Build an execution plan after the existing read-only posting gate.
     *
     * @return array{identity_key:string,readiness:array<string,mixed>,stages:list<array<string,mixed>>,posting_plan:array<string,mixed>}
     */
    public static function build(array $sale): array
    {
        $postingPlan = PhysicalSalePostingPlan::buildReady($sale);
        $readiness = $postingPlan['readiness'];

        $stages = [
            [
                'name' => 'source_lock',
                'depends_on' => [],
                'payload' => [
                    'source_type' => $readiness['source_type'],
                    'source_id' => (int) ($sale['source_id'] ?? 0),
                    'sale_id' => $readiness['sale_id'],
                    'warehouse_id' => (int) ($sale['warehouse_id'] ?? 0),
                    'document_number' => $readiness['document_number'],
                ],
            ],
            [
                'name' => 'stock_issue',
                'depends_on' => ['source_lock'],
                'payload' => [
                    'stock_intents' => $postingPlan['stock_intents'],
                    'source_reference' => $readiness['document_number'],
                ],
            ],
            [
                'name' => 'cost_allocation',
                'depends_on' => ['stock_issue'],
                'payload' => [
                    'movement_source' => 'stock_issue',
                    'allocation_status' => 'PENDING',
                ],
            ],
            [
                'name' => 'cogs_journal',
                'depends_on' => ['cost_allocation'],
                'payload' => [
                    'source_event' => 'sales_cogs',
                    'source_type' => 'INVENTORY',
                    'source_reference' => $readiness['document_number'],
                ],
            ],
            [
                'name' => 'revenue_journal',
                'depends_on' => ['cogs_journal'],
                'payload' => $postingPlan['revenue_journal'],
            ],
            [
                'name' => 'linkage_and_status',
                'depends_on' => ['cogs_journal', 'revenue_journal'],
                'payload' => [
                    'sale_id' => $readiness['sale_id'],
                    'revenue_journal_source' => $postingPlan['revenue_journal']['source_reference'],
                    'status_after_success' => 'POSTED',
                ],
            ],
        ];

        self::assertStageContract($stages);

        return [
            'identity_key' => $postingPlan['identity_key'],
            'readiness' => $readiness,
            'stages' => $stages,
            'posting_plan' => $postingPlan,
        ];
    }

    /**
     * @return list<string>
     */
    public static function stageOrder(): array
    {
        return self::STAGE_ORDER;
    }

    /**
     * @param  list<array{name:string,depends_on:list<string>,payload:array<string,mixed>}>  $stages
     */
    private static function assertStageContract(array $stages): void
    {
        $names = array_map(static fn (array $stage): string => (string) ($stage['name'] ?? ''), $stages);
        if ($names !== self::STAGE_ORDER || count($names) !== count(array_unique($names))) {
            throw ValidationException::withMessages(['posting' => 'ลำดับขั้นตอนลงบัญชี HS/IV ไม่ถูกต้อง']);
        }

        $seen = [];
        foreach ($stages as $stage) {
            foreach ($stage['depends_on'] as $dependency) {
                if (! in_array($dependency, $seen, true)) {
                    throw ValidationException::withMessages(['posting' => 'ขั้นตอนลงบัญชี HS/IV มี dependency ย้อนลำดับ']);
                }
            }
            $seen[] = $stage['name'];
        }
    }
}
