<?php

namespace App\Modules\Installer\Services;

use App\Modules\Installer\Models\InstallationSession;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GoLiveService
{
    /** @param array<string, mixed> $summary */
    public function execute(?int $userId, string $ipAddress, array $summary): InstallationSession
    {
        return DB::transaction(function () use ($userId, $ipAddress, $summary): InstallationSession {
            $session = InstallationSession::query()->latest('id')->lockForUpdate()->first();

            if (! $session) {
                throw new RuntimeException('ยังไม่มี Installation Session');
            }

            if ($session->status === 'LIVE') {
                return $session;
            }

            $now = now();
            $session->forceFill([
                'status' => 'LIVE',
                'progress' => 100,
                'completed_at' => $now,
                'go_live_at' => $now,
                'metadata' => [...($session->metadata ?? []), 'go_live_summary' => $summary],
            ])->save();

            DB::table('installation_logs')->insert([
                'installation_session_id' => $session->id,
                'step_code' => 'go_live',
                'action' => 'GO_LIVE',
                'old_value' => json_encode(['status' => 'IN_PROGRESS']),
                'new_value' => json_encode(['status' => 'LIVE', 'summary' => $summary]),
                'status' => 'SUCCESS',
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            $session->steps()->updateOrCreate(['step_code' => 'go_live'], [
                'status' => 'COMPLETED',
                'started_at' => $now,
                'completed_at' => $now,
                'metadata' => ['user_id' => $userId],
            ]);

            return $session->fresh();
        });
    }
}
