<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

final class DashboardScopeService
{
    public function branches(User $user): Collection
    {
        return Branch::query()->where('is_active', true)
            ->when($user->branches()->exists(), fn ($query) => $query->whereIn('id', $user->branches()->select('branches.id')),
                fn ($query) => $query->whereIn('id', $user->warehouses()->where('warehouses.is_active', true)->select('warehouses.branch_id')))
            ->orderBy('name')->get(['id', 'code', 'name']);
    }

    public function branchIds(User $user, mixed $branchId = 'all'): array
    {
        $allowed = $this->branches($user)->pluck('id')->map(fn ($id) => (int) $id);
        if ($branchId !== null && $branchId !== 'all') {
            abort_unless($allowed->contains((int) $branchId), 403, 'คุณไม่มีสิทธิ์ดูข้อมูลสาขานี้');

            return [(int) $branchId];
        }

        return $allowed->all();
    }
}
