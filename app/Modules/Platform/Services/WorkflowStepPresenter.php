<?php

namespace App\Modules\Platform\Services;

final class WorkflowStepPresenter
{
    public static function present(array $step, object $user): array
    {
        $allowed = $user->hasPermission($step['permission']);
        $hasRoute = is_string($step['route'] ?? null) && \Route::has($step['route']);
        $available = $allowed && $hasRoute;
        $runtimeNotReady = $available && (bool) ($step['runtime_not_ready'] ?? false);
        $configurationWarning = $available && (bool) ($step['configuration_warning'] ?? false);
        $pending = $available && (int) ($step['pending_count'] ?? 0) > 0;

        if (! $allowed) {
            $step['status'] = 'ไม่มีสิทธิ์';
            $step['status_code'] = 'NO_PERMISSION';
            $step['status_badge_class'] = 'app-status-neutral';
        } elseif ($runtimeNotReady) {
            $step['status'] = 'ยังไม่พร้อม';
            $step['status_code'] = 'NOT_READY';
            $step['status_badge_class'] = 'app-status-warning';
        } elseif ($configurationWarning) {
            $step['status'] = 'ตรวจ Mapping';
            $step['status_code'] = 'CONFIGURATION_WARNING';
            $step['status_badge_class'] = 'app-status-warning';
        } elseif ($pending) {
            $step['status'] = 'มีงานค้าง · '.number_format((int) $step['pending_count']).' รายการ';
            $step['status_code'] = 'PENDING';
            $step['status_badge_class'] = 'app-status-info';
        } elseif ($available) {
            $step['status'] = 'พร้อมทำ';
            $step['status_code'] = 'READY';
            $step['status_badge_class'] = 'app-status-success';
        } else {
            $step['status'] = 'ยังไม่พร้อม';
            $step['status_code'] = 'NOT_READY';
            $step['status_badge_class'] = 'app-status-warning';
        }

        $step['url'] = $available && ! $runtimeNotReady ? route($step['route']) : null;
        $step['block_reason'] = $available && ! $pending && ! $runtimeNotReady && ! $configurationWarning
            ? null
            : ($step['block_reason'] ?? ($allowed
                ? ($pending ? (($step['pending_label'] ?? 'มีรายการค้างที่ต้องตรวจสอบ').' จำนวน '.number_format((int) $step['pending_count']).' รายการ') : 'ยังไม่มีหน้าหรือความสามารถนี้ใน MVP')
                : 'ไม่มีสิทธิ์เข้าถึง'));

        return $step;
    }
}
