<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Club;

class AuditLogController extends Controller
{
    /**
     * Super admin: view all audit logs across every club.
     */
    public function index()
    {
        $clubs = Club::orderBy('name')->get();

        $query = AuditLog::with(['user', 'club'])->latest();

        if ($club_id = request('club_id')) {
            $query->where('club_id', $club_id);
        }
        if ($action = request('action')) {
            $query->where('action', $action);
        }
        if ($from = request('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = request('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        $logs    = $query->paginate(50)->withQueryString();
        $actions = AuditLog::distinct()->pluck('action')->sort()->values();

        return view('admin.audit-logs.index', compact('logs', 'clubs', 'actions'));
    }

    /**
     * Club admin: view audit logs for a specific club.
     */
    public function clubLogs(Club $club)
    {
        // Verify the authenticated admin belongs to this club
        $user = auth()->user();
        if (! $user->isSuperAdmin()) {
            $isMember = $user->clubs()
                ->wherePivot('role', 'admin')
                ->where('clubs.id', $club->id)
                ->exists();
            if (! $isMember) {
                abort(403);
            }
        }

        $query = AuditLog::with(['user'])->where('club_id', $club->id)->latest();

        if ($action = request('action')) {
            $query->where('action', $action);
        }
        if ($from = request('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = request('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($search = request('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        $logs    = $query->paginate(50)->withQueryString();
        $actions = AuditLog::where('club_id', $club->id)->distinct()->pluck('action')->sort()->values();

        return view('admin.audit-logs.club', compact('logs', 'club', 'actions'));
    }
}
