<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExecutiveDashboardMySqlIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_dashboard_data_returns_the_executive_contract_for_an_authorized_user(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();

        $response = $this->actingAs($user)->getJson(route('dashboard.data', [
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->toDateString(),
            'branch_id' => 'all',
            'business_unit_id' => 'all',
        ]));

        $response->assertOk()->assertJsonStructure([
            'filters' => ['date_from', 'date_to', 'branch_id', 'business_unit_id'],
            'refreshed_at',
            'kpis' => ['sales', 'gross_profit', 'cash_flow', 'receivables', 'payables', 'inventory'],
            'trend' => ['labels', 'sales', 'receipts', 'payments'],
            'branches',
            'attention',
            'decisions',
            'meta' => ['partial', 'warnings'],
        ]);
    }

    public function test_dashboard_rejects_a_branch_outside_the_users_scope(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();

        $this->actingAs($user)
            ->getJson(route('dashboard.data', [
                'date_from' => now()->startOfMonth()->toDateString(),
                'date_to' => now()->toDateString(),
                'branch_id' => '999999999',
            ]))
            ->assertForbidden();
    }
}
