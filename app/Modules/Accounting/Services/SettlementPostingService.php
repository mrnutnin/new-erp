<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Validation\ValidationException;

final class SettlementPostingService
{
    public function __construct(private readonly JournalPostingService $posting) {}

    public function post(array $attributes, Warehouse $warehouse, ?User $actor = null): JournalEntry
    {
        $event = strtolower(trim((string) ($attributes['event_code'] ?? '')));
        if (! in_array($event, ['customer_payment', 'supplier_payment'], true)) {
            throw ValidationException::withMessages(['event_code' => 'Settlement รองรับเฉพาะ customer_payment หรือ supplier_payment']);
        }

        $date = trim((string) ($attributes['settlement_date'] ?? $attributes['entry_date'] ?? ''));
        if ($date === '') {
            throw ValidationException::withMessages(['settlement_date' => 'ต้องระบุวันที่รับ/จ่ายเงินจริง']);
        }

        $attributes['entry_date'] = $date;
        $attributes['document_date'] ??= $date;
        $attributes['lines'] = array_map(function (array $line) use ($date): array {
            if (! empty($line['tax_code_id']) && empty($line['tax_settlement_date'])) {
                $line['tax_settlement_date'] = $date;
            }

            return $line;
        }, $attributes['lines'] ?? []);
        unset($attributes['settlement_date']);

        return $this->posting->post($attributes, $warehouse, $actor);
    }
}
