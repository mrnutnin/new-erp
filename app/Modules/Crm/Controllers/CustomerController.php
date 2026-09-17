<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Modules\Crm\Models\Activity;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Controllers\CustomerController as PosCustomerController;
use App\Modules\Pos\Requests\SaveCustomerRequest;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\CustomerAssignment;
use App\Modules\Crm\Models\SalesTeam;
use App\Support\PartyNameNormalizer;
use App\Modules\Crm\Services\Customer360DocumentTrailService;
use App\Modules\Crm\Services\Customer360FinancialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class CustomerController extends Controller
{
    private const OPEN_STAGES = ['NEW', 'CONTACTED', 'QUALIFIED', 'PROPOSAL', 'NEGOTIATION'];

    public function index(Request $request): View
    {
        $filters=$request->validate(['q'=>['nullable','string','max:100'],'status'=>['nullable','in:ACTIVE,INACTIVE']]);
        return view('Crm::customers.index',['search'=>trim((string)($filters['q']??'')),'status'=>$filters['status']??'ACTIVE']);
    }

    public function duplicateReview(Request $request):View
    {
        $filters=$request->validate(['q'=>['nullable','string','max:100'],'type'=>['nullable','in:TAX,PHONE,EMAIL,NAME']]);
        return view('Crm::customers.duplicates',['search'=>trim((string)($filters['q']??'')),'type'=>$filters['type']??'']);
    }

    public function duplicateData(Request $request):JsonResponse
    {
        $filters=$request->validate(['q'=>['nullable','string','max:100'],'type'=>['nullable','in:TAX,PHONE,EMAIL,NAME']]);$search=trim((string)($filters['q']??''));
        $groups=$this->duplicateGroups()->whereRaw("NOT EXISTS (SELECT 1 FROM crm_customer_duplicate_resolutions r WHERE r.signature = SHA2(CONCAT(match_type, ':', member_ids), 256))")->when($filters['type']??null,fn($query,$type)=>$query->where('match_type',$type))->when($search!=='',fn($query)=>$query->where('match_key','like',"%{$search}%"))->orderByRaw("FIELD(match_type,'TAX','PHONE','EMAIL','NAME')")->orderByDesc('total')->orderBy('match_key')->paginate(12)->withPath(route('crm.customers.duplicates'))->withQueryString();
        $ids=$groups->getCollection()->flatMap(fn($group)=>array_slice(array_map('intval',explode(',',$group->member_ids)),0,5))->unique();$customers=Party::withTrashed()->with('customerRole')->whereIn('id',$ids)->get(['id','code','name','tax_id','branch_code','phone','email','is_active','deleted_at'])->keyBy('id');
        $groups->getCollection()->each(function($group)use($customers){$group->members=collect(explode(',',$group->member_ids))->take(5)->map(fn($id)=>$customers->get((int)$id))->filter()->values();});
        return response()->json(['html'=>view('Crm::customers._duplicate-cards',compact('groups'))->render(),'pagination'=>$groups->hasPages()?$groups->onEachSide(1)->links('pagination::bootstrap-5')->render():'','from'=>$groups->firstItem(),'to'=>$groups->lastItem(),'total'=>$groups->total()]);
    }

    public function dismissDuplicate(Request $request,AuditLogger $audit):JsonResponse
    {
        $data=$request->validate(['match_type'=>['required','in:TAX,PHONE,EMAIL,NAME'],'match_key'=>['required','string','max:255']]);
        $group=$this->duplicateGroups()->where('match_type',$data['match_type'])->where('match_key',$data['match_key'])->firstOrFail();
        DB::transaction(function()use($request,$audit,$data,$group):void{$signature=hash('sha256',$data['match_type'].':'.$group->member_ids);DB::table('crm_customer_duplicate_resolutions')->insertOrIgnore(['signature'=>$signature,'match_type'=>$data['match_type'],'match_key'=>$data['match_key'],'resolved_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);$subject=Party::findOrFail((int)explode(',',$group->member_ids)[0]);$audit->record('crm.customer.duplicate-dismissed',$subject,[],['match_type'=>$data['match_type'],'match_key'=>$data['match_key'],'member_ids'=>explode(',',$group->member_ids)],$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'บันทึกว่าไม่ใช่ข้อมูลซ้ำแล้ว']);
    }

    public function mergePreview(Party $target,Party $source):View
    {
        $this->customer($target);$this->customer($source);abort_if($target->is($source),422);
        return view('Crm::customers.merge',['target'=>$target->load('customerRole'),'source'=>$source->load('customerRole'),'blockers'=>$this->mergeBlockers($target,$source),'counts'=>['contacts'=>$source->contacts()->count(),'opportunities'=>Opportunity::withTrashed()->where('party_id',$source->id)->count(),'addresses'=>$source->addresses()->count(),'groups'=>$source->customerGroups()->count()]]);
    }

    public function merge(Request $request,Party $target,Party $source,AuditLogger $audit):JsonResponse
    {
        $this->customer($target);$this->customer($source);abort_if($target->is($source),422);
        DB::transaction(function()use($request,$target,$source,$audit):void{$locked=Party::query()->whereIn('id',[$target->id,$source->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');$target=$locked[$target->id];$source=$locked[$source->id];$blockers=$this->mergeBlockers($target,$source);if($blockers)throw ValidationException::withMessages(['merge'=>implode(' ',$blockers)]);$before=['target_id'=>$target->id,'source_id'=>$source->id,'source_code'=>$source->code];if($target->contacts()->where('is_primary',true)->exists())$source->contacts()->update(['is_primary'=>false]);foreach(['BILLING','SHIPPING'] as $type)if($target->addresses()->where('address_type',$type)->where('is_default',true)->exists())$source->addresses()->where('address_type',$type)->update(['is_default'=>false]);$source->contacts()->update(['party_id'=>$target->id]);$source->addresses()->update(['party_id'=>$target->id]);Opportunity::withTrashed()->where('party_id',$source->id)->update(['party_id'=>$target->id]);CustomerAssignment::query()->where('party_id',$source->id)->lockForUpdate()->get()->each(function(CustomerAssignment $assignment)use($target){if(CustomerAssignment::query()->where('party_id',$target->id)->where('branch_id',$assignment->branch_id)->exists())$assignment->delete();else $assignment->update(['party_id'=>$target->id]);});$target->customerGroups()->syncWithoutDetaching($source->customerGroups()->pluck('pos_customer_groups.id')->all());$source->customerGroups()->detach();$fill=[];foreach(['contact_name','phone','email','address'] as $field)if(blank($target->{$field})&&filled($source->{$field}))$fill[$field]=$source->{$field};if(blank($target->tax_id)&&filled($source->tax_id)){$fill['tax_id']=$source->tax_id;$fill['branch_code']=$source->branch_code;}$target->update([...$fill,'updated_by'=>$request->user()->id]);$source->customerRole()->delete();$source->update(['is_active'=>false,'updated_by'=>$request->user()->id]);$source->delete();$audit->record('crm.customer.merged',$target,$before,['target_id'=>$target->id,'source_id'=>$source->id,'copied_fields'=>array_keys($fill)],$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'รวมข้อมูลลูกค้าแล้ว','redirect'=>route('crm.customers.show',$target)]);
    }

    public function create(): View
    {
        return view('Crm::customers.form',['customer'=>new Party(['type'=>'COMPANY','branch_code'=>'00000'])]);
    }

    public function edit(Party $customer):View
    {
        $this->customer($customer);
        return view('Crm::customers.form',['customer'=>$customer->load('customerRole')]);
    }

    public function duplicateOptions(Request $request,PosCustomerController $customers):JsonResponse
    {
        $data=$customers->quickOptions($request)->getData(true);$ignoreId=$request->integer('exclude_id');
        $data['results']=collect($data['results'])->reject(fn(array $row)=>(int)$row['id']===$ignoreId)->values()->all();
        $normalizedName=PartyNameNormalizer::normalize((string)$request->input('name'));$nameDuplicate=mb_strlen($normalizedName)>=3?Party::withTrashed()->with('customerRole')->whereHas('customerRole')->when($ignoreId,fn(Builder $query)=>$query->where('id','!=',$ignoreId))->where('normalized_name',$normalizedName)->first():null;if($nameDuplicate){$result=['id'=>$nameDuplicate->id,'text'=>$nameDuplicate->code.' · '.$nameDuplicate->name,'hard_match'=>false,'similarity_reason'=>'ชื่อใกล้เคียงหลังตัดคำนำหน้าและรูปแบบนิติบุคคล','can_select'=>!$nameDuplicate->trashed()&&$nameDuplicate->is_active&&$nameDuplicate->customerRole?->is_active];$data['results']=collect($data['results'])->reject(fn(array $row)=>(int)$row['id']===(int)$nameDuplicate->id)->prepend($result)->take(5)->values()->all();}
        if($duplicate=$this->phoneDuplicate((string)$request->input('phone'),$ignoreId)){$result=['id'=>$duplicate->id,'text'=>$duplicate->code.' · '.$duplicate->name,'hard_match'=>true,'can_select'=>!$duplicate->trashed()&&$duplicate->is_active&&$duplicate->customerRole?->is_active];$data['results']=collect($data['results'])->reject(fn(array $row)=>(int)$row['id']===(int)$duplicate->id)->prepend($result)->take(5)->values()->all();}
        $data['hard_match']=collect($data['results'])->contains('hard_match',true);
        return response()->json($data);
    }

    public function store(SaveCustomerRequest $request,PosCustomerController $customers,AuditLogger $audit,DocumentSequenceService $sequences):JsonResponse
    {
        if($duplicate=$this->phoneDuplicate((string)$request->input('phone')))throw ValidationException::withMessages(['phone'=>'เบอร์โทรนี้ถูกใช้โดยลูกค้า '.$duplicate->code.' · '.$duplicate->name.' แล้ว']);
        $response=$customers->store($request,$audit,$sequences);$data=$response->getData(true);$customerId=(int)$data['customer']['id'];
        return response()->json(['status'=>true,'msg'=>$data['msg'],'redirect'=>route('crm.customers.show',$customerId)]);
    }

    public function update(SaveCustomerRequest $request,Party $customer,AuditLogger $audit):JsonResponse
    {
        $this->customer($customer);if($duplicate=$this->phoneDuplicate((string)$request->input('phone'),$customer->id))throw ValidationException::withMessages(['phone'=>'เบอร์โทรนี้ถูกใช้โดยลูกค้า '.$duplicate->code.' · '.$duplicate->name.' แล้ว']);
        DB::transaction(function()use($request,$customer,$audit):void{$customer=Party::query()->lockForUpdate()->findOrFail($customer->id);$data=$request->validated();if($this->hasBusinessHistory($customer)&&($customer->type!==$data['type']||$customer->tax_id!==($data['tax_id']??null)||$customer->branch_code!==$data['branch_code']))throw ValidationException::withMessages(['tax_id'=>'ลูกค้าที่มีประวัติธุรกิจแล้วไม่สามารถเปลี่ยนประเภทหรือข้อมูลภาษีได้']);$before=$customer->only(['name','type','tax_id','branch_code','contact_name','phone','email','address']);$customer->update([...collect($data)->only(array_keys($before))->all(),'updated_by'=>$request->user()->id]);$audit->record('crm.customer.updated',$customer,$before,$customer->fresh()->only(array_keys($before)),$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'แก้ไขข้อมูลลูกค้าแล้ว','redirect'=>route('crm.customers.show',$customer)]);
    }

    public function data(Request $request): JsonResponse
    {
        $filters=$request->validate(['q'=>['nullable','string','max:100'],'status'=>['nullable','in:ACTIVE,INACTIVE']]);
        $search=trim((string)($filters['q']??''));$active=($filters['status']??'ACTIVE')==='ACTIVE';
        $branchId = $this->branchId($request);
        $customers = Party::query()->with('customerRole')->whereHas('customerRole',fn(Builder $query)=>$query->where('is_active',$active))->when($active,fn(Builder $query)=>$query->where('is_active',true))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->addSelect(['open_opportunities_count' => Opportunity::query()->selectRaw('COUNT(*)')->whereColumn('party_id', 'parties.id')->where('branch_id', $branchId)->whereIn('stage', self::OPEN_STAGES)])
            ->addSelect(['pipeline_value' => Opportunity::query()->selectRaw('COALESCE(SUM(expected_value),0)')->whereColumn('party_id', 'parties.id')->where('branch_id', $branchId)->whereIn('stage', self::OPEN_STAGES)])
            ->orderBy('name')->orderBy('id')->paginate(12)->withPath(route('crm.customers.index'))->withQueryString();

        return response()->json([
            'html' => view('Crm::customers._cards', compact('customers'))->render(),
            'pagination' => $customers->hasPages() ? $customers->onEachSide(1)->links('pagination::bootstrap-5')->render() : '',
            'from' => $customers->firstItem(), 'to' => $customers->lastItem(), 'total' => $customers->total(),
        ]);
    }

    public function show(Request $request, Party $customer, Customer360FinancialService $financials, Customer360DocumentTrailService $documentTrails): View
    {
        $this->customer($customer);
        $branchId = $this->branchId($request);
        $opportunities = Opportunity::query()->where('branch_id', $branchId)->where('party_id', $customer->id);
        $open = (clone $opportunities)->whereIn('stage', self::OPEN_STAGES);
        $recentOpportunities = (clone $opportunities)->with('owner:id,name')->orderByDesc('updated_at')->limit(5)->get();
        $activityOpportunities = (clone $open)->orderByDesc('updated_at')->limit(50)->get(['id', 'title', 'owner_id']);
        return view('Crm::customers.show', [
            'customer' => $customer->load(['customerRole','customerGroups:id,code,name','contacts']),
            'financial' => $financials->summary($customer->id, $branchId),
            'documentTrails' => $documentTrails->forCustomer($customer->id, $branchId),
            'openCount' => (clone $open)->count(),
            'pipelineValue' => (string) (clone $open)->sum('expected_value'),
            'wonCount' => (clone $opportunities)->where('stage', 'WON')->count(),
            'pendingActivityCount' => Activity::query()->whereNull('completed_at')->whereHas('opportunity', fn (Builder $query) => $query->where('branch_id', $branchId)->where('party_id', $customer->id))->count(),
            'recentOpportunities' => $recentOpportunities,
            'activityOpportunities' => $activityOpportunities,
            'assignment' => CustomerAssignment::query()->with(['owner:id,name,employee_code','backupOwner:id,name,employee_code','team:id,name'])->where('party_id',$customer->id)->where('branch_id',$branchId)->first(),
            'assignmentTeams' => SalesTeam::query()->where('branch_id',$branchId)->where('is_active',true)->orderBy('name')->get(['id','name']),
        ]);
    }

    public function activate(Request $request,Party $customer,AuditLogger $audit):JsonResponse
    {
        abort_unless($customer->customerRole()->exists(),404);
        DB::transaction(function()use($request,$customer,$audit):void{$customer=Party::query()->lockForUpdate()->findOrFail($customer->id);$role=$customer->customerRole()->lockForUpdate()->firstOrFail();$before=['party_is_active'=>$customer->is_active,'role_is_active'=>$role->is_active];$role->update(['is_active'=>true]);$customer->update(['is_active'=>true,'updated_by'=>$request->user()->id]);$audit->record('crm.customer.activated',$customer,$before,['party_is_active'=>true,'role_is_active'=>true],$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'เปิดใช้งานลูกค้าแล้ว']);
    }

    public function deactivate(Request $request,Party $customer,AuditLogger $audit):JsonResponse
    {
        $this->customer($customer);
        DB::transaction(function()use($request,$customer,$audit):void{$customer=Party::query()->lockForUpdate()->findOrFail($customer->id);$role=$customer->customerRole()->lockForUpdate()->firstOrFail();$before=['party_is_active'=>$customer->is_active,'role_is_active'=>$role->is_active];$role->update(['is_active'=>false]);$active=$customer->roles()->where('is_active',true)->exists();$customer->update(['is_active'=>$active,'updated_by'=>$request->user()->id]);$audit->record('crm.customer.deactivated',$customer,$before,['party_is_active'=>$active,'role_is_active'=>false],$request->user(),$request);});
        return response()->json(['status'=>true,'msg'=>'ปิดใช้งานลูกค้าแล้ว']);
    }

    public function destroy(Request $request,Party $customer,PosCustomerController $customers,AuditLogger $audit):JsonResponse
    {
        $this->customer($customer);
        DB::transaction(function()use($request,$customer,$customers,$audit):void{$customer=Party::query()->lockForUpdate()->findOrFail($customer->id);if($this->hasBusinessHistory($customer))throw ValidationException::withMessages(['customer'=>'ลูกค้ามี Opportunity หรือเอกสารธุรกิจแล้ว กรุณาปิดใช้งานแทน']);$customers->destroy($request,$customer,$audit);});
        return response()->json(['status'=>true,'msg'=>'ลบข้อมูลลูกค้าแล้ว','redirect'=>route('crm.customers.index')]);
    }

    private function hasBusinessHistory(Party $customer):bool
    {
        return Opportunity::withTrashed()->where('party_id',$customer->id)->exists()||$this->hasExternalBusinessHistory($customer);
    }

    private function hasExternalBusinessHistory(Party $customer):bool
    {
        foreach(['sales_intakes','sales_rfqs','sales_quotations','sales_orders','sales_documents','pos_physical_sales','pos_billing_notes','finance_advance_deposits'] as $table)if(DB::table($table)->where('party_id',$customer->id)->exists())return true;
        return DB::table('finance_open_items')->where('party_type','CUSTOMER')->whereIn('party_id',[(string)$customer->id,$customer->code])->exists()||DB::table('finance_settlements')->where('party_type','CUSTOMER')->whereIn('party_id',[(string)$customer->id,$customer->code])->exists();
    }

    private function mergeBlockers(Party $target,Party $source):array
    {
        $blockers=[];
        if($target->type!==$source->type)$blockers[]='ประเภทลูกค้าไม่ตรงกัน';
        if(filled($target->tax_id)&&filled($source->tax_id)&&($target->tax_id!==$source->tax_id||$target->branch_code!==$source->branch_code))$blockers[]='Tax ID หรือสาขาภาษีขัดแย้งกัน';
        if($source->roles()->where('role','!=','CUSTOMER')->exists())$blockers[]='ลูกค้ารองมีบทบาทอื่น เช่น Supplier';
        if($this->hasExternalBusinessHistory($source))$blockers[]='ลูกค้ารองมีเอกสาร POS หรือ Finance แล้ว กรุณาปิดใช้งานแทน';
        return $blockers;
    }

    private function duplicateGroups()
    {
        $base=fn()=>DB::table('parties')->join('party_roles',fn($join)=>$join->on('party_roles.party_id','=','parties.id')->where('party_roles.role','CUSTOMER'))->whereNull('parties.deleted_at');
        $group=fn(string $type,string $expression)=>$base()->selectRaw('? match_type, '.$expression.' match_key, COUNT(*) total, GROUP_CONCAT(parties.id ORDER BY parties.id) member_ids',[$type])->groupByRaw($expression)->havingRaw('COUNT(*) > 1');
        $phone="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(parties.phone, '-', ''), ' ', ''), '(', ''), ')', ''), '+', '')";
        $tax=$group('TAX',"CONCAT(parties.tax_id, '|', parties.branch_code)")->whereNotNull('parties.tax_id')->where('parties.tax_id','!=','');
        $phoneGroups=$group('PHONE',$phone)->whereNotNull('parties.phone')->whereRaw("CHAR_LENGTH({$phone}) >= 6");
        $email=$group('EMAIL','LOWER(TRIM(parties.email))')->whereNotNull('parties.email')->whereRaw("TRIM(parties.email) <> ''");
        $name=$group('NAME','parties.normalized_name')->whereRaw("CHAR_LENGTH(parties.normalized_name) >= 3");
        return DB::query()->fromSub($tax->unionAll($phoneGroups)->unionAll($email)->unionAll($name),'duplicate_groups');
    }

    private function phoneDuplicate(string $phone,int $ignoreId=0):?Party
    {
        $digits=preg_replace('/\D+/','',$phone);if($digits==='')return null;
        return Party::withTrashed()->with('customerRole')->whereHas('customerRole')->when($ignoreId,fn(Builder $query)=>$query->where('id','!=',$ignoreId))->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '(', ''), ')', ''), '+', '') = ?",[$digits])->first();
    }

    private function customer(Party $customer): void
    {
        abort_unless($customer->customerRole()->exists(),404);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }
}
