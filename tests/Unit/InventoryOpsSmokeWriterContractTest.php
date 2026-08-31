<?php

namespace Tests\Unit;

use Tests\TestCase;

final class InventoryOpsSmokeWriterContractTest extends TestCase
{
    public function test_writer_and_command_are_explicit_runtime_gated_and_audited(): void
    {
        $writer = file_get_contents(base_path('app/Modules/Wms/Services/InventoryOpsSmokeWriter.php'));
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('DB::transaction', $writer);
        $this->assertStringContainsString("config(['erp.inventory.purchase_posting_enabled' => true])", $writer);
        $this->assertStringContainsString("config(['erp.inventory.purchase_posting_enabled' => \$previousFlag])", $writer);
        $this->assertStringContainsString('AuditLog::query()->create', $writer);
        $this->assertStringContainsString("->where('status', 'POSTED')->sole()", $writer);
        $this->assertStringContainsString('wms:inventory-ops-smoke {--prefix=} {--actor=} {--confirm}', $console);
        $this->assertStringContainsString('app(InventoryOpsSmokeWriter::class)->run', $console);
    }
}
