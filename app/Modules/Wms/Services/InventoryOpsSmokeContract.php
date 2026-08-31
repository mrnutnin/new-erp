<?php

namespace App\Modules\Wms\Services;

use Illuminate\Validation\ValidationException;

final class InventoryOpsSmokeContract
{
    public function validate(string $prefix, ?int $actorId, bool $confirm, ?string $existingHash = null, ?string $requestedHash = null): void
    {
        if (! preg_match('/^OPS-SMOKE-[A-Z0-9-]+$/', strtoupper(trim($prefix)))) {
            throw ValidationException::withMessages(['prefix' => 'prefix ต้องอยู่ในรูปแบบ OPS-SMOKE-*']);
        }
        if (! $actorId || $actorId < 1) {
            throw ValidationException::withMessages(['actor' => 'ต้องระบุ actor ที่ถูกต้อง']);
        }
        if (! $confirm) {
            throw ValidationException::withMessages(['confirm' => 'ต้องระบุ --confirm ก่อนสร้างข้อมูลถาวร']);
        }
        if ($existingHash !== null && $requestedHash !== null && ! hash_equals($existingHash, $requestedHash)) {
            throw ValidationException::withMessages(['idempotency' => 'prefix เดิมมี hash ต่างกัน ไม่อนุญาตให้สร้างซ้ำ']);
        }
    }
}
