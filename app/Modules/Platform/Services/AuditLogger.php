<?php

namespace App\Modules\Platform\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'remember_token'];

    public function record(
        string $action,
        Model $subject,
        array $oldValues,
        array $newValues,
        ?User $actor,
        Request $request,
    ): void {
        AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'old_values' => Arr::except($oldValues, self::SENSITIVE_KEYS),
            'new_values' => Arr::except($newValues, self::SENSITIVE_KEYS),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
    }
}
