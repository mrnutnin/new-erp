<?php

namespace Tests\Unit;

use Tests\TestCase;

final class CostAccountingProofPolicyTest extends TestCase
{
    public function test_reversed_issue_return_is_a_no_gl_reversal_pair(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/CostAccountingProofPolicy.php'));

        $this->assertStringContainsString('$status === \'REVERSED\'', $source);
        $this->assertStringContainsString("'NO_GL_REVERSAL_PAIR'", $source);
        $this->assertStringContainsString('reversal_of_movement_id', $source);
    }
}
