<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Accounting\Rules\AccountStructure;
use App\Modules\Accounting\Support\ChartOfAccountsTemplate;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\SpreadsheetService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountImportService
{
    private const MAX_ROWS = 2000;

    private const CLASSES = ['SUMMARY', 'SUBACCOUNT', 'CONTROL'];

    private const CONTROL_TYPES = ['AR', 'AP', 'INVENTORY', 'CASH', 'BANK', 'CREDIT_CARD', 'CHEQUE', 'FIXED_ASSET', 'INPUT_VAT', 'OUTPUT_VAT', 'WITHHOLDING_TAX', 'WIP'];

    public function __construct(private readonly SpreadsheetService $spreadsheets) {}

    public function stage(UploadedFile $file, string $sourceSystem, User $user): MigrationImportBatch
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $existing = MigrationImportBatch::query()->where('type', ChartOfAccountsTemplate::TYPE)->where('checksum', $checksum)->first();

        if ($existing) {
            return $existing;
        }

        $workbook = $this->spreadsheets->readXlsx($file, ['Accounts', '_meta']);
        $accountsSheet = $workbook['Accounts'] ?? [];
        $metaSheet = $workbook['_meta'] ?? [];

        $this->validateWorkbook($accountsSheet, $metaSheet);

        $dataRows = array_values(array_filter(array_slice($accountsSheet, 1), fn (array $row) => collect($row)->contains(fn ($value) => $value !== null && trim((string) $value) !== '')));

        if ($dataRows === []) {
            throw ValidationException::withMessages(['file' => 'กรุณากรอกข้อมูลใน sheet Accounts อย่างน้อย 1 แถว']);
        }

        if (count($dataRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'ผังบัญชีหนึ่งไฟล์รองรับสูงสุด '.self::MAX_ROWS.' แถว']);
        }

        $types = AccountType::query()->get()->keyBy('code');
        $existingCodes = Account::withTrashed()->pluck('code')->mapWithKeys(fn ($code) => [strtoupper($code) => true]);
        $knownParents = Account::query()->with('type:id,code')->get([
            'id', 'code', 'level', 'account_type_id', 'is_active', 'is_postable', 'control_account_type',
        ])->mapWithKeys(fn (Account $account) => [strtoupper($account->code) => [
            'level' => $account->level,
            'account_type' => $account->type->code,
            'is_active' => $account->is_active,
            'account_class' => $account->control_account_type ? 'CONTROL' : ($account->is_postable ? 'SUBACCOUNT' : 'SUMMARY'),
        ]]);
        $seenKeys = [];
        $seenCodes = [];
        $stagedRows = [];

        foreach ($dataRows as $offset => $row) {
            $source = array_combine(ChartOfAccountsTemplate::HEADERS, array_pad(array_slice($row, 0, count(ChartOfAccountsTemplate::HEADERS)), count(ChartOfAccountsTemplate::HEADERS), null));
            $normalized = $this->normalize($source);
            $errors = $this->validateRow($normalized, $types->keys()->all(), $existingCodes, $knownParents, $seenKeys, $seenCodes);
            $normalized['level'] = $this->levelFor($normalized['parent_code'], $knownParents);

            $stagedRows[] = [
                'row_number' => $offset + 2,
                'source' => $source,
                'normalized' => $normalized,
                'errors' => $errors,
            ];

            $seenKeys[$normalized['row_key']] = true;
            $seenCodes[$normalized['code']] = true;

            if ($errors === []) {
                $knownParents[$normalized['code']] = [
                    'level' => $normalized['level'],
                    'account_type' => $normalized['account_type'],
                    'is_active' => $normalized['is_active'],
                    'account_class' => $normalized['account_class'],
                ];
            }
        }

        $errorRows = count(array_filter($stagedRows, fn (array $row) => $row['errors'] !== []));

        // ponytail: COA staging stays in one JSON payload up to 2,000 rows; split to row tables when high-volume domain imports arrive.
        return MigrationImportBatch::query()->create([
            'type' => ChartOfAccountsTemplate::TYPE,
            'template_version' => ChartOfAccountsTemplate::VERSION,
            'source_system' => $sourceSystem,
            'original_filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'status' => $errorRows === 0 ? 'VALIDATED' : 'INVALID',
            'total_rows' => count($stagedRows),
            'valid_rows' => count($stagedRows) - $errorRows,
            'error_rows' => $errorRows,
            'staged_rows' => $stagedRows,
            'created_by' => $user->id,
        ]);
    }

    public function commit(MigrationImportBatch $batch, User $user, Request $request, AuditLogger $audit, AccountWriter $writer): void
    {
        DB::transaction(function () use ($batch, $user, $request, $audit, $writer) {
            $batch = MigrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->type !== ChartOfAccountsTemplate::TYPE || $batch->status !== 'VALIDATED' || $batch->error_rows > 0) {
                throw ValidationException::withMessages(['batch' => 'Batch นี้ยังไม่พร้อมนำเข้า']);
            }

            $rows = $batch->staged_rows;

            foreach ($rows as &$row) {
                $values = $row['normalized'];
                $type = AccountType::query()->where('code', $values['account_type'])->firstOrFail();
                $parent = $values['parent_code'] ? Account::query()->where('code', $values['parent_code'])->firstOrFail() : null;
                $account = $writer->create([
                    ...$values,
                    'account_type_id' => $type->id,
                    'parent_id' => $parent?->id,
                ], $user, $request, $audit);
                $row['target_id'] = $account->id;
            }
            unset($row);

            $before = $batch->only(['status', 'committed_by', 'committed_at']);
            $batch->update([
                'status' => 'COMMITTED',
                'staged_rows' => $rows,
                'committed_by' => $user->id,
                'committed_at' => now(),
            ]);
            $audit->record('migration.chart_of_accounts.committed', $batch, $before, [
                'status' => 'COMMITTED',
                'row_count' => $batch->total_rows,
            ], $user, $request);
        });
    }

    private function validateWorkbook(array $accounts, array $meta): void
    {
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), $accounts[0] ?? []);
        $metadata = collect(array_slice($meta, 1))->mapWithKeys(fn (array $row) => [(string) ($row[0] ?? '') => (string) ($row[1] ?? '')]);

        if ($headers !== ChartOfAccountsTemplate::HEADERS) {
            throw ValidationException::withMessages(['file' => 'หัวตาราง Accounts ไม่ตรงกับ template กรุณาดาวน์โหลดไฟล์ใหม่']);
        }

        if ($metadata->get('template_type') !== ChartOfAccountsTemplate::TYPE || $metadata->get('template_version') !== ChartOfAccountsTemplate::VERSION) {
            throw ValidationException::withMessages(['file' => 'ชนิดหรือรุ่นของ template ไม่ถูกต้อง']);
        }
    }

    private function normalize(array $row): array
    {
        $text = fn (string $key) => strtoupper(trim((string) ($row[$key] ?? '')));

        return [
            'row_key' => trim((string) ($row['row_key'] ?? '')),
            'code' => $text('code'),
            'name' => trim((string) ($row['name'] ?? '')),
            'account_type' => $text('account_type'),
            'parent_code' => $text('parent_code') ?: null,
            'account_class' => $text('account_class'),
            'control_account_type' => $text('control_type') ?: null,
            'reporting_profile' => $text('reporting_profile') ?: null,
            'is_active' => $this->booleanValue($row['is_active'] ?? null),
        ];
    }

    private function validateRow(array $row, array $typeCodes, $existingCodes, $knownParents, array $seenKeys, array $seenCodes): array
    {
        $errors = [];

        foreach ($row as $value) {
            if (is_string($value) && str_starts_with(ltrim($value), '=')) {
                $errors[] = 'ไม่อนุญาตให้ใช้สูตร Excel';
                break;
            }
        }

        if ($row['row_key'] === '' || isset($seenKeys[$row['row_key']])) {
            $errors[] = 'row_key ต้องมีค่าและไม่ซ้ำ';
        }
        if ($row['code'] === '' || mb_strlen($row['code']) > 50 || isset($seenCodes[$row['code']]) || $existingCodes->has($row['code'])) {
            $errors[] = 'รหัสบัญชีต้องมีค่า ไม่ซ้ำ และยาวไม่เกิน 50 ตัวอักษร';
        }
        if ($row['name'] === '' || mb_strlen($row['name']) > 255) {
            $errors[] = 'ชื่อบัญชีต้องมีค่าและยาวไม่เกิน 255 ตัวอักษร';
        }
        if (! in_array($row['account_type'], $typeCodes, true)) {
            $errors[] = 'หมวดบัญชีไม่ถูกต้อง';
        }
        if (! in_array($row['account_class'], self::CLASSES, true)) {
            $errors[] = 'ประเภทบัญชีไม่ถูกต้อง';
        }
        if ($row['account_class'] === 'CONTROL' && ! in_array($row['control_account_type'], self::CONTROL_TYPES, true)) {
            $errors[] = 'บัญชีคุมต้องระบุ control_type ที่ถูกต้อง';
        }
        if ($row['account_class'] !== 'CONTROL' && $row['control_account_type'] !== null) {
            $errors[] = 'control_type ใช้ได้เฉพาะบัญชีคุม';
        }
        if ($row['reporting_profile'] !== null && ! in_array($row['reporting_profile'], ['PAE', 'NPAE'], true)) {
            $errors[] = 'reporting_profile ต้องเป็น PAE, NPAE หรือเว้นว่าง';
        }
        if ($row['is_active'] === null) {
            $errors[] = 'is_active ต้องเป็น TRUE หรือ FALSE';
        }

        if ($row['parent_code']) {
            $parent = $knownParents[$row['parent_code']] ?? null;
            if (! $parent) {
                $errors[] = 'ไม่พบบัญชีแม่ หรือบัญชีแม่ไม่ได้อยู่ก่อนแถวนี้';
            } elseif (! $parent['is_active'] || $parent['account_class'] !== 'SUMMARY' || $parent['account_type'] !== $row['account_type']) {
                $errors[] = 'บัญชีแม่ต้อง active เป็น SUMMARY และอยู่หมวดเดียวกัน';
            }
        }

        if (! AccountStructure::levelIsValid($this->levelFor($row['parent_code'], $knownParents))) {
            $errors[] = 'โครงสร้างบัญชีเกินระดับ 5';
        }

        return array_values(array_unique($errors));
    }

    private function levelFor(?string $parentCode, $knownParents): int
    {
        return $parentCode ? (($knownParents[$parentCode]['level'] ?? AccountStructure::MAX_LEVEL) + 1) : 1;
    }

    private function booleanValue(mixed $value): ?bool
    {
        $value = strtoupper(trim((string) $value));

        return match ($value) {
            'TRUE', '1', 'YES', 'Y' => true,
            'FALSE', '0', 'NO', 'N' => false,
            default => null,
        };
    }
}
