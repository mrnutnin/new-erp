<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Modules\Crm\Models\Opportunity;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CustomerTimelineController extends Controller
{
    public function __invoke(Request $request, Party $customer): JsonResponse
    {
        abort_unless($customer->customerRole()->exists(),404);
        $filters = $request->validate(['type' => ['nullable', Rule::in(['ALL', 'ACTIVITY', 'STAGE', 'DOCUMENT'])], 'q' => ['nullable', 'string', 'max:100']]);
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $query = DB::query()->fromSub($this->events($customer->id, $branchId), 'events');
        if (($filters['type'] ?? 'ALL') !== 'ALL') $query->where('event_type', $filters['type']);
        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn (Builder $query) => $query->where('title', 'like', "%{$search}%")->orWhere('detail', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")->orWhere('actor', 'like', "%{$search}%"));
        }
        $events = $query->orderByDesc('event_at')->orderByDesc('sort_id')->paginate(12)
            ->withPath(route('crm.customers.timeline', $customer))->withQueryString();

        return response()->json([
            'html' => view('Crm::customers._timeline', compact('events'))->render(),
            'pagination' => $events->hasPages() ? $events->onEachSide(1)->links('pagination::bootstrap-5')->render() : '',
            'from' => $events->firstItem(), 'to' => $events->lastItem(), 'total' => $events->total(),
        ]);
    }

    private function events(int $partyId, int $branchId): Builder
    {
        $activities = DB::table('crm_activities as activity')->join('crm_opportunities as opportunity', 'opportunity.id', '=', 'activity.opportunity_id')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.created_by')->whereNull('opportunity.deleted_at')
            ->where(['opportunity.party_id' => $partyId, 'opportunity.branch_id' => $branchId])
            ->selectRaw("activity.id sort_id, 'ACTIVITY' event_type, activity.created_at event_at, activity.subject title, CONCAT('เพิ่มกิจกรรม ', activity.type) detail, opportunity.title reference, actor_user.name actor, activity.type status");
        $completed = DB::table('crm_activities as activity')->join('crm_opportunities as opportunity', 'opportunity.id', '=', 'activity.opportunity_id')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.assigned_to')->whereNull('opportunity.deleted_at')->whereNotNull('activity.completed_at')
            ->where(['opportunity.party_id' => $partyId, 'opportunity.branch_id' => $branchId])
            ->selectRaw("activity.id sort_id, 'ACTIVITY' event_type, activity.completed_at event_at, activity.subject title, 'ทำกิจกรรมเสร็จแล้ว' detail, opportunity.title reference, actor_user.name actor, 'COMPLETED' status");
        $created = DB::table('crm_opportunities as opportunity')->leftJoin('users as actor_user', 'actor_user.id', '=', 'opportunity.created_by')
            ->whereNull('opportunity.deleted_at')->where(['opportunity.party_id' => $partyId, 'opportunity.branch_id' => $branchId])
            ->selectRaw("opportunity.id sort_id, 'STAGE' event_type, opportunity.created_at event_at, opportunity.title title, 'สร้าง Opportunity' detail, NULL reference, actor_user.name actor, opportunity.stage status");
        $stages = DB::table('audit_logs as audit')->join('crm_opportunities as opportunity', 'opportunity.id', '=', 'audit.subject_id')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'audit.user_id')->whereNull('opportunity.deleted_at')
            ->where('audit.subject_type', Opportunity::class)->where('audit.action', 'crm.opportunity.updated')
            ->where(['opportunity.party_id' => $partyId, 'opportunity.branch_id' => $branchId])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(audit.old_values, '$.stage')) <> JSON_UNQUOTE(JSON_EXTRACT(audit.new_values, '$.stage'))")
            ->selectRaw("audit.id sort_id, 'STAGE' event_type, audit.created_at event_at, opportunity.title title, CONCAT('เปลี่ยนขั้นตอนจาก ', JSON_UNQUOTE(JSON_EXTRACT(audit.old_values, '$.stage')), ' เป็น ', JSON_UNQUOTE(JSON_EXTRACT(audit.new_values, '$.stage'))) detail, NULL reference, actor_user.name actor, JSON_UNQUOTE(JSON_EXTRACT(audit.new_values, '$.stage')) status");

        return $activities->unionAll($completed)->unionAll($created)->unionAll($stages)
            ->unionAll($this->documents('sales_intakes', $partyId, $branchId, 'Sales Intake'))
            ->unionAll($this->documents('sales_rfqs', $partyId, $branchId, 'RFQ'))
            ->unionAll($this->documents('sales_quotations', $partyId, $branchId, 'Quotation'))
            ->unionAll($this->documents('sales_orders', $partyId, $branchId, 'Sales Order'))
            ->unionAll($this->documents('pos_physical_sales', $partyId, $branchId, 'Invoice / Sale'));
    }

    private function documents(string $table, int $partyId, int $branchId, string $label): Builder
    {
        $detail = $table === 'pos_physical_sales'
            ? "CONCAT('ยอดขาย ', FORMAT(document.total_amount, 2), ' บาท · สถานะ ', document.status)"
            : "CONCAT('สร้างเอกสาร สถานะ ', document.status)";

        return DB::table("{$table} as document")->when($table !== 'sales_rfqs', fn (Builder $query) => $query->whereNull('document.deleted_at'))
            ->where(['document.party_id' => $partyId, 'document.branch_id' => $branchId])->selectRaw("document.id sort_id, 'DOCUMENT' event_type, document.created_at event_at, ? title, {$detail} detail, document.document_number reference, NULL actor, document.status status", [$label]);
    }
}
