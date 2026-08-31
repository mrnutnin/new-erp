<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class FinanceReportController extends Controller
{
    public function paymentIndex(): View
    {
        return view('Finance::reports.payment-activity');
    }

    public function paymentData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $dateFormat = (string) $settings->value('date_format');
        $query = $this->paymentQuery($request);

        return DataTables::query($query)
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('date_label', fn ($row) => Carbon::parse($row->document_date)->format($dateFormat))
            ->addColumn('type_label', fn ($row) => $row->document_type === 'RECEIPT' ? 'รับเงิน' : 'จ่ายเงิน')
            ->addColumn('party_label', fn ($row) => $row->party_code ? $row->party_code.' · '.$row->party_name : '—')
            ->addColumn('status_label', fn ($row) => match ($row->status) {
                'POSTED' => 'ลงบัญชีแล้ว', 'APPROVED' => 'อนุมัติแล้ว', 'VOID' => 'ยกเลิก', default => 'ร่าง',
            })
            ->addColumn('show_url', fn ($row) => route('finance.settlements.show', $row->id))
            ->toJson();
    }

    private function paymentQuery(Request $request): Builder
    {
        return DB::table('finance_settlements as s')
            ->join('finance_bank_accounts as b', 'b.id', '=', 's.bank_account_id')
            ->leftJoin('parties as p', 'p.id', '=', 's.party_id')
            ->leftJoin('journal_entries as j', 'j.id', '=', 's.journal_entry_id')
            ->whereIn('b.warehouse_id', $this->authorizedWarehouseIds($request))
            ->whereNull('s.deleted_at')
            ->select([
                's.id', 's.document_number', 's.document_type', 's.document_date', 's.status',
                's.net_amount', 'b.code as bank_code', 'p.code as party_code', 'p.name as party_name',
                'j.entry_number as journal_number',
            ]);
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('s.document_number', 'like', "%{$search}%")
                ->orWhere('s.document_type', 'like', "%{$search}%")
                ->orWhere('s.status', 'like', "%{$search}%")
                ->orWhere('p.code', 'like', "%{$search}%")
                ->orWhere('p.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 's.document_number', 1 => 's.document_type', 2 => 's.document_date', 3 => 'p.code', 4 => 's.net_amount', 5 => 's.status'];
        $column = $columns[(int) $request->input('order.0.column', 2)] ?? $columns[2];
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($column, $direction)->orderBy('s.id', 'desc');
    }
}
