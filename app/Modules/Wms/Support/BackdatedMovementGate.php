<?php

namespace App\Modules\Wms\Support;

use App\Modules\Accounting\Models\FiscalPeriod;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * Keeps a stock movement's business date inside an open fiscal period.
 *
 * Backdated stock is allowed only when its target period is OPEN. The period
 * row is locked by the caller's transaction so a close cannot race a post.
 */
final class BackdatedMovementGate
{
    public function assertOpen(CarbonInterface|string $businessDate, bool $lock = true): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->whereDate('start_date', '<=', $businessDate)
            ->whereDate('end_date', '>=', $businessDate)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'business_date' => 'วันที่ Movement ต้องอยู่ภายในงวดบัญชีที่กำหนดไว้ กรุณาสร้างงวดบัญชีก่อน',
            ]);
        }

        try {
            self::assertStatusOpen((string) $period->status);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['business_date' => $exception->getMessage()]);
        }

        return $period;
    }

    public static function assertStatusOpen(string $status): void
    {
        if ($status !== 'OPEN') {
            throw new DomainException('ไม่สามารถ Post Movement ย้อนหลังในงวดที่ปิดหรือ Soft close แล้วได้ กรุณาเปิดงวดบัญชีหรือใช้เอกสารแก้ไขในงวดปัจจุบัน');
        }
    }
}
