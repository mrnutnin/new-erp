<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Production\Requests\SaveBomRequest;
use App\Modules\Production\Services\BomService;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class BomController extends Controller
{
    public function index(): View
    {
        return view('Production::boms.index');
    }

    public function data(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $status = strtoupper(trim((string) $request->input('status')));
        $query = Bom::query()
            ->leftJoin('wms_items as finished_items', 'finished_items.id', '=', 'production_boms.finished_item_id')
            ->leftJoin('wms_uoms as base_uoms', 'base_uoms.id', '=', 'production_boms.base_uom_id')
            ->where('production_boms.branch_id', $branchId)
            ->when($status && in_array($status, ['DRAFT', 'ACTIVE', 'INACTIVE'], true), fn ($q) => $q->whereHas('revisions', fn ($revision) => $revision->where('status', $status)))
            ->with(['activeRevision:id,bom_id,revision_number,status,effective_from', 'revisions' => fn ($q) => $q->withCount('lines')->orderByDesc('revision_number')])
            ->select(['production_boms.*', 'finished_items.code as finished_item_code', 'finished_items.name as finished_item_name', 'base_uoms.code as base_uom_code']);

        return DataTables::eloquent($query)
            ->addColumn('finished_item_label', fn ($row) => trim(($row->finished_item_code ?? '').' · '.($row->finished_item_name ?? ''), ' ·'))
            ->addColumn('revision_label', fn ($row) => $row->activeRevision ? 'Rev '.$row->activeRevision->revision_number : 'ยังไม่มี Active Revision')
            ->addColumn('effective_from_label', fn ($row) => $row->activeRevision?->effective_from?->format('d/m/Y') ?: '-')
            ->addColumn('component_count', fn ($row) => (int) ($row->activeRevision ? $row->revisions->firstWhere('id', $row->activeRevision->id)?->lines_count : $row->revisions->first()?->lines_count ?? 0))
            ->addColumn('status', fn ($row) => $row->activeRevision ? 'ACTIVE' : ($row->revisions->first()?->status ?? 'DRAFT'))
            ->addColumn('status_label', fn ($row) => ['DRAFT' => 'ร่าง', 'ACTIVE' => 'ใช้งาน', 'INACTIVE' => 'เลิกใช้'][$row->activeRevision ? 'ACTIVE' : ($row->revisions->first()?->status ?? 'DRAFT')])
            ->addColumn('show_url', fn ($row) => route('production.boms.show', $row->id))
            ->addColumn('edit_url', function ($row) use ($request): ?string {
                $draft = $row->revisions->firstWhere('status', 'DRAFT');
                return $draft && $request->user()->hasPermission('production.boms.update') ? route('production.boms.revisions.edit', [$row->id, $draft->id]) : null;
            })
            ->addColumn('delete_url', function ($row) use ($request): ?string {
                $draft = $row->revisions->firstWhere('status', 'DRAFT');
                return $draft && $request->user()->hasPermission('production.boms.delete') ? route('production.boms.destroy', [$row->id, $draft->id]) : null;
            })
            ->addColumn('deactivate_url', function ($row) use ($request): ?string {
                $active = $row->activeRevision;
                return $active && $request->user()->hasPermission('production.boms.deactivate') ? route('production.boms.revisions.deactivate', [$row->id, $active->id]) : null;
            })
            ->filterColumn('finished_item_label', fn ($q, $keyword) => $q->where(fn ($nested) => $nested->where('finished_items.code', 'like', "%{$keyword}%")->orWhere('finished_items.name', 'like', "%{$keyword}%")))
            ->filterColumn('revision_label', fn ($q, $keyword) => $q->whereHas('revisions', fn ($revision) => $revision->where('revision_number', 'like', '%'.preg_replace('/\D+/', '', $keyword).'%')))
            ->filterColumn('effective_from_label', function ($q, $keyword): void {
                $date = \DateTimeImmutable::createFromFormat('d/m/Y', trim($keyword));
                $q->whereHas('revisions', fn ($revision) => $revision->where('status', 'ACTIVE')->whereDate('effective_from', $date ? $date->format('Y-m-d') : $keyword));
            })
            ->filterColumn('status_label', function ($q, $keyword): void {
                $map = ['ร่าง' => 'DRAFT', 'ใช้งาน' => 'ACTIVE', 'เลิกใช้' => 'INACTIVE'];
                $statuses = collect($map)->filter(fn ($status, $label) => str_contains($label, $keyword) || str_contains($status, strtoupper($keyword)))->values();
                $statuses->isNotEmpty()
                    ? $q->whereHas('revisions', fn ($revision) => $revision->whereIn('status', $statuses))
                    : $q->whereRaw('1 = 0');
            })
            ->toJson();
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->where('is_stock_item', true)
            ->when($q, fn ($query) => $query->where(fn ($nested) => $nested->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn (Item $item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name, 'uom_id' => $item->base_uom_id, 'uom_label' => trim(($item->baseUom?->code ?? '').' · '.($item->baseUom?->name ?? ''), ' ·')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(Request $request): View
    {
        return view('Production::boms.form', ['bom' => new Bom(), 'revision' => new BomRevision(), 'lines' => collect(), 'quantityStep' => WmsDecimal::step()]);
    }

    public function store(SaveBomRequest $request, BomService $service): JsonResponse
    {
        abort_unless(! collect($request->validated()['lines'] ?? [])->contains(fn ($line) => ! empty($line['substitutes'] ?? [])) || $request->user()->hasPermission('production.boms.substitute.manage'), 403);
        $bom = $service->create($request->validated(), $this->branch($request), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกร่าง BOM แล้ว', 'redirect' => route('production.boms.show', $bom)]);
    }

    public function show(Request $request, Bom $bom): View
    {
        $this->scope($request, $bom);
        $bom->load(['finishedItem:id,code,name,cover_image_disk,cover_image_path', 'baseUom:id,code,name', 'revisions.lines.componentItem:id,code,name', 'revisions.lines.uom:id,code,name', 'revisions.lines.substitutes.substituteItem:id,code,name', 'revisions.operations', 'revisions.activator:id,name']);
        $revision = $request->integer('revision_id') ? $bom->revisions->firstWhere('id', $request->integer('revision_id')) : $bom->revisions->first();
        abort_unless($revision, 404);
        $history = AuditLog::query()->with('user:id,name')->whereIn('subject_type', [$bom->getMorphClass(), $revision->getMorphClass()])->whereIn('subject_id', [$bom->id, $revision->id])->latest('created_at')->latest('id')->limit(100)->get();

        return view('Production::boms.show', compact('bom', 'revision', 'history'));
    }

    public function edit(Request $request, Bom $bom, BomRevision $revision): View
    {
        $this->scopeRevision($request, $bom, $revision);
        abort_unless($revision->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะ Revision ร่าง');
        $revision->load(['lines.componentItem:id,code,name,base_uom_id', 'lines.uom:id,code,name', 'lines.substitutes.substituteItem:id,code,name', 'lines.substitutes.uom:id,code,name', 'operations']);
        $bom->load(['finishedItem:id,code,name', 'baseUom:id,code,name']);

        return view('Production::boms.form', ['bom' => $bom, 'revision' => $revision, 'lines' => $revision->lines, 'quantityStep' => WmsDecimal::step()]);
    }

    public function update(SaveBomRequest $request, Bom $bom, BomRevision $revision, BomService $service): JsonResponse
    {
        $this->scopeRevision($request, $bom, $revision);
        abort_unless(! collect($request->validated()['lines'] ?? [])->contains(fn ($line) => ! empty($line['substitutes'] ?? [])) || $request->user()->hasPermission('production.boms.substitute.manage'), 403);
        $service->updateDraft($revision, $request->validated(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไข BOM Revision ร่างแล้ว', 'redirect' => route('production.boms.show', [$bom, 'revision_id' => $revision->id])]);
    }

    public function destroy(Request $request, Bom $bom, BomRevision $revision, BomService $service): JsonResponse
    {
        $this->scopeRevision($request, $bom, $revision);
        $service->deleteDraft($bom, $revision, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่าง BOM แล้ว', 'redirect' => route('production.boms.index')]);
    }

    public function deactivate(Request $request, Bom $bom, BomRevision $revision, BomService $service): JsonResponse
    {
        $this->scopeRevision($request, $bom, $revision);
        $service->deactivate($revision, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ปิดใช้งาน BOM แล้ว', 'redirect' => route('production.boms.index')]);
    }

    public function copy(Request $request, Bom $bom, BomRevision $revision, BomService $service): JsonResponse
    {
        $this->scopeRevision($request, $bom, $revision);
        $copy = $service->copyRevision($revision, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'คัดลอกเป็น Revision ร่างแล้ว', 'redirect' => route('production.boms.revisions.edit', [$bom, $copy])]);
    }

    public function activate(Request $request, Bom $bom, BomRevision $revision, BomService $service): JsonResponse
    {
        $this->scopeRevision($request, $bom, $revision);
        $service->activate($revision, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'เปิดใช้ BOM Revision แล้ว']);
    }

    private function scope(Request $request, Bom $bom): void
    {
        abort_unless((int) $bom->branch_id === $this->branchId($request), 404);
    }

    private function scopeRevision(Request $request, Bom $bom, BomRevision $revision): void
    {
        $this->scope($request, $bom);
        abort_unless((int) $revision->bom_id === (int) $bom->id, 404);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedWarehouse')->branch_id;
    }

    private function branch(Request $request): Branch
    {
        return Branch::query()->findOrFail($this->branchId($request));
    }
}
