<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Requests\SaveOpportunityRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Support\SalesDocumentTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class OpportunityController extends Controller
{
    private const TRAIL_META=[
        'intake'=>['label'=>'Sales Intake','permission'=>'pos.sales-intakes.view','program'=>'pos','route'=>'pos.sales-intakes.show'],
        'rfq'=>['label'=>'RFQ','permission'=>'pos.sales-rfqs.view','program'=>'pos','route'=>'pos.sales-rfqs.show'],
        'quotation'=>['label'=>'Quotation','permission'=>'pos.sales-quotations.view','program'=>'pos','route'=>'pos.sales-quotations.show'],
        'order'=>['label'=>'Sales Order','permission'=>'pos.sales-orders.view','program'=>'pos','route'=>'pos.sales-orders.show'],
        'sale'=>['label'=>'Invoice / Sale','permission'=>'pos.physical-sales.view','program'=>'pos','route'=>'pos.physical-sales.show'],
        'payment'=>['label'=>'Payment','permission'=>'finance.settlements.view','program'=>'finance','route'=>'finance.settlements.show'],
    ];
    public function index(Request $request): View
    {
        return view('Crm::opportunities.index', [
            'stages' => Opportunity::STAGES,
            'filters' => $this->filters($request),
            'selectedOwner' => $request->filled('owner_id') ? User::query()->find($request->integer('owner_id')) : null,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $opportunities = $this->opportunityQuery($request, $this->filters($request))->paginate(12)->withPath(route('crm.opportunities.index'))->withQueryString();

        return response()->json([
            'html' => view('Crm::opportunities._cards', ['opportunities' => $opportunities, 'stages' => Opportunity::STAGES])->render(),
            'pagination' => $opportunities->hasPages() ? $opportunities->onEachSide(1)->links('pagination::bootstrap-5')->render() : '',
            'from' => $opportunities->firstItem(),
            'to' => $opportunities->lastItem(),
            'total' => $opportunities->total(),
        ]);
    }

    public function ownerOptions(Request $request): JsonResponse
    {
        abort_unless(collect(['crm.opportunities.view', 'crm.opportunities.create', 'crm.opportunities.update', 'crm.activities.create', 'crm.team-work.view', 'crm.team-work.view-all', 'crm.teams.manage'])->contains(fn (string $permission) => $request->user()->hasPermission($permission)), 403);
        $search = trim((string) $request->input('q'));
        $rows = User::query()->where('is_active', true)
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('employee_code', 'like', "%{$search}%")))
            ->orderBy('name')->forPage(max(1, $request->integer('page', 1)), 31)
            ->get(['id', 'name', 'employee_code', 'position']);

        return response()->json([
            'results' => $rows->take(30)->map(fn (User $user) => ['id' => $user->id, 'text' => ($user->employee_code ? $user->employee_code.' · ' : '').$user->name, 'position' => $user->position])->values(),
            'pagination' => ['more' => $rows->count() > 30],
        ]);
    }

    public function customerOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q'));
        $rows = Party::query()->where('is_active', true)->whereHas('customerRole', fn ($q) => $q->where('is_active', true))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'contact_name', 'phone', 'email']);

        return response()->json(['results' => $rows->take(30)->map(fn (Party $party) => [
            'id' => $party->id, 'text' => $party->code.' · '.$party->name, 'contact_name' => $party->contact_name, 'phone' => $party->phone, 'email' => $party->email,
        ])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(Request $request): View
    {
        $party = $request->filled('party_id')
            ? Party::query()->whereKey($request->integer('party_id'))->where('is_active', true)->whereHas('customerRole', fn (Builder $query) => $query->where('is_active', true))->firstOrFail()
            : null;

        return $this->form(new Opportunity([
            'stage' => 'NEW', 'probability' => 10, 'expected_value' => '0.00', 'owner_id' => auth()->id(),
            'party_id' => $party?->id, 'contact_name' => $party?->contact_name, 'phone' => $party?->phone, 'email' => $party?->email,
        ]));
    }

    public function store(SaveOpportunityRequest $request, AuditLogger $audit): JsonResponse
    {
        $opportunity = DB::transaction(function () use ($request, $audit): Opportunity {
            $values = $this->values($request);
            $opportunity = Opportunity::query()->create([...$values, 'branch_id' => $this->branchId($request), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('crm.opportunity.created', $opportunity, [], $opportunity->toArray(), $request->user(), $request);
            return $opportunity;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างโอกาสการขายแล้ว', 'redirect' => route('crm.opportunities.show', $opportunity)]);
    }

    public function show(Request $request, Opportunity $opportunity): View
    {
        $this->scope($request, $opportunity);
        $opportunity->load(['party:id,code,name,contact_name,phone,email','owner:id,name','salesIntake.rfq.quotation.order.physicalSales','salesIntake.rfq.order.physicalSales','salesIntake.quotation.order.physicalSales','salesIntake.order.physicalSales']);
        $activityCount = $opportunity->activities()->count();
        $posProgram = $request->user()->programs()->where('code', 'pos')->where('is_enabled', true)->exists();
        $canOpenPos=$posProgram&&$request->user()->hasPermission('pos.sales-intakes.create');
        $warehouses=$canOpenPos?$request->user()->warehouses()->where('warehouses.branch_id',$opportunity->branch_id)->where('warehouses.is_active',true)->orderBy('warehouses.name')->get(['warehouses.id','warehouses.code','warehouses.name']):collect();

        return view('Crm::opportunities.show', ['opportunity' => $opportunity, 'stages' => Opportunity::STAGES, 'activityCount' => $activityCount, 'canOpenPos' => $canOpenPos,'warehouses'=>$warehouses,'documentTrail'=>$this->documentTrail($request,$opportunity)]);
    }

    public function edit(Request $request, Opportunity $opportunity): View
    {
        $this->scope($request, $opportunity);
        abort_if(in_array($opportunity->stage, ['WON', 'LOST'], true), 422, 'โอกาสการขายที่ปิดแล้วแก้ไขไม่ได้');
        return $this->form($opportunity->load('party:id,code,name'));
    }

    public function update(SaveOpportunityRequest $request, Opportunity $opportunity, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $opportunity);
        DB::transaction(function () use ($request, $opportunity, $audit): void {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $before = $opportunity->toArray();
            $opportunity->update([...$this->values($request), 'updated_by' => $request->user()->id]);
            $audit->record('crm.opportunity.updated', $opportunity, $before, $opportunity->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'อัปเดตโอกาสการขายแล้ว', 'redirect' => route('crm.opportunities.show', $opportunity)]);
    }

    public function destroy(Request $request, Opportunity $opportunity, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $opportunity);
        DB::transaction(function () use ($request, $opportunity, $audit): void {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless(in_array($opportunity->stage, ['NEW', 'CONTACTED'], true) && ! $opportunity->activities()->exists() && ! $opportunity->sales_intake_id, 422, 'ลบได้เฉพาะรายการเริ่มต้นที่ยังไม่มีกิจกรรมหรือเอกสาร POS');
            $before = $opportunity->toArray();
            $opportunity->delete();
            $audit->record('crm.opportunity.deleted', $opportunity, $before, [], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบโอกาสการขายแล้ว', 'redirect' => route('crm.opportunities.index')]);
    }

    public function toPos(Request $request, Opportunity $opportunity): RedirectResponse
    {
        $this->scope($request, $opportunity);
        abort_unless($request->user()->hasPermission('crm.opportunities.convert-pos') && $request->user()->hasPermission('pos.sales-intakes.create'), 403);
        if (! $opportunity->party_id) throw ValidationException::withMessages(['party_id' => 'ต้องผูกลูกค้าให้โอกาสการขายก่อนสร้างเอกสาร POS']);
        $program = $request->user()->programs()->where('code', 'pos')->where('is_enabled', true)->first();
        abort_unless($program, 403, 'ผู้ใช้ไม่ได้รับสิทธิ์เข้าโปรแกรม POS');
        $linkedIntake=$opportunity->salesIntake;
        $warehouses=$request->user()->warehouses()->where('warehouses.branch_id',$opportunity->branch_id)->where('warehouses.is_active',true);
        $warehouseId=$linkedIntake?(int)$linkedIntake->warehouse_id:($warehouses->count()===1?(int)(clone $warehouses)->value('warehouses.id'):(int)$request->validate(['warehouse_id'=>['required','integer']])['warehouse_id']);
        $warehouse=(clone $warehouses)->find($warehouseId);
        abort_unless($warehouse,422,'กรุณาเลือกคลังสินค้าที่ได้รับสิทธิ์ในสาขานี้');
        $request->session()->put(['selected_program_id' => $program->id, 'selected_branch_id' => $opportunity->branch_id, 'selected_warehouse_id' => $warehouse->id]);
        if ($linkedIntake) return redirect()->route('pos.sales-intakes.show', $linkedIntake);
        $interestCount=$opportunity->productInterests()->count();
        if($interestCount<1||$interestCount>100)throw ValidationException::withMessages(['product_interests'=>$interestCount<1?'กรุณาเพิ่มสินค้าที่ลูกค้าสนใจก่อนส่งต่อ POS':'ส่งต่อ POS ได้ไม่เกิน 100 รายการต่อเอกสาร']);

        return redirect()->route('pos.sales-intakes.create', ['crm_opportunity_id' => $opportunity->id]);
    }

    public function openDocument(Request $request,Opportunity $opportunity,string $documentType,int $documentId):RedirectResponse
    {
        $this->scope($request,$opportunity);$step=collect($this->documentTrail($request,$opportunity))->first(fn(array $step)=>$step['type']===$documentType&&(int)($step['id']??0)===$documentId);
        abort_unless($step&&$step['can_open'],404);$program=$request->user()->programs()->where('code',$step['program'])->where('is_enabled',true)->firstOrFail();
        $warehouse=$request->user()->warehouses()->whereKey($step['warehouse_id'])->where('warehouses.branch_id',$opportunity->branch_id)->where('warehouses.is_active',true)->first();abort_unless($warehouse,403);
        $request->session()->put(['selected_program_id'=>$program->id,'selected_branch_id'=>$opportunity->branch_id,'selected_warehouse_id'=>$warehouse->id]);
        return redirect()->route($step['route'],$documentId);
    }

    private function documentTrail(Request $request,Opportunity $opportunity):array
    {
        if(!$opportunity->salesIntake)return [];$flow=SalesDocumentTrail::for($opportunity->salesIntake);$sale=$flow['iv']??$flow['hs']??null;$payment=null;
        if($flow['iv'])$payment=DB::table('finance_settlements as settlement')->join('finance_settlement_allocation_intents as intent','intent.settlement_id','=','settlement.id')->join('finance_open_items as item','item.id','=','intent.open_item_id')->join('finance_bank_accounts as bank','bank.id','=','settlement.bank_account_id')->join('warehouses as warehouse','warehouse.id','=','bank.warehouse_id')->where('settlement.party_type','CUSTOMER')->where('settlement.party_id',$opportunity->party_id)->where('item.document_number',$flow['iv']->document_number)->where('warehouse.branch_id',$opportunity->branch_id)->whereNull('settlement.deleted_at')->orderByDesc('settlement.settlement_date')->orderByDesc('settlement.id')->select('settlement.id','settlement.document_number','settlement.settlement_date as document_date','settlement.status','bank.warehouse_id')->first();
        $programs=$request->user()->programs()->whereIn('code',['pos','finance'])->where('is_enabled',true)->pluck('code');$documents=['intake'=>$flow['intake'],'rfq'=>$flow['rfq'],'quotation'=>$flow['quotation'],'order'=>$flow['order'],'sale'=>$sale,'payment'=>$payment];
        return collect($documents)->map(function($document,string $type)use($request,$programs){$meta=self::TRAIL_META[$type];$status=$document?->status;$date=$document?->document_date??$document?->posting_date??null;return ['type'=>$type,...$meta,'id'=>$document?->id,'number'=>$document?->document_number,'status'=>$status,'status_label'=>$this->trailStatus($status),'date'=>$date?date('d/m/Y',strtotime((string)$date)):null,'warehouse_id'=>$document?->warehouse_id,'can_open'=>(bool)$document&&$programs->contains($meta['program'])&&$request->user()->hasPermission($meta['permission'])];})->values()->all();
    }

    private function trailStatus(?string $status):string
    {
        return ['DRAFT'=>'ร่าง','WAIT'=>'รอพิจารณา','APPROVED'=>'อนุมัติแล้ว','COMPLETED'=>'เสร็จสิ้น','SENT'=>'ส่งแล้ว','ACCEPTED'=>'ตอบรับแล้ว','CONFIRMED'=>'ยืนยันแล้ว','FULFILLED'=>'ดำเนินการแล้ว','POSTED'=>'ลงบัญชีแล้ว','REJECTED'=>'ไม่อนุมัติ','CANCELLED'=>'ยกเลิก','VOID'=>'ยกเลิก'][$status]??($status?:'ยังไม่มี');
    }

    private function form(Opportunity $opportunity): View
    {
        return view('Crm::opportunities.form', ['opportunity' => $opportunity, 'stages' => Opportunity::STAGES, 'owner' => User::query()->find($opportunity->owner_id), 'party' => $opportunity->party]);
    }

    private function values(SaveOpportunityRequest $request): array
    {
        $values = $request->validated();
        if (! empty($values['party_id']) && ! Party::query()->whereKey($values['party_id'])->where('is_active', true)->whereHas('customerRole', fn ($q) => $q->where('is_active', true))->exists()) {
            throw ValidationException::withMessages(['party_id' => 'ลูกค้าที่เลือกไม่พร้อมใช้งาน']);
        }
        $values['won_at'] = $values['stage'] === 'WON' ? now() : null;
        $values['lost_at'] = $values['stage'] === 'LOST' ? now() : null;
        if ($values['stage'] !== 'LOST') $values['lost_reason'] = null;
        return $values;
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', Rule::in(array_keys(Opportunity::STAGES))],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
    }

    private function opportunityQuery(Request $request, array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $stageCodes = collect(Opportunity::STAGES)->filter(fn (array $stage) => $search !== '' && str_contains($stage['label'], $search))->keys();

        return Opportunity::query()
            ->with(['party:id,code,name', 'owner:id,name', 'salesIntake:id,document_number'])
            ->where('branch_id', $this->branchId($request))
            ->when($filters['stage'] ?? null, fn (Builder $query, string $stage) => $query->where('stage', $stage))
            ->when($filters['owner_id'] ?? null, fn (Builder $query, $ownerId) => $query->where('owner_id', (int) $ownerId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('expected_close_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('expected_close_date', '<=', $date))
            ->when($search !== '', function (Builder $query) use ($search, $stageCodes): void {
                $query->where(function (Builder $query) use ($search, $stageCodes): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('source', 'like', "%{$search}%")
                        ->orWhere('lost_reason', 'like', "%{$search}%")
                        ->orWhereIn('stage', $stageCodes)
                        ->orWhereHas('party', fn (Builder $party) => $party->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orWhereHas('owner', fn (Builder $owner) => $owner->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw('next_action_at IS NULL')
            ->orderBy('next_action_at')
            ->orderByDesc('id');
    }

    private function scope(Request $request, Opportunity $opportunity): void { abort_unless((int) $opportunity->branch_id === $this->branchId($request), 404); }
    private function branchId(Request $request): int { return (int) $request->attributes->get('selectedBranch')->id; }
}
