<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use InvalidArgumentException;

final class VatRealizationJournalLines
{
    public static function build(
        string $taxKind,
        int $deferredAccountId,
        int $actualAccountId,
        string $taxBase,
        string $taxAmount,
        int $taxCodeId,
        string $taxPointDate,
        string $settlementDate,
    ): array {
        if (! in_array($taxKind, ['VAT_IN', 'VAT_OUT'], true) || $deferredAccountId < 1 || $actualAccountId < 1 || $taxCodeId < 1) {
            throw new InvalidArgumentException('VAT realization journal มีประเภทหรือบัญชีไม่ถูกต้อง');
        }
        $amount = JournalBalance::decimal($taxAmount);
        if ($amount === '0.00' || str_starts_with($amount, '-')) {
            throw new InvalidArgumentException('VAT realization journal ต้องมียอดมากกว่าศูนย์');
        }
        $base = JournalBalance::decimal($taxBase);
        $debitAccount = $taxKind === 'VAT_IN' ? $actualAccountId : $deferredAccountId;
        $creditAccount = $taxKind === 'VAT_IN' ? $deferredAccountId : $actualAccountId;

        return [
            [
                'account_id' => $debitAccount,
                'subledger_type' => 'TAX',
                'subledger_id' => (string) $taxCodeId,
                'tax_code_id' => $taxCodeId,
                'description' => $taxKind === 'VAT_IN' ? 'รับรู้ภาษีซื้อจากการจ่ายเงิน' : 'ย้ายภาษีขายพักรอรับรู้',
                'debit' => $amount,
                'credit' => '0.00',
                'tax_base' => $base,
                'tax_amount' => $amount,
                'tax_point_date' => $taxPointDate,
                'tax_settlement_date' => $settlementDate,
            ],
            [
                'account_id' => $creditAccount,
                'subledger_type' => 'TAX',
                'subledger_id' => (string) $taxCodeId,
                'tax_code_id' => $taxCodeId,
                'description' => $taxKind === 'VAT_IN' ? 'ตัดภาษีซื้อพักรอรับรู้' : 'รับรู้ภาษีขายจากการรับเงิน',
                'debit' => '0.00',
                'credit' => $amount,
                'tax_base' => $base,
                'tax_amount' => $amount,
                'tax_point_date' => $taxPointDate,
                'tax_settlement_date' => $settlementDate,
            ],
        ];
    }
}
