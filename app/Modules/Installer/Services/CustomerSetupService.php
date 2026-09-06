<?php

namespace App\Modules\Installer\Services;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CustomerSetupService
{
    public function saveCompany(array $values): CompanySetting
    {
        return DB::transaction(function () use ($values): CompanySetting {
            $company = CompanySetting::query()->firstOrNew(['id' => 1]);
            $company->fill([
                'company_name' => $values['company_name'],
                'company_address' => $values['company_address'] ?? null,
                'tax_id' => $values['tax_id'] ?? null,
                'locale' => $values['locale'] ?? 'th',
                'timezone' => $values['timezone'] ?? 'Asia/Bangkok',
                'base_currency' => $values['base_currency'] ?? 'THB',
                'date_format' => 'd/m/Y',
                'business_profile' => 'TRADING',
                'production_enabled' => false,
                'asset_enabled' => true,
                'accounting_profile' => 'PAE',
                'inventory_costing_method' => 'AVG',
                'allow_negative_stock' => false,
                'negative_stock_cost_method' => 'CURRENT_AVERAGE',
                'fiscal_year_start_month' => 1,
                'default_vat_rate' => 7,
                'default_withholding_tax_rate' => 3,
                'tax_decimal_places' => 2,
                'document_sequence_reset' => 'MONTHLY',
                'effective_from' => now()->toDateString(),
                'settings_version' => max(1, (int) ($company->settings_version ?: 1)),
            ]);
            $company->save();

            if (DB::getSchemaBuilder()->hasTable('company_setting_versions')) {
                DB::table('company_setting_versions')->updateOrInsert(
                    ['company_setting_id' => $company->id, 'version' => $company->settings_version],
                    [
                        'effective_from' => $company->effective_from,
                        'values' => json_encode($company->fresh()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'change_reason' => 'ติดตั้งข้อมูลบริษัทผ่าน ERP Installer',
                        'changed_by' => null,
                        'created_at' => now(),
                    ],
                );
            }

            return $company;
        });
    }

    /** @param array<int, string> $moduleCodes */
    public function selectModules(array $moduleCodes): void
    {
        DB::transaction(function () use ($moduleCodes): void {
            Program::query()->withTrashed()->get()->each(function (Program $program) use ($moduleCodes): void {
                $program->forceFill([
                    'is_enabled' => in_array($program->code, ['dashboard', 'settings'], true)
                        || in_array($program->code, $moduleCodes, true),
                    'deleted_at' => null,
                ])->save();
            });
        });
    }

    /** @return array{branch:Branch, warehouse:Warehouse} */
    public function ensureDefaultOrganization(): array
    {
        return DB::transaction(function (): array {
            $branch = Branch::query()->withTrashed()->firstOrNew(['code' => '00000']);
            $branch->forceFill(['name' => 'สำนักงานใหญ่', 'is_active' => true, 'deleted_at' => null])->save();

            $warehouse = Warehouse::query()->withTrashed()->firstOrNew(['code' => 'WH001']);
            $warehouse->forceFill([
                'branch_id' => $branch->id,
                'name' => 'คลังหลัก',
                'is_active' => true,
                'deleted_at' => null,
            ])->save();

            return compact('branch', 'warehouse');
        });
    }

    public function createAdministrator(array $values): User
    {
        return DB::transaction(function () use ($values): User {
            $organization = $this->ensureDefaultOrganization();
            $user = User::query()->withTrashed()->firstOrNew(['username' => $values['username']]);
            $user->forceFill([
                'name' => $values['name'],
                'username' => $values['username'],
                'email' => $values['email'],
                'password' => Hash::make($values['password']),
                'is_active' => true,
                'primary_branch_id' => $organization['branch']->id,
                'deleted_at' => null,
            ])->save();

            $adminRole = Role::query()->where('code', 'admin')->where('is_active', true)->first();
            if ($adminRole) {
                $user->roles()->syncWithoutDetaching([$adminRole->id]);
            }
            $user->programs()->sync(Program::query()->where('is_enabled', true)->pluck('id')->all());
            $user->branches()->syncWithoutDetaching([$organization['branch']->id]);
            $user->warehouses()->syncWithoutDetaching([$organization['warehouse']->id]);

            return $user;
        });
    }
}
