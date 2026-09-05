<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Finance\Models\PettyCashAttachment;
use App\Modules\Finance\Models\PettyCashClearing;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Requests\StorePettyCashAttachmentRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PettyCashAttachmentController extends Controller
{
    public function voucherIndex(Request $request, PettyCashVoucher $voucher): JsonResponse { return $this->index($request, $this->subject($request, $voucher, 'PETTY_CASH_VOUCHER')); }
    public function clearingIndex(Request $request, PettyCashClearing $clearing): JsonResponse { return $this->index($request, $this->subject($request, $clearing, 'PETTY_CASH_CLEARING')); }
    public function employeeAdvanceClearingIndex(Request $request, EmployeeAdvanceClearing $clearing): JsonResponse { return $this->index($request, $this->subject($request, $clearing, 'EMPLOYEE_ADVANCE_CLEARING')); }
    public function voucherStore(StorePettyCashAttachmentRequest $request, PettyCashVoucher $voucher, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse { return $this->store($request, $this->subject($request, $voucher, 'PETTY_CASH_VOUCHER'), 'PETTY_CASH_VOUCHER', 'voucher', $storage, $settings, $audit); }
    public function clearingStore(StorePettyCashAttachmentRequest $request, PettyCashClearing $clearing, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse { return $this->store($request, $this->subject($request, $clearing, 'PETTY_CASH_CLEARING'), 'PETTY_CASH_CLEARING', 'clearing', $storage, $settings, $audit); }
    public function employeeAdvanceClearingStore(StorePettyCashAttachmentRequest $request, EmployeeAdvanceClearing $clearing, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse { $subject = $this->subject($request, $clearing, 'EMPLOYEE_ADVANCE_CLEARING'); abort_unless($subject->status === 'DRAFT', 422, 'แนบเอกสารได้เฉพาะใบเคลียร์ฉบับร่าง'); return $this->store($request, $subject, 'EMPLOYEE_ADVANCE_CLEARING', 'employee-advance-clearing', $storage, $settings, $audit); }
    public function voucherDownload(Request $request, PettyCashVoucher $voucher, PettyCashAttachment $attachment, FileStorageService $storage) { return $this->download($request, $this->subject($request, $voucher, 'PETTY_CASH_VOUCHER'), $attachment, $storage); }
    public function clearingDownload(Request $request, PettyCashClearing $clearing, PettyCashAttachment $attachment, FileStorageService $storage) { return $this->download($request, $this->subject($request, $clearing, 'PETTY_CASH_CLEARING'), $attachment, $storage); }
    public function employeeAdvanceClearingDownload(Request $request, EmployeeAdvanceClearing $clearing, PettyCashAttachment $attachment, FileStorageService $storage) { return $this->download($request, $this->subject($request, $clearing, 'EMPLOYEE_ADVANCE_CLEARING'), $attachment, $storage); }
    public function voucherPreview(Request $request, PettyCashVoucher $voucher, PettyCashAttachment $attachment, FileStorageService $storage) { return $this->preview($request, $this->subject($request, $voucher, 'PETTY_CASH_VOUCHER'), $attachment, $storage); }
    public function clearingPreview(Request $request, PettyCashClearing $clearing, PettyCashAttachment $attachment, FileStorageService $storage) { return $this->preview($request, $this->subject($request, $clearing, 'PETTY_CASH_CLEARING'), $attachment, $storage); }
    public function employeeAdvanceClearingPreview(Request $request, EmployeeAdvanceClearing $clearing, PettyCashAttachment $attachment, FileStorageService $storage) { return $this->preview($request, $this->subject($request, $clearing, 'EMPLOYEE_ADVANCE_CLEARING'), $attachment, $storage); }
    public function voucherDestroy(Request $request, PettyCashVoucher $voucher, PettyCashAttachment $attachment, FileStorageService $storage, AuditLogger $audit): JsonResponse { return $this->destroy($request, $this->subject($request, $voucher, 'PETTY_CASH_VOUCHER'), $attachment, $storage, $audit); }
    public function clearingDestroy(Request $request, PettyCashClearing $clearing, PettyCashAttachment $attachment, FileStorageService $storage, AuditLogger $audit): JsonResponse { return $this->destroy($request, $this->subject($request, $clearing, 'PETTY_CASH_CLEARING'), $attachment, $storage, $audit); }
    public function employeeAdvanceClearingDestroy(Request $request, EmployeeAdvanceClearing $clearing, PettyCashAttachment $attachment, FileStorageService $storage, AuditLogger $audit): JsonResponse { $subject = $this->subject($request, $clearing, 'EMPLOYEE_ADVANCE_CLEARING'); abort_unless($subject->status === 'DRAFT', 422, 'ลบเอกสารได้เฉพาะใบเคลียร์ฉบับร่าง'); return $this->destroy($request, $subject, $attachment, $storage, $audit); }

    private function index(Request $request, Model $subject): JsonResponse
    {
        return response()->json(['data' => PettyCashAttachment::query()->where('warehouse_id', $subject->warehouse_id)->where('subject_type', $this->type($subject))->where('subject_id', $subject->id)->with('uploadedBy:id,name')->latest('id')->get()->map(fn (PettyCashAttachment $a) => $this->row($request, $subject, $a))->values()]);
    }

    private function store(StorePettyCashAttachmentRequest $request, Model $subject, string $type, string $routeKey, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse
    {
        $stored = $storage->store($request->file('file'), 'finance-petty-cash', (string) ($settings->value('tax_id') ?: 'company'));
        try {
            $attachment = DB::transaction(function () use ($request, $subject, $type, $stored, $audit): PettyCashAttachment {
                $attachment = PettyCashAttachment::query()->create([...$stored, 'warehouse_id' => $subject->warehouse_id, 'subject_type' => $type, 'subject_id' => $subject->id, 'file_type' => $request->validated('file_type'), 'uploaded_by' => $request->user()->id]);
                $audit->record('finance.petty_cash.attachment.uploaded', $attachment, [], $attachment->only(['warehouse_id', 'subject_type', 'subject_id', 'file_type', 'original_name', 'mime_type', 'bytes']), $request->user(), $request);
                return $attachment;
            });
        } catch (Throwable $exception) {
            try { $storage->delete($stored['disk'], $stored['path']); } catch (Throwable) { report($exception); }
            throw $exception;
        }

        return response()->json(['status' => true, 'msg' => 'อัปโหลดเอกสารแนบแล้ว', 'attachment' => $this->row($request, $subject, $attachment)]);
    }

    private function download(Request $request, Model $subject, PettyCashAttachment $attachment, FileStorageService $storage)
    {
        $attachment = $this->attachment($subject, $attachment);
        return $storage->download($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    private function preview(Request $request, Model $subject, PettyCashAttachment $attachment, FileStorageService $storage)
    {
        $attachment = $this->attachment($subject, $attachment);
        abort_unless($attachment->mime_type === 'application/pdf' || str_starts_with($attachment->mime_type, 'image/'), 404);
        return $storage->inline($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    private function destroy(Request $request, Model $subject, PettyCashAttachment $attachment, FileStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $attachment = $this->attachment($subject, $attachment);
        DB::transaction(function () use ($request, $attachment, $storage, $audit): void {
            $before = $attachment->toArray();
            $storage->delete($attachment->disk, $attachment->path);
            $attachment->delete();
            $audit->record('finance.petty_cash.attachment.deleted', $attachment, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });
        return response()->json(['status' => true, 'msg' => 'ลบเอกสารแนบแล้ว']);
    }

    private function subject(Request $request, Model $subject, string $type): Model
    {
        abort_unless((int) $subject->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        return $subject;
    }

    private function attachment(Model $subject, PettyCashAttachment $attachment): PettyCashAttachment
    {
        abort_unless((int) $attachment->warehouse_id === (int) $subject->warehouse_id && (int) $attachment->subject_id === (int) $subject->id && $attachment->subject_type === $this->type($subject), 404);
        return $attachment;
    }

    private function row(Request $request, Model $subject, PettyCashAttachment $attachment): array
    {
        $isVoucher = $subject instanceof PettyCashVoucher;
        $isEmployeeAdvanceClearing = $subject instanceof EmployeeAdvanceClearing;
        $prefix = $isVoucher ? 'finance.petty-cash' : ($isEmployeeAdvanceClearing ? 'finance.employee-advance-clearings' : 'finance.petty-cash-clearings');
        $managePermission = $isVoucher ? 'finance.petty-cash.update' : ($isEmployeeAdvanceClearing ? 'finance.employee-advance-clearings.update' : 'finance.petty-cash-clearings.update');
        return ['id' => $attachment->id, 'file_type' => $attachment->file_type, 'original_name' => $attachment->original_name, 'mime_type' => $attachment->mime_type, 'bytes' => $attachment->bytes, 'uploaded_by' => $attachment->uploadedBy?->name ?? '-', 'uploaded_at' => $attachment->created_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i'), 'download_url' => route($prefix.'.attachments.download', [$subject, $attachment]), 'preview_url' => route($prefix.'.attachments.preview', [$subject, $attachment]), 'delete_url' => $request->user()->hasPermission($managePermission) ? route($prefix.'.attachments.destroy', [$subject, $attachment]) : null];
    }

    private function type(Model $subject): string
    {
        return $subject instanceof PettyCashVoucher ? 'PETTY_CASH_VOUCHER' : ($subject instanceof EmployeeAdvanceClearing ? 'EMPLOYEE_ADVANCE_CLEARING' : 'PETTY_CASH_CLEARING');
    }
}
