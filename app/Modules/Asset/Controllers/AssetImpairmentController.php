<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetImpairment;
use App\Modules\Asset\Services\AssetImpairmentService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetImpairmentController extends Controller
{
    public function index(): View
    {
        return view('Asset::impairments.index');
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent(AssetImpairment::query()->with('asset:id,asset_number,name')->where('branch_id', $this->branchId($request))->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))->when($request->filled('assessment_date_from'), fn ($query) => $query->whereDate('assessment_date', '>=', $request->string('assessment_date_from')->toString()))->when($request->filled('assessment_date_to'), fn ($query) => $query->whereDate('assessment_date', '<=', $request->string('assessment_date_to')->toString()))->latest('assessment_date')->latest('id'))
            ->addColumn('asset_label', fn (AssetImpairment $row) => $row->asset?->asset_number.' · '.$row->asset?->name)
            ->addColumn('show_url', fn (AssetImpairment $row) => route('asset.impairments.show', $row))->toJson();
    }

    public function create(): View
    {
        return view('Asset::impairments.form', ['impairment' => new AssetImpairment(['assessment_date' => today()])]);
    }

    public function show(Request $request, AssetImpairment $impairment): View
    {
        return view('Asset::impairments.show', ['impairment' => $this->scoped($request, $impairment)->load(['asset', 'createdBy'])]);
    }

    public function store(Request $request, AssetImpairmentService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $data = $request->validate(['asset_id' => ['required', 'integer', 'exists:assets,id'], 'assessment_date' => ['required', 'date'], 'recoverable_amount' => ['required', 'numeric', 'min:0'], 'evidence_reference' => ['nullable', 'string', 'max:255'], 'reason' => ['required', 'string', 'min:10']]);
        $impairment = DB::transaction(function () use ($request, $data, $service, $sequences): AssetImpairment {
            $asset = Asset::query()->where('branch_id', $this->branchId($request))->lockForUpdate()->findOrFail($data['asset_id']);
            if ((float) $data['recoverable_amount'] > (float) $asset->book_value) {
                throw ValidationException::withMessages([
                    'recoverable_amount' => 'มูลค่าคาดว่าจะได้รับต้องไม่สูงกว่ามูลค่าตามบัญชีปัจจุบัน ('.number_format((float) $asset->book_value, 2).')',
                ]);
            }
            $amount = $service->assess((float) $asset->book_value, (float) $data['recoverable_amount']);
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_IMPAIRMENT')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['assessment_date' => 'ยังไม่ได้ตั้งค่าเลขที่เอกสารด้อยค่าสินทรัพย์']);
            }
            $date = Carbon::parse($data['assessment_date']);
            $number = $sequences->issueAvailableForBranch($sequence, $request->attributes->get('selectedBranch'), $date, fn (string $candidate): bool => AssetImpairment::query()->where('document_number', $candidate)->exists());
            $row = AssetImpairment::query()->create([...$data, 'document_number' => $number, 'branch_id' => $asset->branch_id, 'carrying_amount' => $asset->book_value, 'impairment_amount' => $amount['impairment_amount'], 'created_by' => $request->user()->id]);
            $sequences->recordIssued($sequence, $number, 'asset_impairments', $row->id, $date, $request->user()->id);

            return $row;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างใบด้อยค่าสินทรัพย์แล้ว', 'redirect' => route('asset.impairments.show', $impairment)]);
    }

    public function submit(Request $request, AssetImpairment $impairment): JsonResponse
    {
        return $this->transition($request, $impairment, 'DRAFT', 'SUBMITTED', ['submitted_by' => $request->user()->id, 'submitted_at' => now()]);
    }

    public function approve(Request $request, AssetImpairment $impairment): JsonResponse
    {
        return $this->transition($request, $impairment, 'SUBMITTED', 'APPROVED', ['approved_by' => $request->user()->id, 'approved_at' => now()]);
    }

    public function cancel(Request $request, AssetImpairment $impairment): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10']]);

        return $this->transition($request, $impairment, ['DRAFT', 'SUBMITTED', 'APPROVED'], 'CANCELLED', ['cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancellation_reason' => $data['cancellation_reason']]);
    }

    public function post(Request $request, AssetImpairment $impairment, AssetImpairmentService $service): JsonResponse
    {
        $row = $service->post($this->scoped($request, $impairment), $request->user());

        return response()->json(['status' => true, 'msg' => 'ลงบัญชีด้อยค่าแล้ว', 'redirect' => route('asset.impairments.show', $row)]);
    }

    public function reverse(Request $request, AssetImpairment $impairment, AssetImpairmentService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $original = $this->scoped($request, $impairment);
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_IMPAIRMENT')->where('is_active', true)->lockForUpdate()->firstOrFail();
        $number = $sequences->issueAvailableForBranch($sequence, $request->attributes->get('selectedBranch'), today(), fn (string $candidate): bool => AssetImpairment::query()->where('document_number', $candidate)->exists());
        $row = $service->reverse($original, $number, $data['reason'], $request->user());
        $sequences->recordIssued($sequence, $number, 'asset_impairments', $row->id, today(), $request->user()->id);

        return response()->json(['status' => true, 'msg' => 'กลับรายการด้อยค่าแล้ว', 'redirect' => route('asset.impairments.show', $row)]);
    }

    private function transition(Request $request, AssetImpairment $impairment, string|array $from, string $to, array $values): JsonResponse
    {
        $row = $this->scoped($request, $impairment);
        if (! in_array($row->status, (array) $from, true)) {
            throw ValidationException::withMessages(['status' => 'สถานะเอกสารไม่พร้อมสำหรับขั้นตอนนี้']);
        }
        $row->update(['status' => $to, ...$values]);

        return response()->json(['status' => true, 'msg' => 'เปลี่ยนสถานะเอกสารแล้ว', 'redirect' => route('asset.impairments.show', $row)]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function scoped(Request $request, AssetImpairment $impairment): AssetImpairment
    {
        return AssetImpairment::query()->where('branch_id', $this->branchId($request))->findOrFail($impairment->id);
    }
}
