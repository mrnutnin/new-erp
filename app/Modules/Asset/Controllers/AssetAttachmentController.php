<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetAttachment;
use App\Modules\Asset\Requests\StoreAssetAttachmentRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssetAttachmentController extends Controller
{
    public function index(Request $request, Asset $asset): JsonResponse
    {
        $asset = $this->assetForBranch($request, $asset);

        return response()->json(['data' => $asset->attachments()->with('uploadedBy:id,name')->latest('id')->get()->map(fn (AssetAttachment $attachment) => [
            'id' => $attachment->id,
            'file_type' => $attachment->file_type,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'bytes' => $attachment->bytes,
            'uploaded_by' => $attachment->uploadedBy?->name ?? '-',
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'download_url' => route('asset.assets.attachments.download', [$asset, $attachment]),
            'preview_url' => $attachment->file_type === 'PHOTO' && str_starts_with($attachment->mime_type, 'image/') ? route('asset.assets.attachments.preview', [$asset, $attachment]) : null,
            'delete_url' => $request->user()->hasPermission('asset.attachments.manage') ? route('asset.assets.attachments.destroy', [$asset, $attachment]) : null,
        ])->values()]);
    }

    public function store(StoreAssetAttachmentRequest $request, Asset $asset, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse
    {
        $asset = $this->assetForBranch($request, $asset);
        $stored = $storage->store($request->file('file'), 'asset', (string) ($settings->value('tax_id') ?: 'company'));

        try {
            $attachment = DB::transaction(function () use ($request, $asset, $stored, $audit) {
                $attachment = AssetAttachment::query()->create([
                    ...$stored,
                    'branch_id' => $asset->branch_id,
                    'subject_type' => 'ASSET',
                    'subject_id' => $asset->id,
                    'file_type' => $request->validated('file_type'),
                    'uploaded_by' => $request->user()->id,
                ]);
                $audit->record('asset.attachment.uploaded', $attachment, [], $this->snapshot($attachment), $request->user(), $request);

                return $attachment;
            });
        } catch (Throwable $exception) {
            try {
                $storage->delete($stored['disk'], $stored['path']);
            } catch (Throwable) {
                report($exception);
            }

            throw $exception;
        }

        return response()->json(['status' => true, 'msg' => 'อัปโหลดเอกสารแนบแล้ว', 'attachment' => [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'download_url' => route('asset.assets.attachments.download', [$asset, $attachment]),
        ]]);
    }

    public function download(Request $request, Asset $asset, AssetAttachment $attachment, FileStorageService $storage)
    {
        $asset = $this->assetForBranch($request, $asset);
        $attachment = $this->attachmentForAsset($asset, $attachment);

        return $storage->download($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    public function preview(Request $request, Asset $asset, AssetAttachment $attachment, FileStorageService $storage)
    {
        $asset = $this->assetForBranch($request, $asset);
        $attachment = $this->attachmentForAsset($asset, $attachment);
        abort_unless($attachment->file_type === 'PHOTO' && str_starts_with($attachment->mime_type, 'image/'), 404);

        return $storage->inline($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    public function destroy(Request $request, Asset $asset, AssetAttachment $attachment, FileStorageService $storage, AuditLogger $audit): JsonResponse
    {
        $asset = $this->assetForBranch($request, $asset);
        $attachment = $this->attachmentForAsset($asset, $attachment);

        DB::transaction(function () use ($request, $asset, $attachment, $storage, $audit) {
            $attachment = AssetAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $this->attachmentForAsset($asset, $attachment);
            $before = $this->snapshot($attachment);
            $storage->delete($attachment->disk, $attachment->path);
            $attachment->delete();
            $audit->record('asset.attachment.deleted', $attachment, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบเอกสารแนบแล้ว']);
    }

    private function assetForBranch(Request $request, Asset $asset): Asset
    {
        return Asset::query()->where('branch_id', $request->attributes->get('selectedBranch')->id)->findOrFail($asset->id);
    }

    private function attachmentForAsset(Asset $asset, AssetAttachment $attachment): AssetAttachment
    {
        abort_unless($attachment->subject_type === 'ASSET' && (int) $attachment->subject_id === (int) $asset->id && (int) $attachment->branch_id === (int) $asset->branch_id, 404);

        return $attachment;
    }

    private function snapshot(AssetAttachment $attachment): array
    {
        return $attachment->only(['branch_id', 'subject_type', 'subject_id', 'file_type', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by']);
    }
}
