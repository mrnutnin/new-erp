<?php

namespace App\Modules\Pos\Requests;

use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Requests\SaveSettlementRequest;

final class SavePosReceiptRequest extends SaveSettlementRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge(['document_type' => 'RECEIPT', 'party_type' => 'CUSTOMER']);

        $bankAccountId = (int) data_get($this->input('tenders'), '0.bank_account_id');
        $branchId = $this->attributes->get('selectedBranch')?->id;

        if ($bankAccountId && $branchId) {
            $bankAccount = BankAccount::query()
                ->whereKey($bankAccountId)
                ->where('is_active', true)
                ->whereHas('warehouse', fn ($query) => $query->where('branch_id', $branchId)->where('is_active', true))
                ->first();
            $warehouse = $bankAccount ? $this->user()?->warehouses()
                ->whereKey($bankAccount->warehouse_id)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->first() : null;

            if ($warehouse) {
                $this->attributes->set('selectedWarehouse', $warehouse);
            }
        }
    }
}
