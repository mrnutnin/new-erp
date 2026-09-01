<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetOpeningBalanceBatch;
use App\Modules\Asset\Services\AssetOpeningBalanceStagingService;
use App\Modules\Asset\Services\AssetOpeningBalanceCommitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssetImportController extends Controller
{
    public function create(): View
    {
        return view('Asset::imports.create');
    }

    public function index(): View
    {
        return view('Asset::imports.index');
    }

    public function data(Request $request): JsonResponse
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;

        return DataTables::eloquent(AssetOpeningBalanceBatch::query()->where('branch_id', $branchId)->withCount('lines')->latest('id'))
            ->addColumn('show_url', fn (AssetOpeningBalanceBatch $batch) => route('asset.assets.import.show', $batch))
            ->toJson();
    }

    public function show(Request $request, AssetOpeningBalanceBatch $batch): View
    {
        abort_unless((int) $batch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);

        return view('Asset::imports.show', ['batch' => $batch->load(['createdBy', 'validatedBy', 'committedBy', 'lines'])]);
    }

    public function commit(Request $request, AssetOpeningBalanceBatch $batch, AssetOpeningBalanceCommitService $committer): JsonResponse
    {
        abort_unless((int) $batch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $committer->commit($batch, $request->user());

        return response()->json(['status' => true, 'msg' => 'นำเข้าและสร้างทะเบียนสินทรัพย์เรียบร้อยแล้ว', 'redirect' => route('asset.assets.import.show', $batch)]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            echo "รหัสแถว,เลขทะเบียนสินทรัพย์,ชื่อสินทรัพย์,รหัสหมวดสินทรัพย์,วันที่ได้มา,วันที่พร้อมใช้งาน,ต้นทุนยกมา,ค่าเสื่อมสะสมยกมา,ด้อยค่าสะสมยกมา,เอกสารอ้างอิง\n";
            echo "FA-001,FA-001,ตัวอย่างสินทรัพย์,1,2026-01-01,2026-01-01,10000.00,0.00,0.00,OPENING-001\n";
        }, 'asset-opening-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function stage(Request $request, AssetOpeningBalanceStagingService $staging): JsonResponse
    {
        $data = $request->validate([
            'batch_reference' => ['nullable', 'string', 'max:100'],
            'cutover_date' => ['required', 'date'],
            'reconciliation_reference' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);
        $branch = $request->attributes->get('selectedBranch');
        $data['batch_reference'] = $this->nextBatchReference((int) $branch->id);
        $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        $headers = array_map(fn ($header): string => trim((string) $header), array_shift($rows) ?: []);
        $aliases = ['รหัสแถว' => 'row_key', 'เลขทะเบียนสินทรัพย์' => 'asset_number', 'ชื่อสินทรัพย์' => 'name', 'รหัสหมวดสินทรัพย์' => 'asset_category_id', 'วันที่ได้มา' => 'acquisition_date', 'วันที่พร้อมใช้งาน' => 'placed_in_service_date', 'ต้นทุนยกมา' => 'opening_cost', 'ค่าเสื่อมสะสมยกมา' => 'opening_accumulated_depreciation', 'ด้อยค่าสะสมยกมา' => 'opening_accumulated_impairment', 'เอกสารอ้างอิง' => 'source_reference'];
        $headers = array_map(fn (string $header): string => $aliases[$header] ?? $header, $headers);
        $required = ['row_key', 'asset_number', 'name', 'asset_category_id', 'acquisition_date', 'opening_cost'];
        $missing = array_values(array_diff($required, $headers));
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'หัวตารางไม่ครบ: '.implode(', ', $missing)]);
        }
        $index = array_flip($headers);
        $errors = [];
        $normalized = [];
        $keys = [];
        foreach (array_slice($rows, 0, 2000) as $rowNumber => $row) {
            $line = $rowNumber + 2;
            $value = fn (string $key): string => trim((string) ($row[$index[$key]] ?? ''));
            $rowKey = $value('row_key');
            $categoryId = (int) $value('asset_category_id');
            if ($rowKey === '' || in_array($rowKey, $keys, true)) {
                $errors["row_{$line}"][] = 'row_key ต้องไม่ว่างและห้ามซ้ำในไฟล์';
            }
            if (! AssetCategory::query()->whereKey($categoryId)->where('is_active', true)->exists()) {
                $errors["row_{$line}"][] = 'ไม่พบหมวดสินทรัพย์ที่ใช้งานได้ในสาขานี้';
            }
            if ($value('asset_number') === '' || $value('name') === '' || $value('acquisition_date') === '' || $value('opening_cost') === '') {
                $errors["row_{$line}"][] = 'asset_number, name, acquisition_date และ opening_cost ต้องครบ';
            }
            $keys[] = $rowKey;
            $normalized[] = ['row_key' => $rowKey, 'source_reference' => $value('source_reference') ?: null, 'asset_payload' => ['asset_number' => $value('asset_number'), 'name' => $value('name'), 'asset_category_id' => $categoryId, 'acquisition_date' => $value('acquisition_date'), 'placed_in_service_date' => $value('placed_in_service_date') ?: null], 'opening_cost' => $value('opening_cost'), 'opening_accumulated_depreciation' => $value('opening_accumulated_depreciation') ?: '0', 'opening_accumulated_impairment' => $value('opening_accumulated_impairment') ?: '0'];
        }
        if (count($rows) > 2000) {
            $errors['file'][] = 'รองรับไม่เกิน 2,000 แถวต่อ Batch';
        }
        if ($normalized === []) {
            $errors['file'][] = 'ไฟล์ไม่มีข้อมูลรายการ';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $batch = DB::transaction(function () use ($staging, $branch, $data, $normalized, $request) {
            $batch = $staging->create($branch, $data, $request->user());
            foreach ($normalized as $line) {
                $staging->addLine($batch, $line);
            }
            $batch = $staging->validate($batch, $request->user());
            return $batch;
        });

        return response()->json(['status' => true, 'msg' => 'ตรวจสอบไฟล์และสร้าง Opening Balance batch แล้ว กรุณาตรวจสอบและกดนำเข้าเพื่อสร้างทะเบียนสินทรัพย์', 'redirect' => route('asset.assets.import.show', $batch)]);
    }

    private function nextBatchReference(int $branchId): string
    {
        do {
            $reference = 'OB-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));
        } while (DB::table('asset_opening_balance_batches')->where('branch_id', $branchId)->where('batch_reference', $reference)->exists());

        return $reference;
    }
}
