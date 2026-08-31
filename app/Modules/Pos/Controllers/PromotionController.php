<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\Promotion;
use App\Modules\Pos\Models\PromotionCampaignCost;
use App\Modules\Pos\Models\PromotionItem;
use App\Modules\Pos\Requests\SavePromotionRequest;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PromotionController extends Controller
{
    public function index(): View
    {
        return view('Pos::promotions.index');
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent($this->query())
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $query->where(fn (Builder $q) => $q->where('pos_promotions.code', 'like', "%{$search}%")->orWhere('pos_promotions.name', 'like', "%{$search}%")->orWhere('pos_promotions.customer_group_code', 'like', "%{$search}%"));
                }
                if ($request->filled('is_active')) {
                    $query->where('pos_promotions.is_active', $request->boolean('is_active'));
                }
                if ($request->filled('customer_group_code')) {
                    $query->where('pos_promotions.customer_group_code', $request->string('customer_group_code')->toString());
                }
            })
            ->order(function (Builder $query) use ($request): void {
                $columns = [0 => 'pos_promotions.code', 1 => 'pos_promotions.name', 2 => 'pos_promotions.application_scope', 3 => 'pos_promotions.customer_group_code', 4 => 'items_count', 5 => 'pos_promotions.priority', 6 => 'pos_promotions.effective_from', 7 => 'pos_promotions.is_active'];
                $query->reorder($columns[(int) $request->input('order.0.column', 0)] ?? 'pos_promotions.priority', $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('pos_promotions.id');
            })
            ->addColumn('group_label', fn (Promotion $promotion) => $promotion->customer_group_code ?: 'ทุกกลุ่ม')
            ->addColumn('scope_label', fn (Promotion $promotion) => $promotion->application_scope === 'DOCUMENT' ? 'ท้ายบิล' : 'ต่อรายการ')
            ->addColumn('line_count', fn (Promotion $promotion) => $promotion->items_count)
            ->editColumn('effective_from', fn (Promotion $promotion) => $promotion->effective_from?->format('d/m/Y') ?: '—')
            ->editColumn('effective_to', fn (Promotion $promotion) => $promotion->effective_to?->format('d/m/Y') ?: '—')
            ->addColumn('status', fn (Promotion $promotion) => $promotion->is_active ? 'ACTIVE' : 'INACTIVE')
            ->addColumn('show_url', fn (Promotion $promotion) => route('pos.promotions.show', $promotion))
            ->addColumn('edit_url', fn (Promotion $promotion) => $request->user()->hasPermission('pos.promotions.update') ? route('pos.promotions.edit', $promotion) : null)
            ->addColumn('delete_url', fn (Promotion $promotion) => $request->user()->hasPermission('pos.promotions.delete') ? route('pos.promotions.destroy', $promotion) : null)
            ->toJson();
    }

    public function create(): View
    {
        return view('Pos::promotions.form', ['promotion' => new Promotion(['currency' => 'THB', 'priority' => 0, 'application_scope' => 'LINE', 'is_active' => true]), 'lines' => [new PromotionItem(['minimum_quantity' => '0.0000', 'is_active' => true])], 'owners' => $this->campaignOwners()]);
    }

    public function store(SavePromotionRequest $request, AuditLogger $audit): JsonResponse
    {
        $promotion = DB::transaction(function () use ($request, $audit): Promotion {
            $promotion = Promotion::query()->create($this->headerValues($request->validated(), $request, true));
            $this->syncLines($promotion, $request->validated('lines', []), $request);
            $audit->record('pos.promotion.created', $promotion, [], $this->auditValues($promotion->fresh('items')), $request->user(), $request);

            return $promotion;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่มโปรโมชั่นแล้ว', 'redirect' => route('pos.promotions.show', $promotion)]);
    }

    public function show(Request $request, Promotion $promotion, GlobalSettings $settings): View
    {
        $promotion->load(['items.item:id,code,name', 'items.uom:id,code,name', 'campaignOwner:id,name']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $promotion->getMorphClass())->where('subject_id', $promotion->id)->latest('created_at')->latest('id')->get();

        return view('Pos::promotions.show', ['promotion' => $promotion, 'history' => $history, 'campaignCosts' => PromotionCampaignCost::query()->with('creator:id,name')->where('promotion_id', $promotion->id)->where('branch_id', $request->attributes->get('selectedBranch')->id)->latest('cost_date')->latest('id')->get(), 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function edit(Promotion $promotion): View
    {
        return view('Pos::promotions.form', ['promotion' => $promotion, 'lines' => $promotion->items()->with('item.baseUom:id,code,name')->orderBy('id')->get(), 'owners' => $this->campaignOwners()]);
    }

    public function update(SavePromotionRequest $request, Promotion $promotion, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $promotion, $audit): void {
            $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $before = $this->auditValues($promotion->load('items'));
            $promotion->update($this->headerValues($request->validated(), $request));
            $this->syncLines($promotion, $request->validated('lines', []), $request);
            $audit->record('pos.promotion.updated', $promotion, $before, $this->auditValues($promotion->fresh('items')), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขโปรโมชั่นแล้ว']);
    }

    public function destroy(Request $request, Promotion $promotion, AuditLogger $audit): JsonResponse
    {
        abort_if($promotion->campaignCosts()->exists(), 422, 'ลบโปรโมชั่นที่มีค่าใช้จ่าย Campaign แล้วไม่ได้');

        DB::transaction(function () use ($request, $promotion, $audit): void {
            $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);
            $before = $this->auditValues($promotion->load('items'));
            $promotion->delete();
            $audit->record('pos.promotion.deleted', $promotion, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบโปรโมชั่นแล้ว']);
    }

    public function storeCampaignCost(Request $request, Promotion $promotion, AuditLogger $audit): JsonResponse
    {
        $values = $request->validate(['cost_date' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'not_in:0'], 'reference' => ['nullable', 'string', 'max:100'], 'note' => ['required', 'string', 'max:500']]);
        $cost = PromotionCampaignCost::query()->create([...$values, 'promotion_id' => $promotion->id, 'branch_id' => $request->attributes->get('selectedBranch')->id, 'created_by' => $request->user()->id]);
        $audit->record('pos.promotion_campaign_cost.created', $cost, [], $cost->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกค่าใช้จ่าย Campaign แล้ว']);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $items = Item::query()->with('baseUom:id,code,name')->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $nested) => $nested->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->limit(30)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $items->map(fn (Item $item) => ['id' => $item->id, 'text' => $item->code.' · '.$item->name, 'uom_id' => $item->base_uom_id, 'uom_text' => $item->baseUom ? $item->baseUom->code.' · '.$item->baseUom->name : 'ไม่พบหน่วย Stock'])->all(), 'pagination' => ['more' => false]]);
    }

    public function uomOptions(Request $request): JsonResponse
    {
        return response()->json(['results' => $this->options(Uom::query()->where('is_active', true), $request, fn (Uom $uom) => ['id' => $uom->id, 'text' => $uom->code.' · '.$uom->name]), 'pagination' => ['more' => false]]);
    }

    public function groupOptions(Request $request): JsonResponse
    {
        return response()->json(['results' => $this->options(CustomerGroup::query()->forCompany($this->companySettingId())->where('is_active', true), $request, fn (CustomerGroup $group) => ['id' => $group->code, 'text' => $group->code.' · '.$group->name]), 'pagination' => ['more' => false]]);
    }

    private function query(): Builder
    {
        return Promotion::query()->select('pos_promotions.*')->withCount('items');
    }

    private function options(Builder $query, Request $request, callable $map): array
    {
        $search = trim((string) $request->input('q', ''));

        return $query->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $nested) => $nested->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))->orderBy('code')->limit(30)->get(['id', 'code', 'name'])->map($map)->all();
    }

    private function headerValues(array $data, Request $request, bool $creating = false): array
    {
        $values = collect($data)->only(['code', 'name', 'currency', 'customer_group_code', 'priority', 'campaign_budget_amount', 'campaign_target_sales_amount', 'campaign_target_gross_profit_amount', 'campaign_owner_id', 'effective_from', 'effective_to', 'application_scope', 'stackable', 'bill_discount_amount', 'bill_discount_percent', 'is_active'])->put('updated_by', $request->user()->id);
        if ($creating) {
            $values->put('created_by', $request->user()->id);
        }

        return $values->all();
    }

    private function syncLines(Promotion $promotion, array $lines, Request $request): void
    {
        $items = Item::query()->whereIn('id', collect($lines)->pluck('item_id')->filter())->get(['id', 'base_uom_id'])->keyBy('id');
        $promotion->items()->delete();
        foreach ($lines as $line) {
            $item = $items->get($line['item_id']);
            abort_if(! $item?->base_uom_id, 422, 'สินค้าในโปรโมชั่นต้องกำหนดหน่วย Stock ก่อน');
            $promotion->items()->create([...collect($line)->only(['item_id', 'minimum_quantity', 'unit_price', 'base_unit_price', 'discount_percent', 'is_active'])->all(), 'uom_id' => $item->base_uom_id, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        }
    }

    private function companySettingId(): int
    {
        return (int) (CompanySetting::query()->value('id') ?: 1);
    }

    private function auditValues(Promotion $promotion): array
    {
        return $promotion->toArray();
    }

    private function campaignOwners()
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
