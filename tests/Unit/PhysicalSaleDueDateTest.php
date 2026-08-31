<?php

namespace Tests\Unit;

use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Pos\Support\PhysicalSaleDueDate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PhysicalSaleDueDateTest extends TestCase
{
    public function test_hs_uses_document_date_without_a_customer_term(): void
    {
        $this->assertSame('2026-08-28', PhysicalSaleDueDate::resolve('HS', '2026-08-28', null, null));
    }

    public function test_iv_derives_due_date_from_active_customer_term(): void
    {
        $term = new PaymentTerm(['credit_days' => 30, 'due_rule' => 'DUE_ON_DATE', 'is_active' => true]);

        $this->assertSame('2026-09-27', PhysicalSaleDueDate::resolve('IV', '2026-08-28', $term, null));
    }

    public function test_iv_without_term_requires_explicit_due_date(): void
    {
        $this->expectException(ValidationException::class);

        PhysicalSaleDueDate::resolve('IV', '2026-08-28', null, null);
    }
}
