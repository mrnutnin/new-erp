<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountMappingService
{
    public const LABELS = [
        'SALES_AR' => 'บัญชีลูกหนี้การค้า',
        'SALES_REVENUE_DEFAULT' => 'บัญชีรายได้ขายเริ่มต้น',
        'PURCHASE_AP' => 'บัญชีเจ้าหนี้การค้า',
        'CUSTOMER_ADVANCE' => 'บัญชีเงินรับล่วงหน้าลูกค้า',
        'SUPPLIER_ADVANCE' => 'บัญชีเงินจ่ายล่วงหน้าผู้ขาย',
        'PURCHASE_EXPENSE_DEFAULT' => 'บัญชีค่าใช้จ่ายซื้อเริ่มต้น',
        'DEFERRED_INPUT_VAT' => 'ภาษีซื้อพักรอรับรู้',
        'DEFERRED_OUTPUT_VAT' => 'ภาษีขายพักรอรับรู้',
        'INPUT_VAT' => 'ภาษีซื้อ',
        'OUTPUT_VAT' => 'ภาษีขาย',
        'WHT_RECEIVABLE' => 'ภาษีหัก ณ ที่จ่ายรอรับ',
        'WHT_PAYABLE' => 'ภาษีหัก ณ ที่จ่ายรอจ่าย',
        'INVENTORY_DEFAULT' => 'บัญชีสินค้าคงเหลือ',
        'COGS_DEFAULT' => 'บัญชีต้นทุนขาย',
        'SALES_COMMISSION_EXPENSE' => 'บัญชีค่าใช้จ่ายคอมมิชชั่นขาย',
        'INVENTORY_ADJUSTMENT_GAIN' => 'กำไรจากปรับปรุงสินค้าคงเหลือ',
        'INVENTORY_ADJUSTMENT_LOSS' => 'ขาดทุนจากปรับปรุงสินค้าคงเหลือ',
        'INVENTORY_RECOST_GAIN' => 'กำไรจากปรับต้นทุนสินค้า',
        'INVENTORY_RECOST_LOSS' => 'ขาดทุนจากปรับต้นทุนสินค้า',
    ];

    public function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    public function resolve(string $key): Account
    {
        return DB::transaction(function () use ($key): Account {
            $mappings = AccountMapping::query()->where('key', $key)->where('is_active', true)->sharedLock()->get();
            if ($mappings->isEmpty()) {
                throw ValidationException::withMessages(['account_mapping' => "ยังไม่ได้ตั้งค่า {$this->label($key)}"]);
            }
            if ($mappings->count() !== 1) {
                throw ValidationException::withMessages(['account_mapping' => "ตั้งค่า {$this->label($key)} ซ้ำกันหลายรายการ ต้องเหลือรายการที่ใช้งานได้เพียงหนึ่งรายการ"]);
            }

            $account = Account::query()->withTrashed()->with('type')->whereKey($mappings->sole()->account_id)->sharedLock()->firstOrFail();
            $this->assertCompatible($key, $account);

            return $account;
        });
    }

    public function assertCompatible(string $key, Account $account): void
    {
        if (! isset(self::LABELS[$key])) {
            throw ValidationException::withMessages(['key' => 'ไม่รองรับ Account Mapping นี้']);
        }
        if ($account->trashed() || ! $account->is_active || ! $account->is_postable) {
            throw ValidationException::withMessages(['account_id' => 'บัญชีต้องเปิดใช้งานและลงรายการได้']);
        }

        $valid = match ($key) {
            'SALES_AR' => $account->control_account_type === 'AR',
            'PURCHASE_AP' => $account->control_account_type === 'AP',
            'CUSTOMER_ADVANCE' => $account->control_account_type === null && $account->type?->code === 'LIABILITY',
            'SUPPLIER_ADVANCE' => $account->control_account_type === null && $account->type?->code === 'ASSET',
            'SALES_REVENUE_DEFAULT' => $account->control_account_type === null && $account->type?->code === 'REVENUE',
            'PURCHASE_EXPENSE_DEFAULT' => $account->control_account_type === null && in_array($account->type?->code, ['EXPENSE', 'ASSET'], true),
            'DEFERRED_INPUT_VAT', 'INPUT_VAT' => $account->control_account_type === 'INPUT_VAT',
            'DEFERRED_OUTPUT_VAT', 'OUTPUT_VAT' => $account->control_account_type === 'OUTPUT_VAT',
            'WHT_RECEIVABLE', 'WHT_PAYABLE' => $account->control_account_type === 'WITHHOLDING_TAX',
            'INVENTORY_DEFAULT' => $account->control_account_type === 'INVENTORY',
            'COGS_DEFAULT' => $account->control_account_type === null && $account->type?->code === 'EXPENSE',
            'SALES_COMMISSION_EXPENSE' => $account->control_account_type === null && $account->type?->code === 'EXPENSE',
            'INVENTORY_ADJUSTMENT_GAIN' => $account->control_account_type === null && $account->type?->code === 'REVENUE',
            'INVENTORY_ADJUSTMENT_LOSS' => $account->control_account_type === null && $account->type?->code === 'EXPENSE',
            'INVENTORY_RECOST_GAIN' => $account->control_account_type === null && $account->type?->code === 'REVENUE',
            'INVENTORY_RECOST_LOSS' => $account->control_account_type === null && $account->type?->code === 'EXPENSE',
        };

        if (! $valid) {
            throw ValidationException::withMessages(['account_id' => 'ประเภทบัญชีไม่ตรงกับ Account Mapping ที่เลือก']);
        }
    }
}
