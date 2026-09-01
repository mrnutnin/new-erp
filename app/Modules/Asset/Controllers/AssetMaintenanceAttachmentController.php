<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\AssetAttachment;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use App\Modules\Asset\Requests\StoreAssetAttachmentRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssetMaintenanceAttachmentController extends Controller
{
    public function index(Request $request, AssetMaintenanceRequest $maintenance): JsonResponse
    {
        $maintenance = $this->scoped($request, $maintenance);

        return response()->json(['data' => $maintenance->attachments()->with('uploadedBy:id,name')->latest('id')->get()->map(fn (AssetAttachment $attachment) => $this->row($request, $maintenance, $attachment))->values()]);
    }

    public function store(StoreAssetAttachmentRequest $request, AssetMaintenanceRequest $maintenance, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse
    {
        $maintenance = $this->scoped($request, $maintenance);
        $stored = $storage->store($request->file('file'), 'asset/maintenance', (string) ($settings->value('tax_id') ?: 'company'));
        try {
            $attachment = DB::transaction(function () use ($request, $maintenance, $stored, $audit): AssetAttachment {
                $attachment = AssetAttachment::query()->create([...$stored, 'branch_id' => $maintenance->branch_id, 'subject_type' => 'ASSET_MAINTENANCE', 'subject_id' => $maintenance->id, 'file_type' => $request->validated('file_type'), 'uploaded_by' => $request->user()->id]);
                $audit->record('asset.maintenance.attachment.uploaded', $attachment, [], $attachment->only(['branch_id', 'subject_type', 'subject_id', 'file_type', 'original_name', 'mime_type', 'bytes']), $request->user(), $request);

                return $attachment;
            });
        } catch (Throwable $exception) {
            $storage->delete($stored['disk'], $stored['path']);
            throw $exception;
        }

        return response()->json(['status' => true, 'msg' => 'อัปโหลดหลักฐานแล้ว', 'attachment' => $this->row($request, $maintenance, $attachment)]);
    }

    public function download(Request $request, AssetMaintenanceRequest $maintenance, AssetAttachment $attachment, FileStorageService $storage)
    {
        $this->attachment($this->scoped($request, $maintenance), $attachment);

        return $storage->download($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    public function preview(Request $request, AssetMaintenanceRequest $maintenance, AssetAttachment $attachment, FileStorageService $storage)
    {
        $this->attachment($this->scoped($request, $maintenance), $attachment);
        abort_unless($attachment->file_type === 'PHOTO' && str_starts_with($attachment->mime_type, 'image/'), 404);

        return $storage->inline($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    public function destroy(Request $request, AssetMaintenanceRequest $maintenance, AssetAttachment $attachment, FileStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $maintenance = $this->scoped($request, $maintenance);
        $attachment = $this->attachment($maintenance, $attachment);
        DB::transaction(function () use ($request, $maintenance, $attachment, $storage, $audit): void {
            $attachment = AssetAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $this->attachment($maintenance, $attachment);
            $before = $attachment->toArray();
            $storage->delete($attachment->disk, $attachment->path);
            $attachment->delete();
            $audit->record('asset.maintenance.attachment.deleted', $attachment, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบหลักฐานแล้ว']);
    }

    private function row(Request $request, AssetMaintenanceRequest $maintenance, AssetAttachment $attachment): array
    {
        return ['id' => $attachment->id, 'file_type' => $attachment->file_type, 'original_name' => $attachment->original_name, 'uploaded_by' => $attachment->uploadedBy?->name ?? '-', 'uploaded_at' => $attachment->created_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i'), 'download_url' => route('asset.maintenance.attachments.download', [$maintenance, $attachment]), 'preview_url' => $attachment->file_type === 'PHOTO' && str_starts_with($attachment->mime_type, 'image/') ? route('asset.maintenance.attachments.preview', [$maintenance, $attachment]) : null, 'delete_url' => $request->user()->hasPermission('asset.attachments.manage') ? route('asset.maintenance.attachments.destroy', [$maintenance, $attachment]) : null];
    }

    private function scoped(Request $request, AssetMaintenanceRequest $maintenance): AssetMaintenanceRequest
    {
        return AssetMaintenanceRequest::query()->where('branch_id', $request->attributes->get('selectedBranch')->id)->findOrFail($maintenance->id);
    }

    private function attachment(AssetMaintenanceRequest $maintenance, AssetAttachment $attachment): AssetAttachment
    {
        abort_unless($attachment->subject_type === 'ASSET_MAINTENANCE' && (int) $attachment->subject_id === $maintenance->id && (int) $attachment->branch_id === $maintenance->branch_id, 404);

        return $attachment;
    }
}
