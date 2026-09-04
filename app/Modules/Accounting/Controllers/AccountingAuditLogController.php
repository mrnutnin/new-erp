<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Support\Carbon;
use Yajra\DataTables\Facades\DataTables;

class AccountingAuditLogController extends Controller
{
    public function index(): View
    {
        return view('Accounting::audit.index');
    }

    public function data(Request $request): JsonResponse
    {
        $query = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.action', 'like', 'accounting.%')
            ->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.subject_type', 'audit_logs.subject_id', 'audit_logs.old_values', 'audit_logs.new_values', 'audit_logs.ip_address', 'audit_logs.created_at', 'users.name as user_name', 'users.username']);
        if ($request->filled('action')) $query->where('audit_logs.action', 'like', '%'.$request->string('action')->toString().'%');
        if ($request->filled('user_id') && is_numeric($request->input('user_id'))) $query->where('audit_logs.user_id', (int) $request->input('user_id'));
        if ($request->filled('date_from')) $query->where('audit_logs.created_at', '>=', $request->input('date_from').' 00:00:00');
        if ($request->filled('date_to')) $query->where('audit_logs.created_at', '<=', $request->input('date_to').' 23:59:59');
        return DataTables::query($query)->editColumn('created_at', fn ($row) => Carbon::parse($row->created_at, 'UTC')->setTimezone('Asia/Bangkok')->format('d/m/Y H:i:s'))->editColumn('subject_type', fn ($row) => class_basename($row->subject_type).($row->subject_id ? ' #'.$row->subject_id : ''))->toJson();
    }
}
