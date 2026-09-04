<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Accounting\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankReconciliationController extends Controller
{
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['วันที่', 'รายละเอียด', 'Reference', 'จำนวนเงิน', 'ยอดคงเหลือ']);
            fputcsv($handle, [now()->toDateString(), 'ตัวอย่างเงินรับโอน', 'REF-001', '1000.00', '1000.00']);
            fputcsv($handle, [now()->toDateString(), 'ตัวอย่างค่าธรรมเนียมธนาคาร', 'FEE-001', '-25.00', '975.00']);
            fclose($handle);
        }, 'bank-statement-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function index(Request $request): View
    {
        $warehouseId = $request->attributes->get('selectedWarehouse')?->id;
        $bankAccounts = BankAccount::query()->where('is_active', true)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->orderBy('code')->get(['id', 'code', 'name']);
        $statements = BankStatement::query()->with('bankAccount')->when($warehouseId, fn ($q) => $q->whereHas('bankAccount', fn ($b) => $b->where('warehouse_id', $warehouseId)))->latest('statement_date')->latest('id')->limit(30)->get();

        return view('Accounting::bank-reconciliation.index', compact('bankAccounts', 'statements'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['required', 'integer', 'exists:finance_bank_accounts,id'],
            'statement_date' => ['required', 'date'],
            'opening_balance' => ['nullable', 'numeric'],
            'closing_balance' => ['nullable', 'numeric'],
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $warehouseId = $request->attributes->get('selectedWarehouse')?->id;
        $bank = BankAccount::query()->whereKey($data['bank_account_id'])->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->firstOrFail();
        $file = $request->file('statement');
        $handle = fopen($file->getRealPath(), 'rb');
        $rows = [];
        $lineNumber = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            $date = trim((string) ($row[0] ?? ''));
            if ($lineNumber === 1 && ! preg_match('/\\d/', $date)) continue;
            try { $transactionDate = Carbon::parse($date)->toDateString(); } catch (\Throwable) { continue; }
            $amount = str_replace([',', ' '], '', trim((string) ($row[3] ?? $row[2] ?? '')));
            if ($amount === '' || ! is_numeric($amount)) continue;
            $rows[] = ['line_number' => count($rows) + 1, 'transaction_date' => $transactionDate, 'description' => trim((string) ($row[1] ?? '')) ?: null, 'reference' => trim((string) ($row[2] ?? '')) ?: null, 'amount' => (float) $amount, 'running_balance' => isset($row[4]) && is_numeric(str_replace(',', '', $row[4])) ? (float) str_replace(',', '', $row[4]) : null, 'status' => 'UNMATCHED'];
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['statement' => 'ไม่พบรายการที่อ่านได้จาก CSV (รูปแบบ: วันที่, รายละเอียด, Reference, จำนวนเงิน, ยอดคงเหลือ)']);

        DB::transaction(function () use ($data, $bank, $rows, $file, $request): void {
            $statement = BankStatement::query()->create(['bank_account_id' => $bank->id, 'statement_date' => $data['statement_date'], 'opening_balance' => $data['opening_balance'] ?? 0, 'closing_balance' => $data['closing_balance'] ?? 0, 'source_file_name' => $file->getClientOriginalName(), 'status' => 'DRAFT', 'created_by' => $request->user()->id]);
            $statement->lines()->createMany($rows);
        });

        return back()->with('success', 'นำเข้า Bank Statement แล้ว และตั้งรายการเป็น Unmatched เพื่อรอจับคู่');
    }

    public function show(Request $request, BankStatement $bankStatement): View
    {
        $warehouseId = $request->attributes->get('selectedWarehouse')?->id;
        $bankStatement->load(['bankAccount', 'lines.matchedJournalLine.entry']);
        abort_unless(! $warehouseId || $bankStatement->bankAccount->warehouse_id === $warehouseId, 404);

        $candidatePool = JournalEntryLine::query()->with(['entry', 'account'])->where('account_id', $bankStatement->bankAccount->account_id)
            ->whereHas('entry', fn ($q) => $q->where('status', 'POSTED')->whereBetween('entry_date', [$bankStatement->statement_date->copy()->subDays(31), $bankStatement->statement_date->copy()->addDays(31)]))
            ->limit(500)->get();
        $candidatesByLine = $bankStatement->lines->mapWithKeys(function ($line) use ($candidatePool) {
            $amount = abs((float) $line->amount);
            return [$line->id => $candidatePool->filter(function ($candidate) use ($line, $amount) {
                $candidateAmount = abs((float) $candidate->debit ?: (float) $candidate->credit);
                return abs($candidateAmount - $amount) <= 0.005 && abs($candidate->entry->entry_date->diffInDays($line->transaction_date)) <= 7;
            })->values()];
        });

        return view('Accounting::bank-reconciliation.show', compact('bankStatement', 'candidatesByLine'));
    }

    public function reconcile(Request $request, BankStatement $bankStatement): RedirectResponse
    {
        $warehouseId = $request->attributes->get('selectedWarehouse')?->id;
        $bankStatement->load(['bankAccount', 'lines.matchedJournalLine.entry']);
        abort_unless(! $warehouseId || $bankStatement->bankAccount->warehouse_id === $warehouseId, 404);

        if ($bankStatement->lines->contains(fn ($line) => $line->status !== 'MATCHED' || ! $line->matchedJournalLine)) {
            throw ValidationException::withMessages(['reconcile' => 'ยังมีรายการธนาคารที่ไม่ได้จับคู่กับ Journal']);
        }

        $statementMovement = (float) $bankStatement->lines->sum('amount');
        $glMovement = (float) $bankStatement->lines->sum(fn ($line) => (float) $line->matchedJournalLine->debit - (float) $line->matchedJournalLine->credit);
        if (abs($statementMovement - $glMovement) > 0.01) {
            throw ValidationException::withMessages(['reconcile' => 'ยอดเคลื่อนไหว Statement ไม่ตรงกับ GL (ต่าง '.number_format(abs($statementMovement - $glMovement), 2).' บาท)']);
        }

        $expectedClosing = (float) $bankStatement->opening_balance + $statementMovement;
        if ((float) $bankStatement->closing_balance !== 0.0 && abs($expectedClosing - (float) $bankStatement->closing_balance) > 0.01) {
            throw ValidationException::withMessages(['reconcile' => 'ยอดปิด Statement ไม่ตรงกับยอดยกมาและรายการเคลื่อนไหว']);
        }

        $bankStatement->update(['status' => 'RECONCILED']);
        return back()->with('success', 'กระทบยอดธนาคารสำเร็จ: Statement ตรงกับ GL แล้ว');
    }

    public function match(Request $request, BankStatementLine $bankStatementLine): RedirectResponse
    {
        $data = $request->validate(['journal_entry_line_id' => ['required', 'integer', 'exists:journal_entry_lines,id']]);
        $bankStatementLine->load('statement.bankAccount');
        $warehouseId = $request->attributes->get('selectedWarehouse')?->id;
        abort_unless(! $warehouseId || $bankStatementLine->statement->bankAccount->warehouse_id === $warehouseId, 404);
        $journalLine = JournalEntryLine::query()->with('entry')->whereKey($data['journal_entry_line_id'])->where('account_id', $bankStatementLine->statement->bankAccount->account_id)->whereHas('entry', fn ($q) => $q->where('status', 'POSTED'))->firstOrFail();
        $bankStatementLine->update(['matched_journal_entry_line_id' => $journalLine->id, 'status' => 'MATCHED']);
        $statement = $bankStatementLine->statement()->first();
        return back()->with('success', 'จับคู่รายการกับ Journal แล้ว');
    }
}
