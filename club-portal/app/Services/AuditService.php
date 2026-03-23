<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    public static function log(
        string  $action,
        string  $description,
        ?Model  $model   = null,
        ?int    $clubId  = null,
        array   $old     = [],
        array   $new     = []
    ): void {
        $user = auth()->user();

        AuditLog::create([
            'user_id'        => $user?->id,
            'user_name'      => $user?->name ?? 'System',
            'user_role'      => $user?->role ?? 'system',
            'action'         => $action,
            'auditable_type' => $model ? get_class($model) : null,
            'auditable_id'   => $model?->id,
            'club_id'        => $clubId,
            'description'    => $description,
            'old_values'     => $old ?: null,
            'new_values'     => $new ?: null,
            'ip_address'     => request()->ip(),
            'user_agent'     => substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
