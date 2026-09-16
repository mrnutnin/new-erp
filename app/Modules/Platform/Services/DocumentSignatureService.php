<?php

namespace App\Modules\Platform\Services;

use App\Models\AuditLog;
use App\Models\DocumentSignatureSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentSignatureService
{
    private const DOCUMENT_ACTION_PREFIXES = [
        'purchasing.purchase-requisition.',
        'purchasing.purchase_requisition.',
        'purchasing.purchase_order.',
        'purchasing.goods_receipt.',
        'purchasing.purchase_document.',
        'purchasing.landed_cost.',
        'pos.sales-intake.',
        'pos.sales-rfq.',
        'pos.sales-quotation.',
        'pos.sales-order.',
        'pos.physical-sale.',
        'pos.sales_document.',
        'pos.sales-return.',
        'pos.billing_note.',
        'pos.advance-deposit.',
        'finance.settlement.',
        'accounting.journal_entry.',
    ];

    /** @var array<string, string> */
    private const ACTION_ROLES = [
        'created' => 'prepared',
        'submit' => 'submitted',
        'submitted' => 'submitted',
        'approve' => 'approved',
        'approved' => 'approved',
        'confirm' => 'approved',
        'confirmed' => 'approved',
        'accept' => 'approved',
        'accepted' => 'approved',
        'issued' => 'issued',
        'send' => 'issued',
        'sent' => 'issued',
        'posted' => 'posted',
        'completed' => 'completed',
        'close' => 'approved',
        'closed' => 'approved',
    ];

    public function captureFromAudit(AuditLog $audit, Model $subject, ?User $actor): void
    {
        if (! $actor || ! $this->isDocumentAction($audit->action)) {
            return;
        }

        $role = $this->roleFor($audit->action);
        if (! $role) {
            return;
        }

        DocumentSignatureSnapshot::query()->firstOrCreate(
            ['audit_log_id' => $audit->id],
            [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'role' => $role,
                'action' => $audit->action,
                'user_id' => $actor->id,
                'signer_name' => $actor->name,
                'signer_position' => $actor->position,
                'signed_at' => $audit->created_at ?? now(),
                'signature_disk' => $actor->signature_disk,
                'signature_path' => $actor->signature_path,
                'signature_checksum' => $actor->signature_checksum,
                'signature_mime_type' => $actor->signature_mime_type,
            ],
        );
    }

    /** @param array<int, string> $roles
     * @return array<string, array<string, mixed>>
     */
    public function forDocument(Model $document, array $roles): array
    {
        if (! $document->exists || ! $document->getKey()) {
            return [];
        }

        $snapshots = DocumentSignatureSnapshot::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->whereIn('role', $roles)
            ->latest('id')
            ->get()
            ->unique('role');

        return $snapshots->mapWithKeys(fn (DocumentSignatureSnapshot $snapshot): array => [
            $snapshot->role => [
                'name' => $snapshot->signer_name,
                'position' => $snapshot->signer_position,
                'signed_at' => $snapshot->signed_at,
                'image' => $this->imageDataUri($snapshot),
            ],
        ])->all();
    }

    private function isDocumentAction(string $action): bool
    {
        foreach (self::DOCUMENT_ACTION_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function roleFor(string $action): ?string
    {
        if ($action === 'accounting.journal_entry.submitted') {
            return 'validated';
        }

        $parts = explode('.', str_replace('-', '_', $action));
        foreach (array_reverse($parts) as $part) {
            if (isset(self::ACTION_ROLES[$part])) {
                return self::ACTION_ROLES[$part];
            }
            foreach (self::ACTION_ROLES as $suffix => $role) {
                if (str_ends_with($part, '_'.$suffix)) {
                    return $role;
                }
            }
        }

        return null;
    }

    private function imageDataUri(DocumentSignatureSnapshot $snapshot): ?string
    {
        if (! $snapshot->signature_disk || ! $snapshot->signature_path) {
            return null;
        }

        try {
            $filesystem = Storage::disk($snapshot->signature_disk);
            if (! $filesystem->exists($snapshot->signature_path) || $filesystem->size($snapshot->signature_path) > 2 * 1024 * 1024) {
                return null;
            }

            $contents = $filesystem->get($snapshot->signature_path);
            $mime = $snapshot->signature_mime_type ?: $filesystem->mimeType($snapshot->signature_path);
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                return null;
            }

            if ($snapshot->signature_checksum && ! hash_equals($snapshot->signature_checksum, hash('sha256', $contents))) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
