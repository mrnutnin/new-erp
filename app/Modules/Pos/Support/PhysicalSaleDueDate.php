<?php

namespace App\Modules\Pos\Support;

use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Support\PaymentDueDate;
use Illuminate\Validation\ValidationException;

final class PhysicalSaleDueDate
{
    public static function resolve(string $documentType, string $documentDate, ?PaymentTerm $term, ?string $explicitDueDate): string
    {
        if ($term && ! $term->is_active) {
            throw ValidationException::withMessages(['due_date' => 'เงื่อนไขชำระเงินของลูกค้าต้องเปิดใช้งาน']);
        }
        $calculatedDueDate = $term
            ? PaymentDueDate::calculate($documentDate, $term->due_rule, $term->credit_days)
            : null;
        if ($explicitDueDate && $calculatedDueDate && $explicitDueDate !== $calculatedDueDate) {
            throw ValidationException::withMessages(['due_date' => 'วันครบกำหนดต้องตรงกับเงื่อนไขชำระเงินของลูกค้า']);
        }
        if ($calculatedDueDate) {
            return $calculatedDueDate;
        }
        if ($documentType === 'HS') {
            return $documentDate;
        }
        if (! $explicitDueDate) {
            throw ValidationException::withMessages(['due_date' => 'IV ต้องมีเงื่อนไขชำระเงินของลูกค้าหรือระบุวันครบกำหนด']);
        }

        return $explicitDueDate;
    }
}
