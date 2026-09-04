<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Support\PostingEvent;
use DomainException;
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
        'INVENTORY_ROUNDING_GAIN' => 'กำไรจากการปัดเศษต้นทุน',
        'INVENTORY_ROUNDING_LOSS' => 'ขาดทุนจากการปัดเศษต้นทุน',
    ];

    private const LEGACY_ROLES = [
        'SALES_AR' => 'ACCOUNTS_RECEIVABLE',
        'SALES_REVENUE_DEFAULT' => 'SALES_REVENUE',
        'PURCHASE_AP' => 'ACCOUNTS_PAYABLE',
        'CUSTOMER_ADVANCE' => 'CUSTOMER_ADVANCE',
        'SUPPLIER_ADVANCE' => 'SUPPLIER_ADVANCE',
        'PURCHASE_EXPENSE_DEFAULT' => 'PURCHASE_EXPENSE',
        'DEFERRED_INPUT_VAT' => 'DEFERRED_INPUT_VAT',
        'DEFERRED_OUTPUT_VAT' => 'DEFERRED_OUTPUT_VAT',
        'INPUT_VAT' => 'INPUT_VAT',
        'OUTPUT_VAT' => 'OUTPUT_VAT',
        'WHT_RECEIVABLE' => 'WHT_RECEIVABLE',
        'WHT_PAYABLE' => 'WHT_PAYABLE',
        'INVENTORY_DEFAULT' => 'INVENTORY',
        'COGS_DEFAULT' => 'COGS',
        'SALES_COMMISSION_EXPENSE' => 'COMMISSION_EXPENSE',
        'INVENTORY_ADJUSTMENT_GAIN' => 'ADJUSTMENT_GAIN',
        'INVENTORY_ADJUSTMENT_LOSS' => 'ADJUSTMENT_LOSS',
        'INVENTORY_RECOST_GAIN' => 'RECOST_GAIN',
        'INVENTORY_RECOST_LOSS' => 'RECOST_LOSS',
        'INVENTORY_ROUNDING_GAIN' => 'ROUNDING_GAIN',
        'INVENTORY_ROUNDING_LOSS' => 'ROUNDING_LOSS',
    ];

    public function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return array<string, array{event_code: string, module: string, document: string, book: string, status: string, roles: array}> */
    public function configurationEvents(): array
    {
        return collect(PostingEvent::codes())
            ->mapWithKeys(function (string $eventCode): array {
                $contract = PostingEvent::contract($eventCode);
                $contract['roles'] = collect($contract['roles'])->map(fn (string $role): array => PostingEvent::role($role))->values()->all();

                return [$eventCode => $contract];
            })
            ->filter(fn (array $contract): bool => $contract['roles'] !== [])
            ->all();
    }

    public function label(string $key): string
    {
        return self::LABELS[$key] ?? $this->roleLabel($key);
    }

    public function roleLabel(string $role): string
    {
        try {
            return PostingEvent::role($this->roleFor($role))['label'];
        } catch (DomainException) {
            return $role;
        }
    }

    public function legacyRole(string $key): string
    {
        return $this->roleFor($key);
    }

    /** Legacy resolver: existing callers intentionally read only unscoped mappings during rollout. */
    public function resolve(string $key): Account
    {
        return DB::transaction(function () use ($key): Account {
            $mapping = $this->soleMapping(
                AccountMapping::query()->whereNull('event_code')->where('key', strtoupper(trim($key)))->where('is_active', true)->sharedLock(),
                'account_mapping',
                "ยังไม่ได้ตั้งค่า {$this->label($key)}",
                "ตั้งค่า {$this->label($key)} ซ้ำกันหลายรายการ ต้องเหลือรายการที่ใช้งานได้เพียงหนึ่งรายการ",
            );
            $account = $this->accountFor($mapping);
            $this->assertCompatible($key, $account);

            return $account;
        });
    }

    /** @return array{account: Account, provenance: array<string, int|string|null>} */
    public function resolveForEvent(string $eventCode, string $role): array
    {
        return DB::transaction(function () use ($eventCode, $role): array {
            $contract = PostingEvent::contract($eventCode);
            $role = strtoupper(trim($role));
            if (! PostingEvent::allowsRole($contract['event_code'], $role)) {
                throw ValidationException::withMessages(['account_role' => 'Account role นี้ไม่ได้อยู่ใน Posting event ที่เลือก']);
            }

            $mapping = $this->soleMapping(
                AccountMapping::query()->where('event_code', $contract['event_code'])->where('key', $role)->where('is_active', true)->sharedLock(),
                "account_mapping.{$contract['event_code']}.{$role}",
                "ยังไม่ได้ตั้งค่า {$this->roleLabel($role)} สำหรับ {$contract['document']}",
                "ตั้งค่า {$this->roleLabel($role)} สำหรับ {$contract['document']} ซ้ำกันหลายรายการ",
            );
            $account = $this->accountFor($mapping);
            $this->assertCompatible($role, $account);

            return [
                'account' => $account,
                'provenance' => [
                    'event_code' => $contract['event_code'],
                    'account_role' => $role,
                    'account_id' => $account->id,
                    'source' => 'MAPPING',
                    'source_type' => 'ACCOUNT_MAPPING',
                    'source_id' => (string) $mapping->id,
                    'mapping_id' => $mapping->id,
                    'mapping_version' => $mapping->version,
                ],
            ];
        });
    }

    /** @return array{ready: bool, event_code: string, required_roles: array, resolved_accounts: array, blockers: array} */
    public function readiness(string $eventCode): array
    {
        $contract = PostingEvent::contract($eventCode);
        $resolved = [];
        $blockers = [];
        foreach ($contract['roles'] as $role) {
            try {
                $resolution = $this->resolveForEvent($contract['event_code'], $role);
                $resolved[] = $resolution['provenance'];
            } catch (ValidationException $exception) {
                $blockers[] = [
                    'code' => 'ACCOUNT_MAPPING_NOT_READY',
                    'field' => "account_mapping.{$contract['event_code']}.{$role}",
                    'event_code' => $contract['event_code'],
                    'account_role' => $role,
                    'message' => collect($exception->errors())->flatten()->first() ?: 'Account Mapping ไม่พร้อม',
                    'recovery_label' => 'ตั้งค่าการลงบัญชี',
                    'recovery_url' => route('accounting.account-mappings.index', ['event_code' => $contract['event_code']], false),
                ];
            }
        }
        if ($contract['status'] === 'DEFERRED') {
            $blockers[] = [
                'code' => 'POSTING_EVENT_DEFERRED',
                'field' => 'event_code',
                'event_code' => $contract['event_code'],
                'account_role' => null,
                'message' => 'Event นี้ยังไม่เปิดใช้การ Post',
                'recovery_label' => 'ดูสถานะ Feature',
                'recovery_url' => null,
            ];
        }

        return [
            'ready' => $blockers === [],
            'event_code' => $contract['event_code'],
            'required_roles' => $contract['roles'],
            'resolved_accounts' => $resolved,
            'blockers' => $blockers,
        ];
    }

    public function nextVersion(AccountMapping $mapping, int $accountId, bool $isActive): int
    {
        return (int) $mapping->account_id === $accountId && (bool) $mapping->is_active === $isActive
            ? max(1, (int) $mapping->version)
            : max(1, (int) $mapping->version) + 1;
    }

    public function assertEventRole(string $eventCode, string $role): void
    {
        if (! PostingEvent::allowsRole($eventCode, $role)) {
            throw ValidationException::withMessages(['key' => 'Account role นี้ไม่ได้อยู่ใน Posting event ที่เลือก']);
        }
    }

    public function applyCompatibleAccountConstraint($query, string $keyOrRole): void
    {
        $role = $this->roleFor($keyOrRole);
        $rule = PostingEvent::role($role);
        if (isset($rule['control'])) {
            $query->where('control_account_type', $rule['control']);

            return;
        }

        $query->where(function ($compatibleQuery) use ($rule) {
            if (isset($rule['controls'])) {
                $compatibleQuery->whereIn('control_account_type', $rule['controls']);
            }

            if (isset($rule['types'])) {
                $method = isset($rule['controls']) ? 'orWhere' : 'where';
                $compatibleQuery->{$method}(function ($typedQuery) use ($rule) {
                    $typedQuery->whereNull('control_account_type')
                        ->whereHas('type', fn ($typeQuery) => $typeQuery->whereIn('code', $rule['types']));
                });
            }
        });
    }

    public function assertCompatible(string $keyOrRole, Account $account): void
    {
        try {
            $role = $this->roleFor($keyOrRole);
            $rule = PostingEvent::role($role);
        } catch (DomainException) {
            throw ValidationException::withMessages(['key' => 'ไม่รองรับ Account Mapping นี้']);
        }
        if ($account->trashed() || ! $account->is_active || ! $account->is_postable) {
            throw ValidationException::withMessages(['account_id' => 'บัญชีต้องเปิดใช้งานและลงรายการได้']);
        }

        $valid = isset($rule['control'])
            ? $account->control_account_type === $rule['control']
            : ($account->control_account_type !== null
                ? in_array($account->control_account_type, $rule['controls'] ?? [], true)
                : (! isset($rule['types']) || in_array($account->type?->code, $rule['types'], true)));
        if (! $valid) {
            throw ValidationException::withMessages(['account_id' => 'ประเภทบัญชีไม่ตรงกับ Account Mapping ที่เลือก']);
        }
    }

    private function roleFor(string $keyOrRole): string
    {
        $keyOrRole = strtoupper(trim($keyOrRole));

        return self::LEGACY_ROLES[$keyOrRole] ?? $keyOrRole;
    }

    private function accountFor(AccountMapping $mapping): Account
    {
        return Account::query()->withTrashed()->with('type')->whereKey($mapping->account_id)->sharedLock()->firstOrFail();
    }

    private function soleMapping($query, string $field, string $missing, string $duplicate): AccountMapping
    {
        $mappings = $query->get();
        if ($mappings->isEmpty()) {
            throw ValidationException::withMessages([$field => $missing]);
        }
        if ($mappings->count() !== 1) {
            throw ValidationException::withMessages([$field => $duplicate]);
        }

        return $mappings->sole();
    }
}
