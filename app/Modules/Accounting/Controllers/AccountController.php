<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Accounting\Requests\SaveAccountRequest;
use App\Modules\Accounting\Rules\AccountStructure;
use App\Modules\Accounting\Services\AccountWriter;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class AccountController extends Controller
{
    public function index(): View
    {
        return view('Accounting::accounts.index');
    }

    public function parentOptions(Request $request): JsonResponse
    {
        $accountTypeId = $request->integer('account_type_id');

        if ($accountTypeId < 1) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }

        $search = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $parents = Account::query()
            ->where('account_type_id', $accountTypeId)
            ->where('is_active', true)
            ->where('is_postable', false)
            ->when($request->integer('account_id') > 0, fn (Builder $query) => $query->whereKeyNot($request->integer('account_id')))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')
            ->forPage($page, 31)
            ->get(['id', 'code', 'name']);

        return response()->json([
            'results' => $parents->take(30)->map(fn (Account $parent) => [
                'id' => $parent->id,
                'text' => $parent->code.' · '.$parent->name,
            ])->values(),
            'pagination' => ['more' => $parents->count() > 30],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->accountsQuery())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('type_name', fn (Account $account) => $account->type->name)
            ->addColumn('parent_label', fn (Account $account) => $account->parent ? $account->parent->code.' · '.$account->parent->name : '—')
            ->addColumn('class_label', fn (Account $account) => $account->control_account_type ? 'บัญชีคุม' : ($account->is_postable ? 'บัญชีย่อย' : 'บัญชีรวม'));

        if ($request->user()->hasPermission('accounting.accounts.update')) {
            $dataTable->addColumn('edit_url', fn (Account $account) => route('accounting.accounts.edit', $account));
        }

        if ($request->user()->hasPermission('accounting.accounts.delete')) {
            $dataTable->addColumn('delete_url', fn (Account $account) => route('accounting.accounts.destroy', $account));
        }

        return $dataTable->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->accountsQuery();
        $this->applySearch($query, $request);
        $this->applyOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Accounts"><Table>';
            echo $this->excelRow(['รหัส', 'ชื่อบัญชี', 'หมวดบัญชี', 'บัญชีแม่', 'ระดับ', 'ประเภทบัญชี', 'Control', 'Profile', 'สถานะ']);

            foreach ($query->lazy(500) as $account) {
                echo $this->excelRow([
                    $account->code,
                    $account->name,
                    $account->type->name,
                    $account->parent ? $account->parent->code.' · '.$account->parent->name : '',
                    $account->level,
                    $account->control_account_type ? 'บัญชีคุม' : ($account->is_postable ? 'บัญชีย่อย' : 'บัญชีรวม'),
                    $account->control_account_type,
                    $account->reporting_profile ?: 'ทั้ง PAE/NPAE',
                    $account->is_active ? 'ใช้งาน' : 'ปิดใช้งาน',
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'chart-of-accounts-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return $this->formView(new Account(['is_postable' => true, 'is_active' => true]));
    }

    public function store(SaveAccountRequest $request, AuditLogger $audit, AccountWriter $writer): JsonResponse|RedirectResponse
    {
        $account = DB::transaction(fn () => $writer->create($request->validated(), $request->user(), $request, $audit));

        return $this->savedResponse($request, 'เพิ่มบัญชีแล้ว', $account);
    }

    public function edit(Account $account): View
    {
        return $this->formView($account);
    }

    public function update(SaveAccountRequest $request, Account $account, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $account, $audit) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            $values = $this->validatedValues($request, $account);
            $childCount = $account->children()->count();
            $activeChildCount = $account->children()->where('is_active', true)->count();

            if ($childCount > 0 && ($values['parent_id'] !== $account->parent_id || $values['account_type_id'] !== $account->account_type_id)) {
                throw ValidationException::withMessages(['parent_id' => 'บัญชีที่มีบัญชีย่อยห้ามเปลี่ยนบัญชีแม่หรือหมวดบัญชี']);
            }

            if ($values['is_postable'] && ! AccountStructure::canBePostable($childCount)) {
                throw ValidationException::withMessages(['is_postable' => 'บัญชีที่มีบัญชีย่อยต้องเป็นบัญชีรวม']);
            }

            if (! $values['is_active'] && ! AccountStructure::canDeactivate($activeChildCount)) {
                throw ValidationException::withMessages(['is_active' => 'ต้องปิดบัญชีย่อยที่ยังใช้งานก่อน']);
            }

            $before = $account->only(array_keys($values));
            $account->update($values);
            $audit->record('accounting.account.updated', $account, $before, $values, $request->user(), $request);
        });

        return $this->savedResponse($request, 'แก้ไขบัญชีแล้ว', $account);
    }

    public function destroy(Request $request, Account $account, AuditLogger $audit): JsonResponse
    {
        $deleted = DB::transaction(function () use ($request, $account, $audit) {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);

            if (! AccountStructure::canDelete($account->children()->count())) {
                return false;
            }

            $before = $account->only(['code', 'name', 'is_active']);
            $account->delete();
            $audit->record('accounting.account.deleted', $account, $before, ['deleted_at' => $account->deleted_at], $request->user(), $request);

            return true;
        });

        return $deleted
            ? response()->json(['status' => true, 'msg' => 'ลบบัญชีแล้ว'])
            : response()->json(['status' => false, 'msg' => 'บัญชีที่มีบัญชีย่อยไม่สามารถลบได้'], 409);
    }

    private function validatedValues(SaveAccountRequest $request, ?Account $account = null): array
    {
        $values = $request->safe()->except('account_class');
        $values['is_postable'] = $request->validated('account_class') !== 'SUMMARY';
        $type = AccountType::query()->findOrFail($values['account_type_id']);
        $parent = null;

        if ($values['parent_id']) {
            $parent = Account::query()->lockForUpdate()->findOrFail($values['parent_id']);

            if (! AccountStructure::parentIsValid($parent->is_active, $parent->is_postable, $parent->account_type_id, $type->id)) {
                throw ValidationException::withMessages(['parent_id' => 'บัญชีแม่ต้องใช้งาน เป็นบัญชีรวม และอยู่ในหมวดเดียวกัน']);
            }

            for ($ancestor = $parent; $ancestor; $ancestor = $ancestor->parent) {
                if ($account && $ancestor->id === $account->id) {
                    throw ValidationException::withMessages(['parent_id' => 'ไม่สามารถเลือกบัญชีย่อยของตนเองเป็นบัญชีแม่']);
                }
            }
        }

        $level = AccountStructure::level($parent?->level);

        if (! AccountStructure::levelIsValid($level)) {
            throw ValidationException::withMessages(['parent_id' => 'ผังบัญชีรองรับระดับบัญชี 1–5 เท่านั้น']);
        }

        if (! AccountStructure::controlTypeIsValid($values['control_account_type'], $values['is_postable'])) {
            throw ValidationException::withMessages(['control_account_type' => 'Control Account ต้องเป็นบัญชีที่ลงรายการได้']);
        }

        return [
            ...$values,
            'level' => $level,
            'normal_balance' => $type->normal_balance,
            'statement_section' => $type->statement_section,
            'updated_by' => $request->user()->id,
        ];
    }

    private function formView(Account $account): View
    {
        $selectedParentId = old('parent_id', $account->parent_id);

        return view('Accounting::accounts.form', [
            'account' => $account,
            'types' => AccountType::query()->orderBy('sort_order')->get(),
            'selectedParent' => $selectedParentId ? Account::query()->find($selectedParentId, ['id', 'code', 'name']) : null,
        ]);
    }

    private function savedResponse(SaveAccountRequest $request, string $message, Account $account): JsonResponse|RedirectResponse
    {
        $redirect = route('accounting.accounts.edit', $account);

        return $request->expectsJson()
            ? response()->json(['status' => true, 'msg' => $message, 'redirect' => $redirect])
            : redirect($redirect)->with('success', $message);
    }

    private function accountsQuery(): Builder
    {
        return Account::query()->with(['type', 'parent'])->select('accounts.*');
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'accounts.code', 1 => 'accounts.name', 4 => 'accounts.level', 8 => 'accounts.is_active'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'accounts.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('accounts.id');
    }

    /** @param array<int, int|string|null> $values */
    private function excelRow(array $values): string
    {
        return '<Row>'.implode('', array_map(function (int|string|null $value) {
            $type = is_int($value) ? 'Number' : 'String';
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');

            return "<Cell><Data ss:Type=\"{$type}\">{$escaped}</Data></Cell>";
        }, $values)).'</Row>';
    }
}
