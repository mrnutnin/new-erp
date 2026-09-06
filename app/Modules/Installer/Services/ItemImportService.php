<?php

namespace App\Modules\Installer\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\Uom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemImportService
{
    private const HEADERS = ['code', 'name', 'item_type', 'category_code', 'base_uom_code', 'is_stock_item', 'inventory_account_code', 'sales_account_code', 'cogs_account_code'];
    private const MAX_ROWS = 2000;

    public function stage(UploadedFile $file, User $user): MigrationImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($existing = MigrationImportBatch::query()->where('type', 'INSTALLER_ITEMS')->where('checksum', $checksum)->first()) return $existing;
        $handle = fopen($file->getRealPath(), 'rb');
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        if ($headers !== self::HEADERS) throw ValidationException::withMessages(['file' => 'หัวตาราง CSV ไม่ถูกต้อง: '.implode(', ', self::HEADERS)]);
        $categories = ItemCategory::query()->where('is_active', true)->get()->keyBy(fn ($row) => strtoupper($row->code));
        $uoms = Uom::query()->where('is_active', true)->get()->keyBy(fn ($row) => strtoupper($row->code));
        $accounts = Account::query()->where('is_active', true)->where('is_postable', true)->get()->keyBy(fn ($row) => strtoupper($row->code));
        $seen = [];
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            if (count($rows) >= self::MAX_ROWS) throw ValidationException::withMessages(['file' => 'รองรับสูงสุด '.self::MAX_ROWS.' รายการต่อไฟล์']);
            $source = array_combine(self::HEADERS, array_pad($values, count(self::HEADERS), null));
            $normalized = $this->normalize($source);
            $errors = $this->validate($normalized, $seen, $categories, $uoms, $accounts);
            $rows[] = ['row_number' => count($rows) + 2, 'source' => $source, 'normalized' => $normalized, 'errors' => $errors];
            if ($normalized['code'] !== '') $seen[$normalized['code']] = true;
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'กรุณากรอกข้อมูลอย่างน้อย 1 รายการ']);
        $errors = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));
        return MigrationImportBatch::query()->create(['type' => 'INSTALLER_ITEMS', 'template_version' => 'ITEM-CSV-1.0', 'source_system' => 'installer', 'original_filename' => $file->getClientOriginalName(), 'checksum' => $checksum, 'status' => $errors === 0 ? 'VALIDATED' : 'INVALID', 'total_rows' => count($rows), 'valid_rows' => count($rows) - $errors, 'error_rows' => $errors, 'staged_rows' => $rows, 'created_by' => $user->id]);
    }

    public function commit(MigrationImportBatch $batch, User $user): int
    {
        return DB::transaction(function () use ($batch, $user): int {
            $batch = MigrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->type !== 'INSTALLER_ITEMS' || $batch->status !== 'VALIDATED' || $batch->error_rows > 0) throw ValidationException::withMessages(['batch' => 'ไฟล์สินค้ายังไม่ผ่านการตรวจสอบ']);
            if ((int) $batch->created_by !== (int) $user->id) throw ValidationException::withMessages(['batch' => 'ผู้สร้างไฟล์เท่านั้นที่ยืนยันได้']);
            foreach ($batch->staged_rows as $row) {
                $values = $row['normalized'];
                $item = Item::query()->withTrashed()->firstOrNew(['code' => $values['code']]);
                $item->fill([...$values, 'is_active' => true, 'created_by' => $item->created_by ?: $user->id]);
                $item->deleted_at = null;
                $item->save();
            }
            $batch->update(['status' => 'COMMITTED', 'committed_by' => $user->id, 'committed_at' => now()]);
            return count($batch->staged_rows);
        });
    }

    private function normalize(array $source): array
    {
        $row = collect(self::HEADERS)->mapWithKeys(fn (string $key) => [$key => trim((string) ($source[$key] ?? ''))])->all();
        $row['code'] = strtoupper($row['code']);
        $row['item_type'] = strtoupper($row['item_type']);
        $row['category_code'] = strtoupper($row['category_code']);
        $row['base_uom_code'] = strtoupper($row['base_uom_code']);
        $row['is_stock_item'] = in_array(strtolower($row['is_stock_item']), ['1', 'true', 'yes', 'y'], true);
        return $row;
    }

    private function validate(array &$row, array $seen, $categories, $uoms, $accounts): array
    {
        $errors = [];
        if ($row['code'] === '' || isset($seen[$row['code']])) $errors[] = 'รหัสต้องมีค่าและไม่ซ้ำในไฟล์';
        if ($row['name'] === '') $errors[] = 'ชื่อต้องมีค่า';
        if (! in_array($row['item_type'], ['GOODS', 'SERVICE'], true)) $errors[] = 'ประเภทต้องเป็น GOODS หรือ SERVICE';
        if (! $categories->has($row['category_code'])) $errors[] = 'ไม่พบหมวดสินค้า'; else $row['category_id'] = $categories[$row['category_code']]->id;
        if (! $uoms->has($row['base_uom_code'])) $errors[] = 'ไม่พบหน่วยฐาน'; else { $row['base_uom_id'] = $uoms[$row['base_uom_code']]->id; $row['base_uom'] = $uoms[$row['base_uom_code']]->code; }
        if ($row['item_type'] === 'GOODS' && $row['is_stock_item'] && $row['inventory_account_code'] !== '') {
            $row['inventory_account_id'] = $accounts[$row['inventory_account_code']]->id ?? null;
            if (! $row['inventory_account_id']) $errors[] = 'ไม่พบบัญชี Inventory';
        }
        if ($row['sales_account_code'] !== '') { $row['sales_account_id'] = $accounts[$row['sales_account_code']]->id ?? null; if (! $row['sales_account_id']) $errors[] = 'ไม่พบบัญชี Sales'; }
        if ($row['item_type'] === 'GOODS' && $row['is_stock_item'] && $row['cogs_account_code'] !== '') { $row['cogs_account_id'] = $accounts[$row['cogs_account_code']]->id ?? null; if (! $row['cogs_account_id']) $errors[] = 'ไม่พบบัญชี COGS'; }
        if ($row['item_type'] === 'GOODS' && $row['is_stock_item'] && ($row['inventory_account_id'] ?? null) === null) $errors[] = 'สินค้า Stock ต้องระบุ inventory_account_code';
        if ($row['item_type'] === 'GOODS' && $row['is_stock_item'] && ($row['cogs_account_id'] ?? null) === null) $errors[] = 'สินค้า Stock ต้องระบุ cogs_account_code';
        return array_values(array_unique($errors));
    }
}
