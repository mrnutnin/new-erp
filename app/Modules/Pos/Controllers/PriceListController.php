<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\CustomerGroup;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\PriceList;
use App\Modules\Pos\Models\PriceListItem;
use App\Modules\Pos\Requests\SavePriceListRequest;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PriceListController extends Controller
{
    public function index(): View
    {
        return view('Pos::price-lists.index');
    }

    public function data(Request $request): JsonResponse
    {
        $table = DataTables::eloquent($this->query($request))
            ->filter(function (Builder $query) use ($request): void {
                $query
                    ->when($request->filled('customer_group_code'), fn (Builder $query) => $query->where('pos_price_lists.customer_group_code', $request->string('customer_group_code')->toString()))
                    ->when($request->filled('is_active'), fn (Builder $query) => $query->where('pos_price_lists.is_active', $request->boolean('is_active')));

                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $query->where(fn (Builder $q) => $q
                        ->where('pos_price_lists.code', 'like', "%{$search}%")
                        ->orWhere('pos_price_lists.name', 'like', "%{$search}%")
                        ->orWhere('pos_price_lists.customer_group_code', 'like', "%{$search}%")
                        ->orWhere('pos_price_lists.currency', 'like', "%{$search}%"));
                }
            })
            ->order(function (Builder $query) use ($request): void {
                $columns = [0 => 'pos_price_lists.code', 1 => 'pos_price_lists.name', 2 => 'pos_price_lists.customer_group_code', 3 => 'pos_price_lists.currency', 4 => 'pos_price_lists.priority', 5 => 'pos_price_lists.effective_from', 6 => 'pos_price_lists.is_active'];
                $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'pos_price_lists.code';
                $query->reorder($column, $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderBy('pos_price_lists.id', 'desc');
            })
            ->addColumn('group_label', fn (PriceList $priceList) => $priceList->customer_group_code ?: 'ทุกกลุ่ม')
            ->editColumn('effective_from', fn (PriceList $priceList) => $priceList->effective_from?->format('d/m/Y') ?: '—')
            ->editColumn('effective_to', fn (PriceList $priceList) => $priceList->effective_to?->format('d/m/Y') ?: '—')
            ->addColumn('status_label', fn (PriceList $priceList) => $priceList->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')
            ->addColumn('line_count', fn (PriceList $priceList) => $priceList->items_count)
            ->addColumn('edit_url', fn (PriceList $priceList) => $request->user()->hasPermission('pos.price-lists.update') ? route('pos.price-lists.edit', $priceList) : null)
            ->addColumn('delete_url', fn (PriceList $priceList) => $request->user()->hasPermission('pos.price-lists.delete') ? route('pos.price-lists.destroy', $priceList) : null);

        return $table->toJson();
    }

    public function create(Request $request): View
    {
        return view('Pos::price-lists.form', [
            'priceList' => new PriceList(['branch_id' => $this->branchId($request), 'currency' => 'THB', 'priority' => 0, 'is_active' => true]),
            'lines' => [new PriceListItem(['minimum_quantity' => '0.0000', 'discount_percent' => '0.0000', 'is_active' => true])],
        ]);
    }

    public function edit(Request $request, PriceList $priceList): View
    {
        $priceList = $this->scoped($request, $priceList);

        return view('Pos::price-lists.form', [
            'priceList' => $priceList,
            'lines' => $priceList->items()->with(['item:id,code,name', 'uom:id,code,name'])->orderBy('id')->get(),
        ]);
    }

    public function store(SavePriceListRequest $request, AuditLogger $audit): JsonResponse
    {
        $priceList = DB::transaction(function () use ($request, $audit): PriceList {
            $data = $request->validated();
            $priceList = PriceList::query()->create($this->headerValues($data, $request, true));
            $this->syncLines($priceList, $data['lines'], $request);
            $audit->record('pos.price-list.created', $priceList, [], $this->auditValues($priceList), $request->user(), $request);

            return $priceList;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่ม Price List แล้ว', 'redirect' => route('pos.price-lists.index')]);
    }

    public function update(SavePriceListRequest $request, PriceList $priceList, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $priceList, $audit): void {
            $priceList = PriceList::query()->whereKey($this->scoped($request, $priceList)->id)->lockForUpdate()->firstOrFail();
            $before = $this->auditValues($priceList->load('items'));
            $data = $request->validated();
            $priceList->update($this->headerValues($data, $request));
            $this->syncLines($priceList, $data['lines'], $request);
            $audit->record('pos.price-list.updated', $priceList, $before, $this->auditValues($priceList->fresh('items')), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไข Price List แล้ว']);
    }

    public function destroy(Request $request, PriceList $priceList, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $priceList, $audit): void {
            $priceList = PriceList::query()->whereKey($this->scoped($request, $priceList)->id)->lockForUpdate()->firstOrFail();
            $before = $this->auditValues($priceList->load('items'));
            $priceList->delete();
            $audit->record('pos.price-list.deleted', $priceList, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบ Price List แล้ว']);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $items = Item::query()->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $items->take(30)->map(fn (Item $item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name])->values(), 'pagination' => ['more' => $items->count() > 30]]);
    }

    public function uomOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $uoms = Uom::query()->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name']);

        return response()->json(['results' => $uoms->take(30)->map(fn (Uom $uom) => ['id' => $uom->id, 'text' => $uom->code.' · '.$uom->name])->values(), 'pagination' => ['more' => $uoms->count() > 30]]);
    }

    public function groupOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $groups = CustomerGroup::query()->forCompany($this->companySettingId())->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['code', 'name']);

        return response()->json(['results' => $groups->take(30)->map(fn (CustomerGroup $group) => ['id' => $group->code, 'text' => $group->code.' · '.$group->name])->values(), 'pagination' => ['more' => $groups->count() > 30]]);
    }

    private function query(Request $request): Builder
    {
        return PriceList::query()->where('branch_id', $this->branchId($request))->withCount('items')->select('pos_price_lists.*');
    }

    private function headerValues(array $data, Request $request, bool $creating = false): array
    {
        $values = collect($data)->only(['code', 'name', 'currency', 'customer_group_code', 'priority', 'effective_from', 'effective_to', 'is_active'])
            ->put('branch_id', $this->branchId($request))
            ->put('updated_by', $request->user()->id);
        if ($creating) {
            $values->put('created_by', $request->user()->id);
        }

        return $values->all();
    }

    private function companySettingId(): int
    {
        return (int) (CompanySetting::query()->value('id') ?: 1);
    }

    private function syncLines(PriceList $priceList, array $lines, Request $request): void
    {
        $priceList->items()->delete();
        foreach ($lines as $line) {
            $priceList->items()->create([...collect($line)->only(['item_id', 'uom_id', 'minimum_quantity', 'unit_price', 'discount_percent', 'effective_from', 'effective_to', 'is_active'])->all(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        }
    }

    private function auditValues(PriceList $priceList): array
    {
        return $priceList->toArray();
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function scoped(Request $request, PriceList $priceList): PriceList
    {
        abort_unless((int) $priceList->branch_id === $this->branchId($request), 404);

        return $priceList;
    }
}
