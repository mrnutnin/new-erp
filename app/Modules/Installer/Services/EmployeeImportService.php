<?php

namespace App\Modules\Installer\Services;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Platform\Models\MigrationImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeImportService
{
    private const HEADERS = ['employee_code', 'name', 'username', 'email', 'password'];

    public function stage(UploadedFile $file, User $user): MigrationImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($existing = MigrationImportBatch::query()->where('type', 'INSTALLER_EMPLOYEES')->where('checksum', $checksum)->first()) return $existing;
        $handle = fopen($file->getRealPath(), 'rb');
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), fgetcsv($handle) ?: []);
        if ($headers !== self::HEADERS) throw ValidationException::withMessages(['file' => 'หัวตาราง CSV ไม่ถูกต้อง: '.implode(', ', self::HEADERS)]);
        $seen = [];
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            if (count($rows) >= 2000) throw ValidationException::withMessages(['file' => 'รองรับสูงสุด 2,000 รายการต่อไฟล์']);
            $source = array_combine(self::HEADERS, array_pad($values, count(self::HEADERS), null));
            $normalized = collect(self::HEADERS)->mapWithKeys(fn (string $key) => [$key => trim((string) ($source[$key] ?? ''))])->all();
            $errors = [];
            foreach (['employee_code', 'name', 'username', 'email', 'password'] as $required) if ($normalized[$required] === '') $errors[] = $required.' ต้องมีค่า';
            if ($normalized['password'] !== '' && strlen($normalized['password']) < 8) $errors[] = 'password ต้องมีอย่างน้อย 8 ตัวอักษร';
            if ($normalized['email'] !== '' && ! filter_var($normalized['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'email ไม่ถูกต้อง';
            if ($normalized['username'] !== '' && isset($seen[$normalized['username']])) $errors[] = 'username ซ้ำในไฟล์';
            $normalized['password_hash'] = $normalized['password'] !== '' ? Hash::make($normalized['password']) : null;
            $normalized['password'] = null;
            $source['password'] = '[REDACTED]';
            $rows[] = ['row_number' => count($rows) + 2, 'source' => $source, 'normalized' => $normalized, 'errors' => array_values(array_unique($errors))];
            if ($normalized['username'] !== '') $seen[$normalized['username']] = true;
        }
        fclose($handle);
        if ($rows === []) throw ValidationException::withMessages(['file' => 'กรุณากรอกข้อมูลอย่างน้อย 1 รายการ']);
        $errors = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));
        return MigrationImportBatch::query()->create(['type' => 'INSTALLER_EMPLOYEES', 'template_version' => 'EMPLOYEE-CSV-1.0', 'source_system' => 'installer', 'original_filename' => $file->getClientOriginalName(), 'checksum' => $checksum, 'status' => $errors === 0 ? 'VALIDATED' : 'INVALID', 'total_rows' => count($rows), 'valid_rows' => count($rows) - $errors, 'error_rows' => $errors, 'staged_rows' => $rows, 'created_by' => $user->id]);
    }

    public function commit(MigrationImportBatch $batch, User $user): int
    {
        return DB::transaction(function () use ($batch, $user): int {
            $batch = MigrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->type !== 'INSTALLER_EMPLOYEES' || $batch->status !== 'VALIDATED' || $batch->error_rows > 0) throw ValidationException::withMessages(['batch' => 'ไฟล์พนักงานยังไม่ผ่านการตรวจสอบ']);
            if ((int) $batch->created_by !== (int) $user->id) throw ValidationException::withMessages(['batch' => 'ผู้สร้างไฟล์เท่านั้นที่ยืนยันได้']);
            $branch = Branch::query()->where('code', '00000')->firstOrFail();
            $warehouse = Warehouse::query()->where('code', 'WH001')->firstOrFail();
            $viewer = Role::query()->where('code', 'viewer')->where('is_active', true)->first();
            foreach ($batch->staged_rows as $row) {
                $values = $row['normalized'];
                $employee = User::query()->withTrashed()->firstOrNew(['username' => $values['username']]);
                $employee->forceFill(['employee_code' => $values['employee_code'], 'name' => $values['name'], 'username' => $values['username'], 'email' => $values['email'], 'password' => $values['password_hash'], 'is_active' => true, 'primary_branch_id' => $branch->id, 'deleted_at' => null])->save();
                if ($viewer) $employee->roles()->syncWithoutDetaching([$viewer->id]);
                $employee->branches()->syncWithoutDetaching([$branch->id]);
                $employee->warehouses()->syncWithoutDetaching([$warehouse->id]);
            }
            $batch->update(['status' => 'COMMITTED', 'committed_by' => $user->id, 'committed_at' => now()]);
            return count($batch->staged_rows);
        });
    }
}
