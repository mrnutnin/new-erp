<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Requests\ChangeJournalEntryStatusRequest;
use App\Modules\Accounting\Requests\SaveJournalEntryRequest;
use App\Modules\Accounting\Services\JournalEntryWriter;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class JournalEntryController extends Controller
{
    public function index(): View
    {
        return view('Accounting::journal-entries.index');
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $accounts = Account::query()->where('is_active', true)->where('is_postable', true)->whereNull('control_account_type')->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $canUpdate = $request->user()->hasPermission('accounting.journal-entries.update');

        return DataTables::eloquent($this->entriesQuery($request))
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('entry_date_label', fn (JournalEntry $entry) => $entry->entry_date->format('d/m/Y'))
            ->addColumn('book_label', fn (JournalEntry $entry) => $entry->book->code.' · '.$entry->book->name)
            ->addColumn('branch_label', fn (JournalEntry $entry) => $entry->branch->name)
            ->addColumn('debit_total', fn (JournalEntry $entry) => number_format((float) $entry->debit_total, 2))
            ->addColumn('show_url', fn (JournalEntry $entry) => route('accounting.journal-entries.show', $entry))
            ->addColumn('edit_url', fn (JournalEntry $entry) => $canUpdate && $entry->status === 'DRAFT' ? route('accounting.journal-entries.edit', $entry) : null)
            ->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->entriesQuery($request);
        $this->applySearch($query, $request);
        $this->applyOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Journal Entries"><Table>';
            echo $this->excelRow(['เลขที่', 'วันที่', 'สมุดบัญชี', 'สาขา', 'คำอธิบาย', 'เดบิต', 'เครดิต', 'สถานะ']);
            foreach ($query->lazy(500) as $entry) {
                echo $this->excelRow([$entry->entry_number, $entry->entry_date->format('d/m/Y'), $entry->book->code, $entry->branch->name, $entry->description, $entry->debit_total, $entry->credit_total, $entry->status]);
            }
            echo '</Table></Worksheet></Workbook>';
        }, 'journal-entries-'.now()->format('Ymd-His').'.xls', ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
    }

    public function create(): View
    {
        return $this->form(new JournalEntry(['entry_date' => now()->toDateString()]));
    }

    public function store(SaveJournalEntryRequest $request, JournalEntryWriter $writer, AuditLogger $audit): JsonResponse
    {
        $entry = DB::transaction(function () use ($request, $writer, $audit) {
            $entry = $writer->create($request->validated(), $request->attributes->get('selectedWarehouse'), $request->user());
            $audit->record('accounting.journal_entry.created', $entry, [], $entry->only(['entry_number', 'entry_date', 'description', 'status']), $request->user(), $request);

            return $entry;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกรายการบัญชี Draft แล้ว', 'redirect' => route('accounting.journal-entries.show', $entry)]);
    }

    public function show(Request $request, JournalEntry $journalEntry): View
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        $journalEntry->load([
            'book', 'period.fiscalYear', 'branch', 'warehouse', 'lines.account', 'lines.taxCode', 'createdBy',
            'validatedBy', 'postedBy', 'reversedBy', 'reversalOf', 'reversal',
        ]);

        return view('Accounting::journal-entries.show', compact('journalEntry'));
    }

    public function preview(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        $journalEntry->load(['book:id,code,name', 'period.fiscalYear:id,name', 'lines.account:id,code,name', 'lines.taxCode:id,code']);

        return response()->json([
            'entry_number' => $journalEntry->entry_number,
            'status' => $journalEntry->status,
            'status_label' => ['DRAFT' => 'ร่าง', 'VALIDATED' => 'รออนุมัติ', 'POSTED' => 'ลงบัญชีแล้ว', 'REVERSED' => 'กลับรายการแล้ว'][$journalEntry->status] ?? $journalEntry->status,
            'entry_date' => $journalEntry->entry_date?->format('d/m/Y'),
            'document_date' => $journalEntry->document_date?->format('d/m/Y') ?: '—',
            'book' => $journalEntry->book?->code.' · '.$journalEntry->book?->name,
            'period' => $journalEntry->period?->fiscalYear?->name.' / '.$journalEntry->period?->period_number,
            'description' => $journalEntry->description,
            'lines' => $journalEntry->lines->map(fn ($line) => [
                'line_number' => $line->line_number,
                'account' => $line->account?->code.' · '.$line->account?->name,
                'description' => $line->description ?: '—',
                'tax_code' => $line->taxCode?->code,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
            ])->values(),
        ]);
    }

    public function edit(Request $request, JournalEntry $journalEntry): View
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        abort_unless($journalEntry->status === 'DRAFT', 404);
        $journalEntry->load('lines');

        return $this->form($journalEntry);
    }

    public function update(SaveJournalEntryRequest $request, JournalEntry $journalEntry, JournalEntryWriter $writer, AuditLogger $audit): JsonResponse
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        DB::transaction(function () use ($request, $journalEntry, $writer, $audit) {
            $before = $journalEntry->only(['entry_date', 'document_date', 'source_reference', 'description', 'status']);
            $entry = $writer->update($journalEntry, $request->validated(), $request->user());
            $audit->record('accounting.journal_entry.updated', $entry, $before, $entry->only(array_keys($before)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'บันทึก Draft แล้ว']);
    }

    public function submit(ChangeJournalEntryStatusRequest $request, JournalEntry $journalEntry, JournalEntryWriter $writer, AuditLogger $audit): JsonResponse
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        $this->changeStatus($request, $journalEntry, $writer, $audit, 'submit', 'accounting.journal_entry.submitted');

        return response()->json(['status' => true, 'msg' => 'ส่งรายการเพื่ออนุมัติแล้ว']);
    }

    public function approve(ChangeJournalEntryStatusRequest $request, JournalEntry $journalEntry, JournalEntryWriter $writer, AuditLogger $audit): JsonResponse
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        $this->changeStatus($request, $journalEntry, $writer, $audit, 'approve', 'accounting.journal_entry.posted');

        return response()->json(['status' => true, 'msg' => 'อนุมัติและลงบัญชีแล้ว']);
    }

    public function reverse(ChangeJournalEntryStatusRequest $request, JournalEntry $journalEntry, JournalEntryWriter $writer, AuditLogger $audit): JsonResponse
    {
        $this->ensureWarehouseScope($request, $journalEntry);
        $reversal = DB::transaction(function () use ($request, $journalEntry, $writer, $audit) {
            $before = $journalEntry->only(['status', 'reversed_by', 'reversed_at', 'reversal_reason']);
            $reversal = $writer->reverse($journalEntry, $request->validated('reversal_date'), $request->validated('reason'), $request->user());
            $journalEntry->refresh();
            $audit->record('accounting.journal_entry.reversed', $journalEntry, $before, $journalEntry->only(array_keys($before)), $request->user(), $request);
            $audit->record('accounting.journal_entry.reversal_created', $reversal, [], $reversal->only([
                'entry_number', 'entry_date', 'status', 'reversal_of_id', 'posting_reason',
            ]), $request->user(), $request);

            return $reversal;
        });

        return response()->json([
            'status' => true,
            'msg' => "สร้างรายการกลับ {$reversal->entry_number} แล้ว",
            'redirect' => route('accounting.journal-entries.show', $reversal),
        ]);
    }

    private function form(JournalEntry $journalEntry): View
    {
        $accountIds = $journalEntry->exists ? $journalEntry->lines->pluck('account_id')->filter()->unique() : collect();
        $accounts = Account::query()->whereKey($accountIds)->get(['id', 'code', 'name']);
        $taxCodes = TaxCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'kind', 'rate']);

        return view('Accounting::journal-entries.form', compact('journalEntry', 'accounts', 'taxCodes'));
    }

    private function entriesQuery(Request $request): Builder
    {
        return JournalEntry::query()->with(['book:id,code,name', 'branch:id,name'])
            ->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))
            ->withSum('lines as debit_total', 'debit')->withSum('lines as credit_total', 'credit');
    }

    private function ensureWarehouseScope(Request $request, JournalEntry $entry): void
    {
        abort_unless(in_array((int) $entry->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function changeStatus(
        ChangeJournalEntryStatusRequest $request,
        JournalEntry $entry,
        JournalEntryWriter $writer,
        AuditLogger $audit,
        string $transition,
        string $auditAction,
    ): void {
        DB::transaction(function () use ($request, $entry, $writer, $audit, $transition, $auditAction) {
            $fields = ['status', 'validated_by', 'validated_at', 'validation_reason', 'posted_by', 'posted_at', 'posting_reason'];
            $before = $entry->only($fields);
            $entry = $writer->{$transition}($entry, $request->validated('reason'), $request->user());
            $audit->record($auditAction, $entry, $before, $entry->only($fields), $request->user(), $request);
        });
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('entry_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('source_reference', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'entry_date', 1 => 'entry_number', 4 => 'description', 7 => 'status'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'entry_date';
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($column, $direction)->orderByDesc('id');
    }

    private function excelRow(array $values): string
    {
        return '<Row>'.implode('', array_map(fn ($value) => '<Cell><Data ss:Type="String">'.htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8').'</Data></Cell>', $values)).'</Row>';
    }
}
