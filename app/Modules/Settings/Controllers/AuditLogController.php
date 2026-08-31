<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class AuditLogController extends Controller
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'remember_token'];

    public function index(): View
    {
        return view('Settings::audit.index');
    }

    public function data(Request $request): JsonResponse
    {
        return DataTables::eloquent($this->auditQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request))
            ->addColumn('occurred_at', fn (AuditLog $log) => $log->created_at->format('d/m/Y H:i:s'))
            ->addColumn('actor', fn (AuditLog $log) => $log->actor_name
                ? $log->actor_name.' · '.$log->actor_username
                : 'System')
            ->addColumn('subject', fn (AuditLog $log) => class_basename($log->subject_type).($log->subject_id ? " #{$log->subject_id}" : ''))
            ->addColumn('before_summary', fn (AuditLog $log) => $this->valuesSummary($log->old_values))
            ->addColumn('after_summary', fn (AuditLog $log) => $this->valuesSummary($log->new_values))
            ->removeColumn('old_values')
            ->removeColumn('new_values')
            ->rawColumns(['before_summary', 'after_summary'])
            ->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->auditQuery();
        $this->applyTableSearch($query, $request);
        $this->applyTableOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Audit"><Table>';
            echo $this->excelRow(['เวลา', 'ผู้ดำเนินการ', 'Action', 'Subject', 'ก่อนแก้ไข', 'หลังแก้ไข']);

            foreach ($query->lazy(500) as $log) {
                echo $this->excelRow([
                    $log->created_at->format('d/m/Y H:i:s'),
                    $log->actor_name ? $log->actor_name.' · '.$log->actor_username : 'System',
                    $log->action,
                    class_basename($log->subject_type).($log->subject_id ? " #{$log->subject_id}" : ''),
                    $this->valuesSummary($log->old_values),
                    $this->valuesSummary($log->new_values),
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'audit-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    private function auditQuery(): Builder
    {
        return AuditLog::query()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.subject_type',
                'audit_logs.subject_id',
                'audit_logs.old_values',
                'audit_logs.new_values',
                'audit_logs.created_at',
                'users.name as actor_name',
                'users.username as actor_username',
            ]);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search) {
            $query->where('audit_logs.action', 'like', "%{$search}%")
                ->orWhere('audit_logs.subject_type', 'like', "%{$search}%")
                ->orWhere('audit_logs.subject_id', 'like', "%{$search}%")
                ->orWhere('users.name', 'like', "%{$search}%")
                ->orWhere('users.username', 'like', "%{$search}%");
        });
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'audit_logs.created_at',
            1 => 'users.name',
            2 => 'audit_logs.action',
            3 => 'audit_logs.subject_type',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'audit_logs.created_at';
        $direction = $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $direction)->orderByDesc('audit_logs.id');
    }

    private function valuesSummary(?array $values): string
    {
        $values = $this->removeSensitiveValues($values ?? []);

        if ($values === []) {
            return '—';
        }

        return Str::limit((string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 500);
    }

    private function removeSensitiveValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = $this->removeSensitiveValues($value);
            }
        }

        return $values;
    }

    /** @param array<int, int|string|null> $values */
    private function excelRow(array $values): string
    {
        $cells = array_map(function (int|string|null $value) {
            $type = is_int($value) ? 'Number' : 'String';
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');

            return "<Cell><Data ss:Type=\"{$type}\">{$escaped}</Data></Cell>";
        }, $values);

        return '<Row>'.implode('', $cells).'</Row>';
    }
}
