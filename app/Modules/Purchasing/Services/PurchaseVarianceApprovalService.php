<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseVarianceApproval;
use App\Modules\Purchasing\Support\PurchaseThreeWayMatchGate;
use App\Modules\Purchasing\Support\PurchaseThreeWayMatchPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseVarianceApprovalService
{
    public function __construct(private readonly PurchaseThreeWayMatchGate $gate) {}

    public function approve(PurchaseDocument $document, User $actor, string $reason, AuditLogger $audit, Request $request): PurchaseVarianceApproval
    {
        return $this->decide($document, $actor, $reason, 'APPROVED', $audit, $request);
    }

    public function reject(PurchaseDocument $document, User $actor, string $reason, AuditLogger $audit, Request $request): PurchaseVarianceApproval
    {
        return $this->decide($document, $actor, $reason, 'REJECTED', $audit, $request);
    }

    /** Recovery after the source document is edited: supersede the prior decision. */
    public function recover(PurchaseDocument $document, User $actor, string $reason, AuditLogger $audit, Request $request): PurchaseVarianceApproval
    {
        $policy = $this->approvalPolicy();
        $match = $this->gate->previewWithPolicy($document, $policy);
        if ($match === null) {
            throw ValidationException::withMessages(['variance' => 'เอกสารนี้ไม่มีรายการสินค้าสำหรับตรวจ variance']);
        }

        return DB::transaction(function () use ($document, $actor, $reason, $audit, $request, $policy, $match): PurchaseVarianceApproval {
            $previous = PurchaseVarianceApproval::query()->where('purchase_document_id', $document->id)->latest('revision')->lockForUpdate()->first();
            $approval = $this->createDecision($document, $actor, $reason, 'RECOVERED', $policy, $match, $previous ? $previous->revision + 1 : 0);
            $audit->record('wms.purchase_document.variance_recovered', $document, $previous?->only(['status', 'revision', 'evidence_hash']) ?? [], $approval->only(['status', 'revision', 'reason', 'evidence_hash', 'recovery_hint']), $actor, $request);

            return $approval;
        });
    }

    private function decide(PurchaseDocument $document, User $actor, string $reason, string $status, AuditLogger $audit, Request $request): PurchaseVarianceApproval
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'การตัดสิน variance ต้องมีเหตุผล']);
        }
        $policy = $this->approvalPolicy();
        $match = $this->gate->previewWithPolicy($document, $policy);
        if ($match === null || ! array_intersect($match['blockers'] ?? [], ['receipt_exceeds_po_quantity', 'invoice_exceeds_received_quantity', 'invoice_price_variance', 'receipt_cost_variance'])) {
            throw ValidationException::withMessages(['variance' => 'ไม่พบ variance ที่ต้องอนุมัติ']);
        }

        return DB::transaction(function () use ($document, $actor, $reason, $status, $audit, $request, $policy, $match): PurchaseVarianceApproval {
            $previous = PurchaseVarianceApproval::query()->where('purchase_document_id', $document->id)->latest('revision')->lockForUpdate()->first();
            $approval = $this->createDecision($document, $actor, $reason, $status, $policy, $match, $previous ? $previous->revision + 1 : 0);
            $audit->record('wms.purchase_document.variance_'.strtolower($status), $document, $previous?->only(['status', 'revision', 'evidence_hash']) ?? [], $approval->only(['status', 'revision', 'reason', 'evidence_hash', 'recovery_hint']), $actor, $request);

            return $approval;
        });
    }

    private function createDecision(PurchaseDocument $document, User $actor, string $reason, string $status, PurchaseThreeWayMatchPolicy $policy, array $match, int $revision): PurchaseVarianceApproval
    {
        return PurchaseVarianceApproval::query()->create([
            'purchase_document_id' => $document->id,
            'status' => $status,
            'revision' => $revision,
            'actor_id' => $actor->id,
            'acted_at' => now(),
            'reason' => trim($reason),
            'policy_snapshot' => $this->policySnapshot($policy),
            'match_snapshot' => $match,
            'evidence_hash' => PurchaseVarianceApproval::evidenceHash($match, $policy),
            'recovery_hint' => $status === 'REJECTED' ? 'แก้ไข Draft/ต้นทาง แล้วตรวจ 3-way match ใหม่ก่อนส่งอนุมัติ' : ($status === 'RECOVERED' ? 'ต้องส่ง variance approval ใหม่หลังแก้เอกสาร' : 'ผ่าน variance approval แล้ว แต่ยังต้องผ่าน Inventory/GL gate'),
        ]);
    }

    private function approvalPolicy(): PurchaseThreeWayMatchPolicy
    {
        return new PurchaseThreeWayMatchPolicy(requireApprovalOnVariance: true, blockOnVariance: false);
    }

    private function policySnapshot(PurchaseThreeWayMatchPolicy $policy): array
    {
        return ['quantity_tolerance' => $policy->quantityTolerance, 'price_tolerance' => $policy->priceTolerance, 'require_approval_on_variance' => $policy->requireApprovalOnVariance, 'block_on_variance' => $policy->blockOnVariance];
    }
}
