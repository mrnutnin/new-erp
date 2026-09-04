<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Requests\StoreFiscalYearRequest;
use App\Modules\Accounting\Support\FiscalPeriodSchedule;
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

class FiscalYearController extends Controller
{
    public function index(): View
    {
        return view('Accounting::fiscal-years.index');
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent($this->yearsQuery())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('start_label', fn (FiscalYear $year) => $year->start_date->format('d/m/Y'))
            ->addColumn('end_label', fn (FiscalYear $year) => $year->end_date->format('d/m/Y'))
            ->addColumn('periods_url', fn (FiscalYear $year) => route('accounting.fiscal-years.show', $year))
            ->rawColumns(['code', 'name', 'start_label', 'end_label'])
            ->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->yearsQuery();
        $this->applySearch($query, $request);
        $this->applyOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Fiscal Years"><Table>';
            echo $this->excelRow(['รหัส', 'ชื่อปีบัญชี', 'วันเริ่ม', 'วันสิ้นสุด', 'Open', 'Soft close', 'Locked']);

            foreach ($query->lazy(500) as $year) {
                echo $this->excelRow([
                    $year->code,
                    $year->name,
                    $year->start_date->format('d/m/Y'),
                    $year->end_date->format('d/m/Y'),
                    $year->open_periods_count,
                    $year->soft_close_periods_count,
                    $year->locked_periods_count,
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'fiscal-years-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return view('Accounting::fiscal-years.create');
    }

    public function store(StoreFiscalYearRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $year = DB::transaction(function () use ($request, $audit) {
            CompanySetting::query()->lockForUpdate()->findOrFail(1);
            $startDate = $request->validated('start_date');
            $endDate = FiscalPeriodSchedule::endDate($startDate);
            $overlaps = FiscalYear::query()->where('start_date', '<=', $endDate)->where('end_date', '>=', $startDate)->exists();

            if ($overlaps) {
                throw ValidationException::withMessages(['start_date' => 'ช่วงปีบัญชีซ้อนทับกับปีบัญชีที่มีอยู่']);
            }

            $year = FiscalYear::query()->create([
                'code' => $request->validated('code'),
                'name' => $request->validated('name'),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'created_by' => $request->user()->id,
            ]);
            $year->periods()->createMany(FiscalPeriodSchedule::periods($startDate));
            $audit->record('accounting.fiscal_year.created', $year, [], [
                ...$year->only(['code', 'name', 'start_date', 'end_date']),
                'period_count' => 12,
            ], $request->user(), $request);

            return $year;
        });

        $redirect = route('accounting.fiscal-years.show', $year);

        return $request->expectsJson()
            ? response()->json(['status' => true, 'msg' => 'สร้างปีและงวดบัญชีแล้ว', 'redirect' => $redirect])
            : redirect($redirect)->with('success', 'สร้างปีและงวดบัญชีแล้ว');
    }

    public function show(FiscalYear $fiscalYear): View
    {
        $fiscalYear->load('periods');

        return view('Accounting::fiscal-years.show', compact('fiscalYear'));
    }

    private function yearsQuery(): Builder
    {
        return FiscalYear::query()
            ->withCount([
                'periods as open_periods_count' => fn ($query) => $query->where('status', 'OPEN'),
                'periods as soft_close_periods_count' => fn ($query) => $query->where('status', 'SOFT_CLOSE'),
                'periods as locked_periods_count' => fn ($query) => $query->where('status', 'LOCKED'),
            ]);
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        $status = $request->input('period_status');
        if (in_array($status, ['OPEN', 'SOFT_CLOSE', 'LOCKED'], true)) {
            $query->whereHas('periods', fn (Builder $periods) => $periods->where('status', $status));
        } elseif ($status === 'CLOSED') {
            $query->whereHas('periods')->whereDoesntHave('periods', fn (Builder $periods) => $periods->whereIn('status', ['OPEN', 'SOFT_CLOSE']));
        }

        $query->when($request->filled('start_date'), fn (Builder $q) => $q->whereDate('start_date', '>=', $request->input('start_date')))
            ->when($request->filled('end_date'), fn (Builder $q) => $q->whereDate('end_date', '<=', $request->input('end_date')));
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'start_date', 1 => 'code', 2 => 'name', 3 => 'end_date'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'start_date';
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $direction)->orderByDesc('id');
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
