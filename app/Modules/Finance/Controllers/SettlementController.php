<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Warehouse;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Models\WithholdingRealization;
use App\Modules\Finance\Requests\ChangeSettlementStatusRequest;
use App\Modules\Finance\Requests\PostSettlementRequest;
use App\Modules\Finance\Requests\ReverseSettlementRequest;
use App\Modules\Finance\Requests\SaveSettlementRequest;
use App\Modules\Finance\Services\AdvanceDepositSettlementService;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Services\SettlementPostingService;
use App\Modules\Finance\Services\SettlementReversalService;
use App\Modules\Finance\Support\SettlementState;
use App\Modules\Finance\Support\WhtRealizationCalculator;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SettlementController extends Controller
{
    public function index(): View
    {
        return view('Finance::settlements.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $dateFormat = (string) $settings->value('date_format');
        $dataTable = DataTables::eloquent($this->settlementsQuery($request))
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('settlement_date_label', fn (Settlement $settlement) => $settlement->settlement_date->format($dateFormat))
            ->addColumn('document_type_label', fn (Settlement $settlement) => $settlement->document_type === 'RECEIPT' ? 'รับเงิน' : 'จ่ายเงิน')
            ->addColumn('status_label', fn (Settlement $settlement) => match ($settlement->status) {
                'APPROVED' => 'อนุมัติแล้ว',
                'POSTED' => 'ลงบัญชีแล้ว',
                'VOID' => 'ยกเลิก',
                default => 'ร่าง',
            })
            ->addColumn('party_label', fn (Settlement $settlement) => $settlement->party_code ? $settlement->party_code.' · '.$settlement->party_name : '—')
            ->addColumn('bank_label', fn (Settlement $settlement) => $settlement->bank_code.' · '.$settlement->bank_name)
            ->editColumn('intent_count', fn (Settlement $settlement) => (int) $settlement->intent_count)
            ->editColumn('intent_amount', fn (Settlement $settlement) => $settlement->intent_amount ?? '0.00')
            ->addColumn('advance_url', fn (Settlement $settlement) => $request->user()->hasPermission('finance.advance-deposits.create')
                && in_array($settlement->status, ['APPROVED', 'POSTED'], true)
                && (int) $settlement->intent_count === 0
                && ! $settlement->advance_deposit_id
                ? route('finance.settlements.advance-deposit', $settlement) : null)
            ->addColumn('advance_document_number', fn (Settlement $settlement) => $settlement->advance_document_number);
        $dataTable->addColumn('show_url', fn (Settlement $settlement) => route('finance.settlements.show', $settlement));

        if ($request->user()->hasPermission('finance.settlements.approve')) {
            $dataTable->addColumn('approve_url', fn (Settlement $settlement) => $settlement->status === 'DRAFT' ? route('finance.settlements.approve', $settlement) : null);
        }

        if ($request->user()->hasPermission('finance.settlements.void')) {
            $dataTable->addColumn('void_url', fn (Settlement $settlement) => in_array($settlement->status, ['DRAFT', 'APPROVED'], true) ? route('finance.settlements.void', $settlement) : null);
        }
        if ($request->user()->hasPermission('finance.settlements.post')) {
            $dataTable->addColumn('post_url', fn (Settlement $settlement) => $settlement->status === 'APPROVED' ? route('finance.settlements.post', $settlement) : null);
        }
        if ($request->user()->hasPermission('finance.settlements.reverse')) {
            $dataTable->addColumn('reverse_url', fn (Settlement $settlement) => $settlement->status === 'POSTED' ? route('finance.settlements.reverse', $settlement) : null);
        }

        return $dataTable->toJson();
    }

    public function show(Request $request, Settlement $settlement, GlobalSettings $settings): View
    {
        $settlement = Settlement::query()->withTrashed()->with(['party', 'bankAccount', 'paymentTerm', 'journalEntry', 'tenders.bankAccount', 'allocationIntents.openItem'])->findOrFail($settlement->id);
        $this->scopeSettlement($request, $settlement);
        $history = AuditLog::query()->with('user')->where('subject_type', $settlement->getMorphClass())->where('subject_id', $settlement->id)->latest('created_at')->latest('id')->get();

        return view('Finance::settlements.show', ['settlement' => $settlement, 'history' => $history, 'dateFormat' => (string) $settings->value('date_format')]);
    }

    public function create(Request $request, OpenItemService $openItems): View
    {
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $preselectedOpenItem = null;
        if ($request->filled('open_item_id')) {
            $preselectedOpenItem = OpenItem::query()
                ->with('party')
                ->whereKey($request->integer('open_item_id'))
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('ledger_type', 'AR')
                ->where('party_type', 'CUSTOMER')
                ->where('balance_side', 'DEBIT')
                ->whereDate('posting_date', '<=', today())
                ->whereHas('account', fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->where('is_postable', true)
                    ->where('control_account_type', 'AR'))
                ->whereHas('party', fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereHas('roles', fn (Builder $roles) => $roles
                        ->where('role', 'CUSTOMER')
                        ->where('is_active', true)))
                ->firstOrFail();
            $preselectedOpenItem->remaining_amount = $openItems->remainingAt($preselectedOpenItem, today()->format('Y-m-d'));
            abort_if($preselectedOpenItem->remaining_amount === '0.00', 422, 'Invoice นี้ไม่มีคงเหลือให้รับชำระ');
        }
        $withholding = $preselectedOpenItem ? $this->withholdingFor($preselectedOpenItem, today()->format('Y-m-d')) : '0.00';

        return view('Finance::settlements.form', [
            'settlement' => new Settlement([
                'document_type' => 'RECEIPT',
                'party_id' => $preselectedOpenItem?->party_id,
                'document_date' => today(),
                'settlement_date' => today(),
                'gross_amount' => $preselectedOpenItem?->remaining_amount ?? 0,
                'tax_amount' => 0,
                'withholding_amount' => $withholding,
                'net_amount' => $preselectedOpenItem ? JournalBalance::subtract($preselectedOpenItem->remaining_amount, $withholding) : 0,
            ]),
            'bankAccounts' => $this->bankAccounts($request)->get(['id', 'code', 'name']),
            'paymentTerms' => PaymentTerm::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'preselectedOpenItem' => $preselectedOpenItem,
        ]);
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $filters = $this->optionFilters($request);
        $query = $this->openItemOptionsQuery($request, $filters)
            ->select(['finance_open_items.party_id', 'parties.code as party_code', 'parties.name as party_name'])
            ->distinct()
            ->reorder('parties.code');

        if ($filters['q'] !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$filters['q']}%")
                ->orWhere('parties.name', 'like', "%{$filters['q']}%")
                ->orWhere('parties.tax_id', 'like', "%{$filters['q']}%")
                ->orWhere('parties.phone', 'like', "%{$filters['q']}%"));
        }

        $parties = $query->forPage($filters['page'], 31)->get();

        return response()->json([
            'results' => $parties->take(30)->map(fn (OpenItem $item) => [
                'id' => $item->party_id,
                'text' => $item->party_code.' · '.$item->party_name,
                'code' => $item->party_code,
                'name' => $item->party_name,
            ])->values(),
            'pagination' => ['more' => $parties->count() > 30],
        ]);
    }

    public function openItemOptions(Request $request, GlobalSettings $settings): JsonResponse
    {
        $filters = $this->optionFilters($request, true);
        $dateFormat = (string) $settings->value('date_format');
        $query = $this->openItemOptionsQuery($request, $filters)
            ->where('finance_open_items.party_id', $filters['party_id'])
            ->orderByRaw('finance_open_items.due_date IS NULL')
            ->orderBy('finance_open_items.due_date')
            ->orderBy('finance_open_items.id');

        if ($filters['q'] !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('finance_open_items.document_number', 'like', "%{$filters['q']}%")
                ->orWhere('finance_open_items.document_type', 'like', "%{$filters['q']}%"));
        }

        $items = $query->forPage($filters['page'], 31)->get();

        return response()->json([
            'results' => $items->take(30)->map(function (OpenItem $item) use ($dateFormat) {
                $remaining = JournalBalance::decimal($item->getAttribute('remaining_amount'));
                $dueDate = $item->due_date?->format($dateFormat) ?? 'ไม่ระบุวันครบกำหนด';

                return [
                    'id' => $item->id,
                    'text' => "{$item->document_number} · {$dueDate} · คงเหลือ {$remaining}",
                    'document_number' => $item->document_number,
                    'due_date' => $item->due_date?->format('Y-m-d'),
                    'remaining_amount' => $remaining,
                ];
            })->values(),
            'pagination' => ['more' => $items->count() > 30],
        ]);
    }

    public function store(SaveSettlementRequest $request, AuditLogger $audit, DocumentSequenceService $sequences, OpenItemService $openItems): JsonResponse
    {
        $settlement = DB::transaction(function () use ($request, $audit, $sequences, $openItems) {
            $values = $request->validated();
            $allocationInputs = $values['allocations'] ?? [];
            $tenders = $values['tenders'] ?? [];
            unset($values['allocations'], $values['tenders']);
            $warehouse = $request->attributes->get('selectedWarehouse');
            $warehouse->loadMissing('branch');
            if (! $warehouse->branch) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขา']);
            }
            $contract = $this->settlementContract($values['document_type']);
            $values['party_id'] = (int) $values['party_id'];
            if ($values['party_type'] !== $contract['party_type']) {
                throw ValidationException::withMessages(['party_type' => 'ประเภทคู่ค้าไม่ตรงกับประเภทเอกสาร']);
            }
            $party = Party::query()->whereKey($values['party_id'])->where('is_active', true)->sharedLock()->first();
            $partyRole = $party ? PartyRole::query()
                ->where('party_id', $party->id)
                ->where('role', $contract['party_type'])
                ->where('is_active', true)
                ->sharedLock()
                ->first() : null;
            if (! $party || ! $partyRole) {
                throw ValidationException::withMessages(['party_id' => 'คู่ค้าและบทบาทต้องเปิดใช้งาน']);
            }
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', $values['document_type'])->where('is_active', true)->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['document_type' => 'ยังไม่ได้ตั้งค่าเลขเอกสารสำหรับประเภทนี้']);
            }

            $values['document_number'] = $sequences->issueForBranch($sequence, $warehouse->branch, Carbon::parse($values['settlement_date']));
            $settlement = Settlement::create([
                ...$values,
                'status' => 'DRAFT',
                'created_by' => $request->user()->id,
            ]);
            $settlement->tenders()->createMany(collect($tenders)->values()->map(fn (array $tender, int $index) => ['bank_account_id' => $tender['bank_account_id'], 'line_number' => $index + 1, 'amount' => JournalBalance::decimal($tender['amount']), 'reference' => $tender['reference'] ?? null])->all());
            $sequences->recordIssued($sequence, $settlement->document_number, 'finance_settlements', (int) $settlement->id, Carbon::parse($settlement->document_date), $request->user()->id);
            $items = OpenItem::query()
                ->with('account')
                ->whereKey(collect($allocationInputs)->pluck('open_item_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($items->count() !== count($allocationInputs)) {
                throw ValidationException::withMessages(['allocations' => 'ไม่พบรายการคงค้างบางรายการ กรุณาเลือกใหม่']);
            }

            $intentRows = [];
            foreach ($allocationInputs as $index => $input) {
                $item = $items->get((int) $input['open_item_id']);
                if ((int) $item->warehouse_id !== (int) $warehouse->id
                    || $item->ledger_type !== $contract['ledger_type']
                    || $item->party_type !== $contract['party_type']
                    || (int) $item->party_id !== $values['party_id']
                    || $item->balance_side !== $contract['balance_side']
                    || ! $item->account
                    || ! $item->account->is_active
                    || ! $item->account->is_postable
                    || $item->account->control_account_type !== $contract['ledger_type']) {
                    throw ValidationException::withMessages(["allocations.{$index}.open_item_id" => 'รายการคงค้างไม่ตรงกับคลัง คู่ค้า หรือประเภทเอกสาร']);
                }
                if ($item->posting_date->format('Y-m-d') > $values['settlement_date']) {
                    throw ValidationException::withMessages(["allocations.{$index}.open_item_id" => 'รายการคงค้างต้อง Post ไม่เกินวันที่รับ/จ่าย']);
                }

                $openItems->assertAmountAvailable($item, $values['settlement_date'], $input['amount'], "allocations.{$index}.amount");
                $intentRows[] = [
                    'open_item_id' => $item->id,
                    'line_number' => $index + 1,
                    'amount' => JournalBalance::decimal($input['amount']),
                ];
            }
            $settlement->allocationIntents()->createMany($intentRows);

            $after = $settlement->only([
                'document_type', 'document_number', 'document_date', 'settlement_date', 'party_type', 'party_id',
                'bank_account_id', 'payment_term_id', 'gross_amount', 'tax_amount', 'withholding_amount', 'net_amount', 'status',
            ]);
            $after['allocation_intents'] = $intentRows;
            $after['tenders'] = $settlement->tenders->map->only(['bank_account_id', 'line_number', 'amount', 'reference'])->all();
            $audit->record('finance.settlement.created', $settlement, [], $after, $request->user(), $request);

            return $settlement;
        });

        return response()->json([
            'status' => true,
            'msg' => "สร้างร่างเอกสาร {$settlement->document_number} แล้ว",
            'redirect' => route('finance.settlements.index'),
            'settlement_id' => $settlement->id,
        ]);
    }

    public function approve(ChangeSettlementStatusRequest $request, Settlement $settlement, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $settlement, $audit, 'approve');

        return response()->json(['status' => true, 'msg' => "อนุมัติเอกสาร {$settlement->document_number} แล้ว"]);
    }

    public function void(ChangeSettlementStatusRequest $request, Settlement $settlement, AuditLogger $audit): JsonResponse
    {
        $this->transition($request, $settlement, $audit, 'void');

        return response()->json(['status' => true, 'msg' => "ยกเลิกเอกสาร {$settlement->document_number} แล้ว"]);
    }

    public function post(PostSettlementRequest $request, Settlement $settlement, SettlementPostingService $posting, AuditLogger $audit): JsonResponse
    {
        $warehouse = $this->scopeSettlement($request, $settlement);
        $request->attributes->set('selectedWarehouse', $warehouse);
        $before = $settlement->only(['status', 'journal_entry_id', 'posted_by', 'posted_at']);
        $posted = $posting->post($settlement, $warehouse, $request->user(), $request);
        $audit->record('finance.settlement.posted', $posted, $before, $posted->only(['status', 'journal_entry_id', 'posted_by', 'posted_at']), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "ลงบัญชีเอกสาร {$posted->document_number} แล้ว"]);
    }

    public function createAdvanceDeposit(Request $request, Settlement $settlement, AdvanceDepositSettlementService $service, AuditLogger $audit): JsonResponse
    {
        $values = $request->validate(['instrument_type' => ['required', Rule::in(['ADVANCE', 'DEPOSIT'])]]);
        $warehouse = $this->scopeSettlement($request, $settlement);
        $request->attributes->set('selectedWarehouse', $warehouse);
        $advance = $settlement->status === 'APPROVED'
            ? $service->postSettlementAsAdvance($settlement, $warehouse, $values['instrument_type'], $request->user())
            : $service->postFromPostedSettlement($settlement, $warehouse, $values['instrument_type'], $request->user());
        $audit->record('finance.advance_deposit.created_from_settlement', $advance, [], $advance->only(['document_number', 'source_settlement_id', 'party_type', 'direction', 'instrument_type', 'original_amount', 'journal_entry_id']), $request->user(), $request);

        $label = $advance->instrument_type === 'DEPOSIT' ? 'เงินมัดจำ' : 'เงินล่วงหน้า';

        return response()->json(['status' => true, 'msg' => "สร้าง{$label}จาก Settlement {$settlement->document_number} แล้ว", 'redirect' => route('finance.advance-deposits.index')]);
    }

    public function reverse(ReverseSettlementRequest $request, Settlement $settlement, SettlementReversalService $reversal, AuditLogger $audit): JsonResponse
    {
        $warehouse = $this->scopeSettlement($request, $settlement);
        $request->attributes->set('selectedWarehouse', $warehouse);
        $before = $settlement->only(['status', 'journal_entry_id', 'reversal_journal_entry_id']);
        $result = $reversal->reverse($settlement, $warehouse, $request->validated('reversal_date'), $request->validated('reason'), $request->user(), $request);
        $audit->record('finance.settlement.reversed', $result, $before, $result->only(['status', 'journal_entry_id', 'reversal_journal_entry_id', 'reversal_date', 'reversal_reason']), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => "กลับรายการ {$result->document_number} แล้ว"]);
    }

    private function transition(ChangeSettlementStatusRequest $request, Settlement $settlement, AuditLogger $audit, string $transition): void
    {
        DB::transaction(function () use ($request, $settlement, $audit, $transition) {
            $warehouseIds = $this->authorizedWarehouseIds($request);
            $settlement = Settlement::query()
                ->whereKey($settlement->id)
                ->whereHas('bankAccount', fn ($query) => $query->whereIn('warehouse_id', $warehouseIds))
                ->lockForUpdate()
                ->firstOrFail();

            try {
                $status = SettlementState::{$transition}($settlement->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }

            $fields = $transition === 'approve'
                ? ['status', 'approved_by', 'approved_at', 'approval_reason']
                : ['status', 'voided_by', 'voided_at', 'void_reason'];
            $before = $settlement->only($fields);
            $settlement->update($transition === 'approve' ? [
                'status' => $status,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_reason' => $request->validated('reason'),
            ] : [
                'status' => $status,
                'voided_by' => $request->user()->id,
                'voided_at' => now(),
                'void_reason' => $request->validated('reason'),
            ]);
            $action = $transition === 'approve' ? 'approved' : 'voided';
            $audit->record("finance.settlement.{$action}", $settlement, $before, $settlement->only($fields), $request->user(), $request);
        });
    }

    private function withholdingFor(OpenItem $item, string $date): string
    {
        if (! $item->withholding_tax_code_id || JournalBalance::decimal($item->withholding_amount) === '0.00') {
            return '0.00';
        }

        $allocated = Allocation::query()
            ->where(fn (Builder $query) => $query->where('debit_open_item_id', $item->id)->orWhere('credit_open_item_id', $item->id))
            ->where('allocation_date', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))
            ->sum('amount');
        $realized = WithholdingRealization::query()
            ->where('open_item_id', $item->id)->where('settlement_date', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $date))
            ->sum('tax_amount');

        return WhtRealizationCalculator::calculate(
            $item->original_amount, $item->withholding_base, $item->withholding_amount,
            $item->remaining_amount, (string) $allocated, (string) $realized,
        )['tax'];
    }

    private function settlementsQuery(Request $request): Builder
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;

        return Settlement::query()
            ->join('finance_bank_accounts as bank_accounts', 'bank_accounts.id', '=', 'finance_settlements.bank_account_id')
            ->join('warehouses', 'warehouses.id', '=', 'bank_accounts.warehouse_id')
            ->leftJoin('journal_entries', 'journal_entries.id', '=', 'finance_settlements.journal_entry_id')
            ->leftJoin('parties', 'parties.id', '=', 'finance_settlements.party_id')
            ->leftJoin('finance_advance_deposits as advance_deposits', 'advance_deposits.source_settlement_id', '=', 'finance_settlements.id')
            ->where('warehouses.branch_id', $branchId)
            ->whereIn('bank_accounts.warehouse_id', $this->authorizedWarehouseIds($request))
            ->select([
                'finance_settlements.*',
                'bank_accounts.code as bank_code',
                'bank_accounts.name as bank_name',
                'journal_entries.entry_number as journal_entry_number',
                'parties.code as party_code',
                'parties.name as party_name',
                'advance_deposits.id as advance_deposit_id',
                'advance_deposits.document_number as advance_document_number',
            ])
            ->withCount(['allocationIntents as intent_count'])
            ->withSum(['allocationIntents as intent_amount'], 'amount');
    }

    private function optionFilters(Request $request, bool $requireParty = false): array
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'document_type' => ['required', Rule::in(['RECEIPT', 'PAYMENT'])],
            'settlement_date' => ['required', 'date_format:Y-m-d'],
            'party_id' => [$requireParty ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);

        return [
            ...$values,
            'q' => trim((string) ($values['q'] ?? '')),
            'page' => (int) ($values['page'] ?? 1),
            'party_id' => isset($values['party_id']) ? (int) $values['party_id'] : null,
        ];
    }

    private function openItemOptionsQuery(Request $request, array $filters): Builder
    {
        $contract = $this->settlementContract($filters['document_type']);
        $foreignKey = $contract['balance_side'] === 'DEBIT' ? 'debit_open_item_id' : 'credit_open_item_id';
        $allocated = DB::table('finance_allocations')
            ->selectRaw("{$foreignKey} AS open_item_id, SUM(amount) AS allocated_amount")
            ->where('allocation_date', '<=', $filters['settlement_date'])
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $filters['settlement_date']))
            ->groupBy($foreignKey);
        $advanceApplied = DB::table('finance_advance_deposit_applications')
            ->selectRaw('open_item_id, SUM(amount) AS applied_amount')
            ->where('application_date', '<=', $filters['settlement_date'])
            ->where(fn ($query) => $query->whereNull('reversal_date')->orWhere('reversal_date', '>', $filters['settlement_date']))
            ->groupBy('open_item_id');

        return OpenItem::query()
            ->join('accounts', 'accounts.id', '=', 'finance_open_items.account_id')
            ->join('parties', 'parties.id', '=', 'finance_open_items.party_id')
            ->join('party_roles', fn ($join) => $join
                ->on('party_roles.party_id', '=', 'parties.id')
                ->where('party_roles.role', $contract['party_type'])
                ->where('party_roles.is_active', true))
            ->leftJoinSub($allocated, 'allocated', 'allocated.open_item_id', '=', 'finance_open_items.id')
            ->leftJoinSub($advanceApplied, 'advance_applied', 'advance_applied.open_item_id', '=', 'finance_open_items.id')
            ->whereIn('finance_open_items.warehouse_id', $this->authorizedWarehouseIds($request))
            ->where('finance_open_items.ledger_type', $contract['ledger_type'])
            ->where('finance_open_items.party_type', $contract['party_type'])
            ->where('finance_open_items.balance_side', $contract['balance_side'])
            ->where('finance_open_items.posting_date', '<=', $filters['settlement_date'])
            ->where('accounts.control_account_type', $contract['ledger_type'])
            ->where('accounts.is_active', true)
            ->where('accounts.is_postable', true)
            ->whereNull('accounts.deleted_at')
            ->where('parties.is_active', true)
            ->whereNull('parties.deleted_at')
            ->whereRaw('finance_open_items.original_amount - COALESCE(allocated.allocated_amount, 0) - COALESCE(advance_applied.applied_amount, 0) > 0')
            ->select('finance_open_items.*')
            ->selectRaw('finance_open_items.original_amount - COALESCE(allocated.allocated_amount, 0) - COALESCE(advance_applied.applied_amount, 0) AS remaining_amount');
    }

    private function settlementContract(string $documentType): array
    {
        return $documentType === 'RECEIPT'
            ? ['ledger_type' => 'AR', 'party_type' => 'CUSTOMER', 'balance_side' => 'DEBIT']
            : ['ledger_type' => 'AP', 'party_type' => 'SUPPLIER', 'balance_side' => 'CREDIT'];
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function bankAccounts(Request $request): Builder
    {
        return BankAccount::query()->where('is_active', true)
            ->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))
            ->orderBy('code');
    }

    private function scopeSettlement(Request $request, Settlement $settlement): Warehouse
    {
        $settlement->loadMissing('bankAccount.warehouse');
        $warehouse = $settlement->bankAccount?->warehouse;
        abort_unless($warehouse
            && (int) $warehouse->branch_id === (int) $request->attributes->get('selectedBranch')->id
            && in_array((int) $warehouse->id, $this->authorizedWarehouseIds($request), true), 404);

        return $warehouse;
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('finance_settlements.document_number', 'like', "%{$search}%")
                ->orWhere('finance_settlements.document_type', 'like', "%{$search}%")
                ->orWhere('finance_settlements.party_type', 'like', "%{$search}%")
                ->orWhere('parties.code', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")
                ->orWhere('finance_settlements.status', 'like', "%{$search}%")
                ->orWhere('finance_settlements.description', 'like', "%{$search}%")
                ->orWhere('bank_accounts.code', 'like', "%{$search}%")
                ->orWhere('bank_accounts.name', 'like', "%{$search}%")
                ->orWhere('journal_entries.entry_number', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'finance_settlements.document_number',
            1 => 'finance_settlements.document_type',
            2 => 'finance_settlements.settlement_date',
            3 => 'parties.code',
            4 => 'bank_accounts.code',
            5 => 'finance_settlements.net_amount',
            6 => 'journal_entries.entry_number',
            7 => 'finance_settlements.status',
        ];
        $column = $columns[(int) $request->input('order.0.column', 2)] ?? 'finance_settlements.settlement_date';
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $query->reorder($column, $direction)->orderByDesc('finance_settlements.id');
    }
}
