<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\SalesTeam;
use App\Modules\Crm\Requests\StoreActivityRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ActivityController extends Controller
{
    public function data(Request $request, Opportunity $opportunity): JsonResponse
    {
        $this->scope($request, $opportunity);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['OPEN', 'COMPLETED', 'ALL'])],
            'type' => ['nullable', Rule::in(['CALL', 'MEETING', 'TASK', 'NOTE'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $activities = Activity::query()->where('opportunity_id', $opportunity->id)
            ->with(['assignee:id,name,employee_code', 'creator:id,name'])
            ->when(($filters['status'] ?? 'ALL') === 'OPEN', fn (Builder $query) => $query->whereNull('completed_at'))
            ->when(($filters['status'] ?? 'ALL') === 'COMPLETED', fn (Builder $query) => $query->whereNotNull('completed_at'))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('subject', 'like', "%{$search}%")
                ->orWhere('details', 'like', "%{$search}%")
                ->orWhereHas('assignee', fn (Builder $user) => $user->where('name', 'like', "%{$search}%"))))
            ->orderByRaw('completed_at IS NOT NULL')
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(8)->withPath(route('crm.opportunities.activities.data', $opportunity))->withQueryString();

        return response()->json([
            'html' => view('Crm::opportunities._activity-cards', compact('activities', 'opportunity'))->render(),
            'pagination' => $activities->hasPages() ? $activities->onEachSide(1)->links('pagination::bootstrap-5')->render() : '',
            'from' => $activities->firstItem(), 'to' => $activities->lastItem(), 'total' => $activities->total(),
        ]);
    }

    public function store(StoreActivityRequest $request, Opportunity $opportunity, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $opportunity);
        $this->workScope($request, $opportunity);
        $this->validateAssignee($request, $request->validated('assigned_to'));
        $activity = DB::transaction(function () use ($request, $opportunity, $audit): Activity {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $activity = $opportunity->activities()->create([...$request->validated(), 'assigned_to' => $request->validated('assigned_to') ?: $request->user()->id, 'created_by' => $request->user()->id]);
            $this->syncNextAction($opportunity, $request);
            $audit->record('crm.activity.created', $activity, [], $activity->toArray(), $request->user(), $request);
            return $activity;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่มกิจกรรมแล้ว', 'activity_id' => $activity->id]);
    }

    public function update(StoreActivityRequest $request, Opportunity $opportunity, Activity $activity, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $opportunity);
        $this->validateActivity($opportunity, $activity);
        $this->workScope($request, $opportunity, $activity);
        $this->validateAssignee($request, $request->validated('assigned_to'));
        DB::transaction(function () use ($request, $opportunity, $activity, $audit): void {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $activity = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            throw_if($activity->completed_at, ValidationException::withMessages(['activity' => 'งานที่เสร็จแล้วไม่สามารถแก้ไขได้']));
            $before = $activity->toArray();
            $activity->update([...$request->validated(), 'assigned_to' => $request->validated('assigned_to') ?: $request->user()->id]);
            $this->syncNextAction($opportunity, $request);
            $audit->record('crm.activity.updated', $activity, $before, $activity->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขกิจกรรมแล้ว']);
    }

    public function reschedule(Request $request, Opportunity $opportunity, Activity $activity, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $opportunity);
        $this->validateActivity($opportunity, $activity);
        $this->workScope($request, $opportunity, $activity);
        $data = $request->validate(['due_at' => ['required', 'date_format:Y-m-d\\TH:i']]);
        DB::transaction(function () use ($request, $opportunity, $activity, $audit, $data): void {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $activity = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            throw_if($activity->completed_at, ValidationException::withMessages(['activity' => 'งานที่เสร็จแล้วไม่สามารถเลื่อนได้']));
            $before = $activity->toArray();
            $activity->update(['due_at' => $data['due_at']]);
            $this->syncNextAction($opportunity, $request);
            $audit->record('crm.activity.rescheduled', $activity, $before, $activity->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'เลื่อนเวลากิจกรรมแล้ว']);
    }

    public function complete(Request $request, Opportunity $opportunity, Activity $activity, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $opportunity);
        $this->validateActivity($opportunity, $activity);
        $this->workScope($request, $opportunity, $activity);
        DB::transaction(function () use ($request, $opportunity, $activity, $audit): void {
            $opportunity = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $activity = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            if (! $activity->completed_at) {
                $before = $activity->toArray();
                $activity->update(['completed_at' => now()]);
                $this->syncNextAction($opportunity, $request);
                $audit->record('crm.activity.completed', $activity, $before, $activity->fresh()->toArray(), $request->user(), $request);
            }
        });

        return response()->json(['status' => true, 'msg' => 'ทำกิจกรรมเสร็จแล้ว']);
    }

    private function validateActivity(Opportunity $opportunity, Activity $activity): void
    {
        abort_unless((int) $activity->opportunity_id === (int) $opportunity->id, 404);
    }

    private function validateAssignee(Request $request, mixed $userId): void
    {
        if (! $userId) return;
        $valid = User::query()->whereKey($userId)->where('is_active', true)->whereHas('branches', fn (Builder $query) => $query->where('branches.id', $request->attributes->get('selectedBranch')->id))->exists();
        throw_unless($valid, ValidationException::withMessages(['assigned_to' => 'ผู้รับผิดชอบต้องเป็นผู้ใช้งานในสาขาปัจจุบัน']));
        if (! $request->user()->hasPermission('crm.team-work.view-all')) {
            $allowed = SalesTeam::query()->where('branch_id', $request->attributes->get('selectedBranch')->id)->where('is_active', true)->where('manager_id', $request->user()->id)->with('members:id')->get()->flatMap(fn (SalesTeam $team) => $team->members->pluck('id'))->push($request->user()->id);
            throw_unless($allowed->contains((int) $userId), ValidationException::withMessages(['assigned_to' => 'ผู้รับผิดชอบอยู่นอกขอบเขตทีมของคุณ']));
        }
    }

    private function syncNextAction(Opportunity $opportunity, Request $request): void
    {
        $opportunity->update(['next_action_at' => $opportunity->activities()->whereNull('completed_at')->whereNotNull('due_at')->min('due_at'), 'updated_by' => $request->user()->id]);
    }

    private function workScope(Request $request, Opportunity $opportunity, ?Activity $activity = null): void
    {
        if ($request->user()->hasPermission('crm.team-work.view-all')) return;
        $memberIds = SalesTeam::query()->where('branch_id', $opportunity->branch_id)->where('is_active', true)->where('manager_id', $request->user()->id)->with('members:id')->get()->flatMap(fn (SalesTeam $team) => $team->members->pluck('id'))->push($request->user()->id)->map(fn ($id) => (int) $id)->unique();
        abort_unless($memberIds->contains((int) ($activity?->assigned_to ?: $opportunity->owner_id)), 403);
    }

    private function scope(Request $request, Opportunity $opportunity): void
    {
        abort_unless((int) $opportunity->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
    }
}
