<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\InventoryOpsSmokeContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InventoryOpsSmokeContractTest extends TestCase
{
    public function test_requires_confirm_actor_and_ops_prefix(): void
    {
        $this->expectException(ValidationException::class);
        (new InventoryOpsSmokeContract)->validate('SMOKE-1', null, false);
    }

    public function test_accepts_confirmed_ops_prefix(): void
    {
        (new InventoryOpsSmokeContract)->validate('OPS-SMOKE-001', 1, true);
        $this->addToAssertionCount(1);
    }

    public function test_rejects_hash_mismatch_for_existing_prefix(): void
    {
        $this->expectException(ValidationException::class);
        (new InventoryOpsSmokeContract)->validate('OPS-SMOKE-001', 1, true, 'old', 'new');
    }
}
