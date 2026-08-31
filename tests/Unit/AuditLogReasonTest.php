<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogReasonTest extends TestCase
{
    public function test_it_exposes_reason_from_the_audit_snapshot(): void
    {
        $log = new AuditLog([
            'action' => 'wms.purchase_document.voided',
            'new_values' => ['void_reason' => 'เลือก Supplier ผิด ต้องสร้างเอกสารใหม่'],
        ]);

        $this->assertSame('ยกเลิกใบตั้งหนี้', $log->action);
        $this->assertSame('เลือก Supplier ผิด ต้องสร้างเอกสารใหม่', $log->reason);
    }

    public function test_empty_reason_is_not_rendered_as_a_reason(): void
    {
        $log = new AuditLog(['new_values' => ['approval_reason' => null]]);

        $this->assertNull($log->reason);
    }
}
