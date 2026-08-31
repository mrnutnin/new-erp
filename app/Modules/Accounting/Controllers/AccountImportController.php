<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Requests\StageAccountImportRequest;
use App\Modules\Accounting\Services\AccountImportService;
use App\Modules\Accounting\Services\AccountWriter;
use App\Modules\Accounting\Support\ChartOfAccountsTemplate;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\SpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountImportController extends Controller
{
    public function create(): View
    {
        return view('Accounting::accounts.import.create');
    }

    public function template(SpreadsheetService $spreadsheets): BinaryFileResponse
    {
        return $spreadsheets->download('chart-of-accounts-'.ChartOfAccountsTemplate::VERSION.'.xlsx', ChartOfAccountsTemplate::sheets());
    }

    public function stage(StageAccountImportRequest $request, AccountImportService $imports): JsonResponse
    {
        $batch = $imports->stage($request->file('file'), $request->validated('source_system'), $request->user());

        return response()->json([
            'status' => true,
            'msg' => 'ตรวจสอบไฟล์และสร้าง Import Batch แล้ว',
            'redirect' => route('accounting.account-import.show', $batch),
        ]);
    }

    public function show(MigrationImportBatch $batch): View
    {
        $this->ensureCoaBatch($batch);

        return view('Accounting::accounts.import.show', compact('batch'));
    }

    public function errors(MigrationImportBatch $batch, SpreadsheetService $spreadsheets): BinaryFileResponse
    {
        $this->ensureCoaBatch($batch);
        $rows = collect($batch->staged_rows)->filter(fn (array $row) => $row['errors'] !== [])->map(fn (array $row) => [
            $row['row_number'],
            $row['normalized']['row_key'],
            $row['normalized']['code'],
            implode(' | ', $row['errors']),
        ])->values()->all();

        return $spreadsheets->download("coa-import-{$batch->id}-errors.xlsx", [[
            'title' => 'Errors',
            'headings' => ['row_number', 'row_key', 'code', 'errors'],
            'rows' => $rows,
        ]]);
    }

    public function commit(
        Request $request,
        MigrationImportBatch $batch,
        AccountImportService $imports,
        AccountWriter $writer,
        AuditLogger $audit,
    ): JsonResponse {
        $this->ensureCoaBatch($batch);
        $imports->commit($batch, $request->user(), $request, $audit, $writer);

        return response()->json([
            'status' => true,
            'msg' => 'นำเข้าผังบัญชีเรียบร้อยแล้ว',
            'redirect' => route('accounting.accounts.index'),
        ]);
    }

    private function ensureCoaBatch(MigrationImportBatch $batch): void
    {
        abort_unless($batch->type === ChartOfAccountsTemplate::TYPE, 404);
    }
}
