<?php

namespace App\Modules\Installer\Services;

use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Platform\Models\MigrationImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenItemImportService
{
    private const HEADERS = ['ledger_type', 'party_code', 'warehouse_code', 'account_code', 'document_number', 'document_date', 'posting_date', 'due_date', 'amount', 'offset_account_code'];

    public function stage(UploadedFile $file, User $user): MigrationImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($existing = MigrationImportBatch::query()->where('type', 'INSTALLER_OPEN_ITEMS')->where('checksum', $checksum)->first()) return $existing;
        $handle = fopen($file->getRealPath(), 'rb');
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        if ($headers !== self::HEADERS) throw ValidationException::withMessages(['file' => 'หัวตาราง CSV ไม่ถูกต้อง: '.implode(', ', self::HEADERS)]);
        $parties = Party::query()->where('is_active', true)->get()->keyBy(fn ($row) => strtoupper($row->code));
        $warehouses = Warehouse::query()->where('is_active', true)->get()->keyBy(fn ($row) => strtoupper($row->code));
        $accounts = Account::query()->where('is_active', true)->where('is_postable', true)->get()->keyBy(fn ($row) => strtoupper($row->code));
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            if (count($rows) >= 2000) throw ValidationException::withMessages(['file' => 'รองรับสูงสุด 2,000 รายการต่อไฟล์']);
            $source = array_combine(self::HEADERS, array_pad($values, count(self::HEADERS), null));
            $row = collect(self::HEADERS)->mapWithKeys(fn (string $key) => [$key => trim((string) ($source[$key] ?? ''))])->all();
            $row['ledger_type'] = strtoupper($row['ledger_type']);
            $errors = [];
            $party = $parties->get(strtoupper($row['party_code']));
            $warehouse = $warehouses->get(strtoupper($row['warehouse_code']));
            $account = $accounts->get(strtoupper($row['account_code']));
            $offset = $accounts->get(strtoupper($row['offset_account_code']));
            if (! in_array($row['ledger_type'], ['AR', 'AP'], true)) $errors[] = 'ledger_type ต้องเป็น AR หรือ AP';
            if (! $party || ! $party->roles()->where('role', $row['ledger_type'] === 'AR' ? 'CUSTOMER' : 'SUPPLIER')->where('is_active', true)->exists()) $errors[] = 'ไม่พบคู่ค้าหรือ Role ไม่ตรงกับ Ledger'; else $row['party_id'] = $party->id;
            if (! $warehouse) $errors[] = 'ไม่พบคลัง'; else { $row['warehouse_id'] = $warehouse->id; $row['branch_id'] = $warehouse->branch_id; }
            if (! $account || $account->control_account_type !== $row['ledger_type']) $errors[] = 'บัญชีคุมต้องเป็น '.$row['ledger_type']; else $row['account_id'] = $account->id;
            if (! $offset || $offset->control_account_type !== null) $errors[] = 'บัญชีคู่ต้องเป็นบัญชีย่อยที่ลงรายการได้'; else $row['offset_account_id'] = $offset->id;
            if ($row['document_number'] === '') $errors[] = 'document_number ต้องมีค่า';
            foreach (['document_date', 'posting_date'] as $date) if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$date]) || ! strtotime($row[$date])) $errors[] = $date.' ต้องเป็น YYYY-MM-DD';
            if ($row['due_date'] !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['due_date']) || ! strtotime($row['due_date']))) $errors[] = 'due_date ต้องเป็น YYYY-MM-DD';
            if (! is_numeric($row['amount']) || (float) $row['amount'] <= 0) $errors[] = 'amount ต้องมากกว่า 0';
            if ($row['due_date'] !== '' && $row['due_date'] < $row['document_date']) $errors[] = 'due_date ต้องไม่ก่อน document_date';
            $rows[] = ['row_number' => count($rows) + 2, 'source' => $source, 'normalized' => $row, 'errors' => array_values(array_unique($errors))];
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'กรุณากรอกข้อมูลอย่างน้อย 1 รายการ']);
        $errors = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));
        return MigrationImportBatch::query()->create(['type' => 'INSTALLER_OPEN_ITEMS', 'template_version' => 'OPEN-ITEM-CSV-1.0', 'source_system' => 'installer', 'original_filename' => $file->getClientOriginalName(), 'checksum' => $checksum, 'status' => $errors === 0 ? 'VALIDATED' : 'INVALID', 'total_rows' => count($rows), 'valid_rows' => count($rows) - $errors, 'error_rows' => $errors, 'staged_rows' => $rows, 'created_by' => $user->id]);
    }

    public function commit(MigrationImportBatch $batch, User $user, JournalPostingService $posting, OpenItemService $openItems): int
    {
        return DB::transaction(function () use ($batch, $user, $posting, $openItems): int {
            $batch = MigrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->type !== 'INSTALLER_OPEN_ITEMS' || $batch->status !== 'VALIDATED' || $batch->error_rows > 0) throw ValidationException::withMessages(['batch' => 'ไฟล์ AR/AP ยังไม่ผ่านการตรวจสอบ']);
            if ((int) $batch->created_by !== (int) $user->id) throw ValidationException::withMessages(['batch' => 'ผู้สร้างไฟล์เท่านั้นที่ยืนยันได้']);
            foreach ($batch->staged_rows as $index => $row) {
                $v = $row['normalized'];
                $event = $v['ledger_type'] === 'AR' ? 'opening_ar' : 'opening_ap';
                $control = $v['ledger_type'] === 'AR' ? ['debit' => $v['amount'], 'credit' => 0] : ['debit' => 0, 'credit' => $v['amount']];
                $offset = $v['ledger_type'] === 'AR' ? ['debit' => 0, 'credit' => $v['amount']] : ['debit' => $v['amount'], 'credit' => 0];
                $entry = $posting->post(['source_type' => 'INSTALLER', 'source_id' => $batch->id.':'.$index, 'source_reference' => $v['document_number'], 'event_code' => $event, 'entry_date' => $v['posting_date'], 'document_date' => $v['document_date'], 'description' => 'ยอดยกมา '.$v['document_number'], 'lines' => [array_merge(['account_id' => $v['account_id'], 'subledger_type' => $v['ledger_type'] === 'AR' ? 'CUSTOMER' : 'SUPPLIER', 'subledger_id' => $v['party_code'], 'description' => 'ยอดยกมา'], $control), array_merge(['account_id' => $v['offset_account_id'], 'subledger_type' => null, 'subledger_id' => null, 'description' => 'บัญชีคู่ยอดยกมา'], $offset)]], Warehouse::query()->findOrFail($v['warehouse_id']), $user);
                $line = $entry->lines()->where('account_id', $v['account_id'])->firstOrFail();
                $openItems->recordFromJournalLine($line, ['document_type' => 'OPENING', 'document_number' => $v['document_number'], 'due_date' => $v['due_date'] ?: null]);
            }
            $batch->update(['status' => 'COMMITTED', 'committed_by' => $user->id, 'committed_at' => now()]);
            return count($batch->staged_rows);
        });
    }
}
