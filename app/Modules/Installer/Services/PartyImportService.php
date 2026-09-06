<?php

namespace App\Modules\Installer\Services;

use App\Models\Party;
use App\Models\PartyRole;
use App\Models\User;
use App\Modules\Platform\Models\MigrationImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartyImportService
{
    private const HEADERS = ['code', 'name', 'type', 'tax_id', 'branch_code', 'contact_name', 'phone', 'email', 'address'];
    private const MAX_ROWS = 2000;

    public function stage(UploadedFile $file, string $role, User $user): MigrationImportBatch
    {
        $role = strtoupper($role);
        if (! in_array($role, ['CUSTOMER', 'SUPPLIER'], true)) {
            throw ValidationException::withMessages(['party_type' => 'ประเภทข้อมูลไม่ถูกต้อง']);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $type = 'INSTALLER_PARTY_'.$role;
        if ($existing = MigrationImportBatch::query()->where('type', $type)->where('checksum', $checksum)->first()) return $existing;

        $handle = fopen($file->getRealPath(), 'rb');
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        if ($headers !== self::HEADERS) throw ValidationException::withMessages(['file' => 'หัวตาราง CSV ไม่ถูกต้อง: '.implode(', ', self::HEADERS)]);

        $seen = [];
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            if (count($rows) >= self::MAX_ROWS) throw ValidationException::withMessages(['file' => 'รองรับสูงสุด '.self::MAX_ROWS.' รายการต่อไฟล์']);
            $source = array_combine(self::HEADERS, array_pad($values, count(self::HEADERS), null));
            $normalized = $this->normalize($source);
            $errors = $this->validate($normalized, $seen);
            $rows[] = ['row_number' => count($rows) + 2, 'source' => $source, 'normalized' => $normalized, 'errors' => $errors];
            if ($normalized['code'] !== '') $seen[$normalized['code']] = true;
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'กรุณากรอกข้อมูลอย่างน้อย 1 รายการ']);

        $errorRows = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));
        return MigrationImportBatch::query()->create([
            'type' => $type,
            'template_version' => 'PARTY-CSV-1.0',
            'source_system' => 'installer',
            'original_filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'status' => $errorRows === 0 ? 'VALIDATED' : 'INVALID',
            'total_rows' => count($rows),
            'valid_rows' => count($rows) - $errorRows,
            'error_rows' => $errorRows,
            'staged_rows' => $rows,
            'created_by' => $user->id,
        ]);
    }

    public function commit(MigrationImportBatch $batch, User $user): int
    {
        return DB::transaction(function () use ($batch, $user): int {
            $batch = MigrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! str_starts_with($batch->type, 'INSTALLER_PARTY_') || $batch->status !== 'VALIDATED' || $batch->error_rows > 0) throw ValidationException::withMessages(['batch' => 'ไฟล์นี้ยังไม่ผ่านการตรวจสอบ']);
            if ((int) $batch->created_by !== (int) $user->id) throw ValidationException::withMessages(['batch' => 'ผู้สร้างไฟล์เท่านั้นที่ยืนยันได้']);
            $role = str_ends_with($batch->type, '_CUSTOMER') ? 'CUSTOMER' : 'SUPPLIER';
            foreach ($batch->staged_rows as $row) {
                $values = $row['normalized'];
                $party = Party::query()->withTrashed()->firstOrNew(['code' => $values['code']]);
                $party->fill([...$values, 'is_active' => true, 'created_by' => $party->created_by ?: $user->id, 'updated_by' => $user->id]);
                $party->deleted_at = null;
                $party->save();
                PartyRole::query()->updateOrCreate(['party_id' => $party->id, 'role' => $role], ['is_active' => true, 'credit_limit' => 0]);
            }
            $batch->update(['status' => 'COMMITTED', 'committed_by' => $user->id, 'committed_at' => now()]);
            return count($batch->staged_rows);
        });
    }

    private function normalize(array $source): array
    {
        return collect(self::HEADERS)->mapWithKeys(fn (string $key) => [$key => trim((string) ($source[$key] ?? ''))])->all();
    }

    /** @param array<string, bool> $seen */
    private function validate(array $row, array $seen): array
    {
        $errors = [];
        if ($row['code'] === '' || isset($seen[$row['code']])) $errors[] = 'รหัสต้องมีค่าและไม่ซ้ำในไฟล์';
        if ($row['name'] === '') $errors[] = 'ชื่อต้องมีค่า';
        if (! in_array($row['type'], ['COMPANY', 'INDIVIDUAL'], true)) $errors[] = 'ประเภทต้องเป็น COMPANY หรือ INDIVIDUAL';
        if ($row['tax_id'] !== '' && ! preg_match('/^\d{13}$/', $row['tax_id'])) $errors[] = 'เลขประจำตัวผู้เสียภาษีต้องเป็นตัวเลข 13 หลัก';
        if ($row['branch_code'] !== '' && ! preg_match('/^\d{5}$/', $row['branch_code'])) $errors[] = 'รหัสสาขาต้องเป็นตัวเลข 5 หลัก';
        if ($row['email'] !== '' && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'อีเมลไม่ถูกต้อง';
        return array_values(array_unique($errors));
    }
}
