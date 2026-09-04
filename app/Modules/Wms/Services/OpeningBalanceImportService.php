<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Platform\Services\SpreadsheetService;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Support\OpeningBalanceTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpeningBalanceImportService
{
    private const MAX_ROWS = 20000;
    public function __construct(private readonly SpreadsheetService $spreadsheets) {}
    public function stage(UploadedFile $file, User $user, array $defaults): MigrationImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($existing = MigrationImportBatch::query()->where('type', OpeningBalanceTemplate::TYPE)->where('checksum', $checksum)->first()) return $existing;
        $workbook = $this->spreadsheets->readXlsx($file, ['Opening Balance', '_meta']);
        $sheet = $workbook['Opening Balance'] ?? [];
        $meta = collect(array_slice($workbook['_meta'] ?? [], 1))->mapWithKeys(fn (array $row) => [(string) ($row[0] ?? '') => (string) ($row[1] ?? '')]);
        $headers = array_map(fn ($v) => strtolower(trim((string) $v)), $sheet[0] ?? []);
        if ($headers !== OpeningBalanceTemplate::HEADERS || $meta->get('template_type') !== OpeningBalanceTemplate::TYPE || $meta->get('template_version') !== OpeningBalanceTemplate::VERSION) throw ValidationException::withMessages(['file' => 'หัวตารางหรือรุ่น Template ไม่ถูกต้อง กรุณาดาวน์โหลดไฟล์ใหม่']);
        $rows = array_values(array_filter(array_slice($sheet, 1), fn (array $row) => collect($row)->contains(fn ($v) => trim((string) $v) !== '')));
        if ($rows === []) throw ValidationException::withMessages(['file' => 'กรุณากรอกข้อมูลอย่างน้อย 1 แถว']);
        if (count($rows) > self::MAX_ROWS) throw ValidationException::withMessages(['file' => 'รองรับสูงสุด '.self::MAX_ROWS.' แถวต่อไฟล์']);
        $warehouses = $user->warehouses()->with('branch')->where('warehouses.is_active', true)->get()->keyBy(fn ($w) => strtoupper($w->code));
        $items = Item::query()->with('baseUom')->where('is_active', true)->where('is_stock_item', true)->get()->keyBy(fn ($i) => strtoupper($i->code));
        $seen = []; $staged = [];
        foreach ($rows as $offset => $row) {
            $source = array_combine(OpeningBalanceTemplate::HEADERS, array_pad(array_slice($row, 0, count(OpeningBalanceTemplate::HEADERS)), count(OpeningBalanceTemplate::HEADERS), null));
            $normalized = $this->normalize($source, $defaults); $errors = $this->validateRow($normalized, $warehouses, $items, $seen);
            $staged[] = ['row_number' => $offset + 2, 'source' => $source, 'normalized' => $normalized, 'errors' => $errors]; $seen[$normalized['row_key']] = true;
        }
        $errorRows = count(array_filter($staged, fn (array $r) => $r['errors'] !== []));
        return DB::transaction(fn () => MigrationImportBatch::query()->create(['type' => OpeningBalanceTemplate::TYPE, 'template_version' => OpeningBalanceTemplate::VERSION, 'source_system' => 'wms', 'original_filename' => $file->getClientOriginalName(), 'checksum' => $checksum, 'status' => $errorRows === 0 ? 'VALIDATED' : 'INVALID', 'total_rows' => count($staged), 'valid_rows' => count($staged) - $errorRows, 'error_rows' => $errorRows, 'staged_rows' => $staged, 'created_by' => $user->id]));
    }

    public function commit(MigrationImportBatch $batch, User $user, OpeningBalanceService $openingBalances): array
    {
        return DB::transaction(function () use ($batch, $user, $openingBalances): array {
            $locked = MigrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->type !== OpeningBalanceTemplate::TYPE || $locked->status !== 'VALIDATED' || (int) $locked->error_rows > 0) {
                throw ValidationException::withMessages(['batch' => 'Batch นี้ยังไม่ผ่านการตรวจสอบ หรือถูกนำเข้าแล้ว']);
            }
            if ((int) $locked->created_by !== (int) $user->id) {
                throw ValidationException::withMessages(['batch' => 'คุณไม่มีสิทธิ์อนุมัติ Batch นี้']);
            }

            $warehouses = $user->warehouses()->where('warehouses.is_active', true)->get()->keyBy(fn ($w) => strtoupper($w->code));
            $items = Item::query()->with('baseUom')->where('is_active', true)->where('is_stock_item', true)->get()->keyBy(fn ($i) => strtoupper($i->code));
            $created = [];
            foreach (collect($locked->staged_rows)->groupBy(fn (array $row) => $row['normalized']['warehouse_code']) as $warehouseCode => $rows) {
                $warehouse = $warehouses->get($warehouseCode);
                if (! $warehouse) throw ValidationException::withMessages(['batch' => "ไม่มีสิทธิ์คลัง {$warehouseCode}"]);
                $first = $rows->first()['normalized'];
                $lines = $rows->map(function (array $row) use ($items): array {
                    $value = $row['normalized']; $item = $items->get($value['item_code']);
                    if (! $item || strtoupper((string) $item->baseUom?->code) !== $value['uom_code']) throw ValidationException::withMessages(['batch' => "สินค้า/หน่วยไม่ถูกต้องแถว {$row['row_number']}"]);
                    return ['item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'quantity' => $value['quantity'], 'total_value' => $value['total_value']];
                })->values()->all();
                $created[] = $openingBalances->createDraft(['warehouse_id' => $warehouse->id, 'cutover_date' => $first['cutover_date'], 'costing_method' => $first['costing_method'], 'source_reference' => $first['source_reference'] ?: 'IMPORT-'.$locked->id, 'lines' => $lines, 'idempotency_key' => 'opening-import:'.$locked->id.':'.$warehouse->id], $user);
            }
            $locked->update(['status' => 'COMMITTED', 'committed_by' => $user->id, 'committed_at' => now()]);
            return $created;
        });
    }
    private function normalize(array $row, array $defaults): array
    {
        $text = fn (string $key) => trim((string) ($row[$key] ?? ''));
        return ['row_key' => $text('row_key'), 'branch_code' => strtoupper($text('branch_code')), 'warehouse_code' => strtoupper($text('warehouse_code')), 'item_code' => strtoupper($text('item_code')), 'uom_code' => strtoupper($text('uom_code')), 'quantity' => $text('quantity'), 'total_value' => $text('total_value'), 'cutover_date' => $defaults['cutover_date'], 'costing_method' => strtoupper($defaults['costing_method']), 'source_reference' => $defaults['source_reference'] ?: null];
    }
    private function validateRow(array $row, $warehouses, $items, array $seen): array
    {
        $errors = [];
        if ($row['row_key'] === '' || isset($seen[$row['row_key']])) $errors[] = 'row_key ต้องมีค่าและไม่ซ้ำ';
        $warehouse = $warehouses->get($row['warehouse_code']);
        if (! $warehouse) $errors[] = 'ไม่พบคลัง หรือไม่มีสิทธิ์ใช้งาน'; elseif (strtoupper((string) $warehouse->branch?->code) !== $row['branch_code']) $errors[] = 'สาขาไม่ตรงกับคลัง';
        $item = $items->get($row['item_code']);
        if (! $item) $errors[] = 'ไม่พบสินค้า Stock ที่ใช้งานอยู่'; elseif (strtoupper((string) $item->baseUom?->code) !== $row['uom_code']) $errors[] = 'หน่วยต้องเป็นหน่วยฐานของสินค้า';
        if (! is_numeric($row['quantity']) || (float) $row['quantity'] <= 0) $errors[] = 'จำนวนต้องเป็นตัวเลขมากกว่า 0';
        if (! is_numeric($row['total_value']) || (float) $row['total_value'] < 0) $errors[] = 'ต้นทุนรวมต้องเป็นตัวเลขไม่น้อยกว่า 0';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['cutover_date']) || ! strtotime($row['cutover_date'])) $errors[] = 'วันที่ต้องเป็นรูปแบบ YYYY-MM-DD';
        if (! in_array($row['costing_method'], ['AVG', 'FIFO'], true)) $errors[] = 'วิธีต้นทุนต้องเป็น AVG หรือ FIFO';
        return array_values(array_unique($errors));
    }
}
