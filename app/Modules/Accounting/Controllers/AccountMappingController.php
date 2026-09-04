<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Requests\SaveAccountMappingRequest;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AccountMappingController extends Controller
{
    public function index(): View
    {
        return view('Accounting::account-mappings.index');
    }

    public function data(Request $request, AccountMappingService $mappings): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->mappingsQuery())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request, $mappings))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('module_label', fn (AccountMapping $mapping) => $mapping->event_code ? ($mappings->configurationEvents()[$mapping->event_code]['module'] ?? '-') : 'Legacy')
            ->addColumn('document_label', fn (AccountMapping $mapping) => $mapping->event_code ? ($mappings->configurationEvents()[$mapping->event_code]['document'] ?? $mapping->event_code) : 'Mapping เดิม')
            ->addColumn('key_label', fn (AccountMapping $mapping) => $mappings->roleLabel($mapping->key))
            ->addColumn('account_label', fn (AccountMapping $mapping) => $mapping->account_code.' · '.$mapping->account_name);

        if ($request->user()->hasPermission('accounting.account-mappings.update')) {
            $dataTable->addColumn('edit_url', fn (AccountMapping $mapping) => route('accounting.account-mappings.edit', $mapping));
        }
        if ($request->user()->hasPermission('accounting.account-mappings.create')) {
            $dataTable->addColumn('copy_url', fn (AccountMapping $mapping) => $mapping->event_code === null ? route('accounting.account-mappings.create', ['copy_legacy' => $mapping->id]) : null);
        }

        return $dataTable->toJson();
    }

    public function readiness(AccountMappingService $mappings): JsonResponse
    {
        $events = collect($mappings->configurationEvents())->map(function (array $event, string $eventCode) use ($mappings): array {
            $readiness = $mappings->readiness($eventCode);

            return [
                'event_code' => $eventCode,
                'module' => $event['module'],
                'document' => $event['document'],
                'status' => $event['status'],
                'ready' => $readiness['ready'],
                'required_roles' => count($readiness['required_roles']),
                'resolved_roles' => count($readiness['resolved_accounts']),
                'blockers' => collect($readiness['blockers'])->pluck('message')->values()->all(),
                'url' => route('accounting.account-mappings.index', ['event_code' => $eventCode]),
            ];
        })->values();

        return response()->json(['data' => $events]);
    }

    public function accountOptions(Request $request, AccountMappingService $mappings): JsonResponse
    {
        $values = $request->validate(['event_code' => ['nullable', Rule::in(array_keys($mappings->configurationEvents()))], 'key' => ['required', 'string', 'max:80'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        if ($values['event_code'] ?? null) {
            $mappings->assertEventRole($values['event_code'], $values['key']);
        } elseif (! in_array($values['key'], $mappings->keys(), true)) {
            abort(422, 'ไม่รองรับ Legacy Account Mapping นี้');
        }
        $search = trim((string) ($values['q'] ?? ''));
        $page = max(1, (int) ($values['page'] ?? 1));
        $accounts = Account::query()->with('type:id,code')->where('is_active', true)->where('is_postable', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")));
        $mappings->applyCompatibleAccountConstraint($accounts, $values['key']);
        $accounts = $accounts->orderBy('code')->forPage($page, 31)->get(['id', 'account_type_id', 'code', 'name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function create(Request $request, AccountMappingService $mappings): View
    {
        $copy = $request->integer('copy_legacy') ? AccountMapping::query()->whereNull('event_code')->findOrFail($request->integer('copy_legacy')) : null;

        return view('Accounting::account-mappings.form', [
            'accountMapping' => new AccountMapping(['is_active' => true]),
            'events' => $mappings->configurationEvents(),
            'copyFromLegacy' => $copy,
            'copyRole' => $copy ? $mappings->legacyRole($copy->key) : null,
            'selectedAccount' => $copy?->account,
        ]);
    }

    public function edit(AccountMapping $accountMapping, AccountMappingService $mappings): View
    {
        return view('Accounting::account-mappings.form', [
            'accountMapping' => $accountMapping,
            'events' => $mappings->configurationEvents(),
            'copyFromLegacy' => null,
            'copyRole' => null,
            'selectedAccount' => Account::query()->find($accountMapping->account_id, ['id', 'code', 'name']),
        ]);
    }

    public function store(SaveAccountMappingRequest $request, AuditLogger $audit, AccountMappingService $mappings): JsonResponse
    {
        $mapping = DB::transaction(function () use ($request, $audit, $mappings) {
            $account = Account::query()->withTrashed()->with('type')->whereKey($request->integer('account_id'))->sharedLock()->firstOrFail();
            $mappings->assertCompatible($request->string('key')->toString(), $account);
            $mapping = AccountMapping::query()->create([...$request->mappingValues(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('accounting.account_mapping.created', $mapping, [], [...$mapping->only(['event_code', 'key', 'account_id', 'is_active', 'version']), 'reason' => $request->input('reason')], $request->user(), $request);

            return $mapping;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่ม Account Mapping แล้ว', 'redirect' => route('accounting.account-mappings.index')]);
    }

    public function update(SaveAccountMappingRequest $request, AccountMapping $accountMapping, AuditLogger $audit, AccountMappingService $mappings): JsonResponse
    {
        DB::transaction(function () use ($request, $accountMapping, $audit, $mappings) {
            $mapping = AccountMapping::query()->lockForUpdate()->findOrFail($accountMapping->id);
            $account = Account::query()->withTrashed()->with('type')->whereKey($request->integer('account_id'))->sharedLock()->firstOrFail();
            $mappings->assertCompatible($request->string('key')->toString(), $account);
            $before = $mapping->only(['event_code', 'key', 'account_id', 'is_active', 'version']);
            $values = $request->mappingValues();
            $values['version'] = $mappings->nextVersion($mapping, (int) $values['account_id'], (bool) $values['is_active']);
            $mapping->update([...$values, 'updated_by' => $request->user()->id]);
            $audit->record('accounting.account_mapping.updated', $mapping, $before, [...$mapping->only(array_keys($before)), 'reason' => $request->input('reason')], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไข Account Mapping แล้ว']);
    }

    private function mappingsQuery(): Builder
    {
        return AccountMapping::query()->join('accounts', 'accounts.id', '=', 'accounting_account_mappings.account_id')->select(['accounting_account_mappings.*', 'accounts.code as account_code', 'accounts.name as account_name']);
    }

    private function applySearch(Builder $query, Request $request, AccountMappingService $mappings): void
    {
        if ($eventCode = $request->string('event_code')->toString()) {
            $query->where('accounting_account_mappings.event_code', $eventCode);
        }
        if ($module = $request->string('module')->toString()) {
            $eventCodes = collect($mappings->configurationEvents())->filter(fn (array $event) => $event['module'] === $module)->keys();
            $query->whereIn('accounting_account_mappings.event_code', $eventCodes);
        }
        if (($status = $request->input('is_active')) !== null && $status !== '') {
            $query->where('accounting_account_mappings.is_active', (bool) $status);
        }
        if ($request->boolean('legacy_only')) {
            $query->whereNull('accounting_account_mappings.event_code');
        }
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $matchingKeys = collect($mappings->keys())->filter(fn (string $key) => str_contains(mb_strtolower($mappings->label($key)), mb_strtolower($search)));
            $query->where(fn (Builder $query) => $query->where('accounting_account_mappings.key', 'like', "%{$search}%")->orWhereIn('accounting_account_mappings.key', $matchingKeys)->orWhere('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        // Keep this in the same order as the DataTable columns: module, document,
        // role, GL account, version, status, actions.
        $columns = [
            0 => 'accounting_account_mappings.event_code',
            1 => 'accounting_account_mappings.event_code',
            2 => 'accounting_account_mappings.key',
            3 => 'accounts.code',
            4 => 'accounting_account_mappings.version',
            5 => 'accounting_account_mappings.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? $columns[0];
        $query->reorder($column, $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderBy('accounting_account_mappings.id');
    }
}
