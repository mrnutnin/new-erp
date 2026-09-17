<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Models\TransferPhoto;
use App\Modules\Wms\Requests\StoreTransferPhotosRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class TransferPhotoController extends Controller
{
    private const MAX_PER_STAGE = 5;

    public function store(StoreTransferPhotosRequest $request, Transfer $transfer, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse
    {
        $stage = (string) $request->validated('stage');
        $this->authorizeStage($request, $transfer, $stage);

        $files = $request->file('photos', []);
        if ($transfer->photos()->where('stage', $stage)->count() + count($files) > self::MAX_PER_STAGE) {
            throw ValidationException::withMessages(['photos' => 'แนบรูปได้สูงสุด '.self::MAX_PER_STAGE.' รูปต่อช่วงการโอน']);
        }

        $storedFiles = [];
        try {
            foreach ($files as $file) {
                $storedFiles[] = $storage->store($file, 'wms/transfer-evidence', (string) ($settings->value('tax_id') ?: 'company'));
            }

            DB::transaction(function () use ($request, $transfer, $stage, $storedFiles, $audit): void {
                Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
                if (TransferPhoto::query()->where('transfer_id', $transfer->id)->where('stage', $stage)->count() + count($storedFiles) > self::MAX_PER_STAGE) {
                    throw ValidationException::withMessages(['photos' => 'แนบรูปได้สูงสุด '.self::MAX_PER_STAGE.' รูปต่อช่วงการโอน']);
                }

                foreach ($storedFiles as $stored) {
                    $photo = TransferPhoto::query()->create([
                        ...$stored,
                        'transfer_id' => $transfer->id,
                        'stage' => $stage,
                        'uploaded_by' => $request->user()->id,
                    ]);
                    $audit->record('wms.transfer.photo.uploaded', $photo, [], $photo->only([
                        'transfer_id', 'stage', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by',
                    ]), $request->user(), $request);
                }
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $stored) {
                try {
                    $storage->delete($stored['disk'], $stored['path']);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        }

        return response()->json(['status' => true, 'msg' => $stage === 'OUTGOING' ? 'แนบรูปก่อนส่งออกแล้ว' : 'แนบรูปตอนรับเข้าแล้ว']);
    }

    public function preview(Request $request, Transfer $transfer, TransferPhoto $photo, FileStorageService $storage): StreamedResponse
    {
        $this->scope($request, $transfer);
        abort_unless((int) $photo->transfer_id === (int) $transfer->id && str_starts_with($photo->mime_type, 'image/'), 404);

        return $storage->inline($photo->disk, $photo->path, $photo->original_name, $photo->mime_type);
    }

    private function authorizeStage(Request $request, Transfer $transfer, string $stage): void
    {
        $warehouseId = $this->scope($request, $transfer);
        if ($stage === 'OUTGOING') {
            abort_unless((int) $transfer->source_warehouse_id === $warehouseId && $request->user()->hasPermission('wms.transfers.dispatch'), 403);
            abort_unless($transfer->status === 'DRAFT', 422, 'แนบรูปก่อนส่งออกได้เฉพาะก่อนกดส่งออกจากคลัง');
            return;
        }

        abort_unless((int) $transfer->destination_warehouse_id === $warehouseId && $request->user()->hasPermission('wms.transfers.complete'), 403);
        abort_unless(in_array($transfer->status, ['DISPATCHED', 'PARTIALLY_ACCEPTED'], true), 422, 'แนบรูปตอนรับเข้าได้เฉพาะรายการที่รอรับ');
    }

    private function scope(Request $request, Transfer $transfer): int
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $transfer->source_warehouse_id === $warehouseId || (int) $transfer->destination_warehouse_id === $warehouseId, 404);

        return $warehouseId;
    }
}
