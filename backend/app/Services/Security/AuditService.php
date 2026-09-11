<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    public function record(
        string $action,
        string $module,
        ?string $entityType = null,
        string|int|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?User $actor = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id ?? $request->user()?->id,
            'action' => strtoupper(trim($action)),
            'module' => trim($module),
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'reason' => $reason,
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
