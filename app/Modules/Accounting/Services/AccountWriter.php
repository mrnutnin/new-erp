<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Accounting\Rules\AccountStructure;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountWriter
{
    public function create(array $input, User $user, Request $request, AuditLogger $audit): Account
    {
        $type = AccountType::query()->lockForUpdate()->findOrFail($input['account_type_id']);
        $parent = empty($input['parent_id']) ? null : Account::query()->lockForUpdate()->findOrFail($input['parent_id']);
        $isPostable = $input['account_class'] !== 'SUMMARY';

        if ($parent && ! AccountStructure::parentIsValid($parent->is_active, $parent->is_postable, $parent->account_type_id, $type->id)) {
            throw ValidationException::withMessages(['parent_id' => 'บัญชีแม่ต้องใช้งาน เป็นบัญชีรวม และอยู่ในหมวดเดียวกัน']);
        }

        $level = AccountStructure::level($parent?->level);

        if (! AccountStructure::levelIsValid($level)) {
            throw ValidationException::withMessages(['parent_id' => 'ผังบัญชีรองรับระดับบัญชี 1–5 เท่านั้น']);
        }

        if (! AccountStructure::controlTypeIsValid($input['control_account_type'] ?? null, $isPostable)) {
            throw ValidationException::withMessages(['control_account_type' => 'บัญชีคุมต้องเป็นบัญชีที่ลงรายการได้']);
        }

        $values = [
            'account_type_id' => $type->id,
            'parent_id' => $parent?->id,
            'code' => $input['code'],
            'name' => $input['name'],
            'level' => $level,
            'normal_balance' => $type->normal_balance,
            'statement_section' => $type->statement_section,
            'reporting_profile' => $input['reporting_profile'] ?? null,
            'control_account_type' => $input['account_class'] === 'CONTROL' ? $input['control_account_type'] : null,
            'is_postable' => $isPostable,
            'is_active' => $input['is_active'],
            'updated_by' => $user->id,
        ];

        $account = Account::query()->create($values);
        $audit->record('accounting.account.created', $account, [], $values, $user, $request);

        return $account;
    }
}
