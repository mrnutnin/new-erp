<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\DocumentPhoto;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Requests\StoreDocumentPhotosRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class DocumentPhotoController extends Controller
{
    private const MAX_PHOTOS = 5;

    public function store(StoreDocumentPhotosRequest $request, string $document, FileStorageService $storage, GlobalSettings $settings, AuditLogger $audit): JsonResponse
    {
        [$type, $model, $uploadPermission] = $this->document($request, $document);
        abort_unless($request->user()->hasPermission($uploadPermission), 403);
        $this->scope($request, $type, $model);

        $files = $request->file('photos', []);
        if ($model->photos()->count() + count($files) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'แนบรูปได้สูงสุด '.self::MAX_PHOTOS.' รูปต่อเอกสาร']);
        }

        $storedFiles = [];
        try {
            foreach ($files as $file) {
                $storedFiles[] = $storage->store($file, 'wms/document-evidence', (string) ($settings->value('tax_id') ?: 'company'));
            }

            DB::transaction(function () use ($request, $type, $model, $storedFiles, $audit): void {
                $model::query()->lockForUpdate()->findOrFail($model->getKey());
                if (DocumentPhoto::query()->where('document_type', $type)->where('document_id', $model->getKey())->count() + count($storedFiles) > self::MAX_PHOTOS) {
                    throw ValidationException::withMessages(['photos' => 'แนบรูปได้สูงสุด '.self::MAX_PHOTOS.' รูปต่อเอกสาร']);
                }

                foreach ($storedFiles as $stored) {
                    $photo = DocumentPhoto::query()->create([
                        ...$stored,
                        'document_type' => $type,
                        'document_id' => $model->getKey(),
                        'uploaded_by' => $request->user()->id,
                    ]);
                    $audit->record('wms.document.photo.uploaded', $photo, [], $photo->only([
                        'document_type', 'document_id', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by',
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

        return response()->json(['status' => true, 'msg' => 'แนบรูปหลักฐานแล้ว']);
    }

    public function preview(Request $request, string $document, DocumentPhoto $photo, FileStorageService $storage): StreamedResponse
    {
        [$type, $model, $uploadPermission, $viewPermission] = $this->document($request, $document);
        abort_unless($request->user()->hasPermission($viewPermission), 403);
        $this->scope($request, $type, $model);
        abort_unless($photo->document_type === $type && (int) $photo->document_id === (int) $model->getKey() && str_starts_with($photo->mime_type, 'image/'), 404);

        return $storage->inline($photo->disk, $photo->path, $photo->original_name, $photo->mime_type);
    }

    /** @return array{string, Model, string, string} */
    private function document(Request $request, string $id): array
    {
        $type = (string) $request->route('photo_document_type');
        [$class, $uploadPermission, $viewPermission] = match ($type) {
            'ISSUE', 'MATERIAL_ISSUE' => [IssueDocument::class, 'wms.issues.create', 'wms.issues.view'],
            'ISSUE_RETURN' => [IssueReturn::class, 'wms.issue-returns.create', 'wms.issue-returns.view'],
            'FINISHED_RECEIPT' => [InventoryAdjustmentDocument::class, 'wms.inventory-adjustments.create', 'wms.inventory-adjustments.view'],
            default => abort(404),
        };

        return [$type, $class::query()->findOrFail($id), $uploadPermission, $viewPermission];
    }

    private function scope(Request $request, string $type, Model $document): void
    {
        abort_unless(
            (int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id
            && (int) $document->branch_id === (int) $request->attributes->get('selectedBranch')->id,
            404,
        );
        abort_unless(match ($type) {
            'ISSUE' => $document->issue_type !== 'PRODUCTION',
            'MATERIAL_ISSUE' => $document->issue_type === 'PRODUCTION',
            'ISSUE_RETURN' => $document->issue()->where('issue_type', '<>', 'PRODUCTION')->exists(),
            'FINISHED_RECEIPT' => $document->document_context === 'PRODUCTION_RECEIPT',
            default => false,
        }, 404);
    }
}
