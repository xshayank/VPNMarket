<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogsController extends Controller
{
    /**
     * Display a listing of audit logs.
     */
    public function index(Request $request)
    {
        // Ensure user is admin
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = AuditLog::query();

        // Filter by action
        if ($request->has('action')) {
            $actions = is_array($request->action) ? $request->action : [$request->action];
            $query->whereIn('action', $actions);
        }

        // Filter by target type
        if ($request->has('target_type')) {
            $query->where('target_type', $request->target_type);
        }

        // Filter by target id
        if ($request->has('target_id')) {
            $query->where('target_id', $request->target_id);
        }

        // Filter by reason
        if ($request->has('reason')) {
            $reasons = is_array($request->reason) ? $request->reason : [$request->reason];
            $query->whereIn('reason', $reasons);
        }

        // Filter by actor id
        if ($request->has('actor_id')) {
            $query->where('actor_id', $request->actor_id);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Pagination
        $perPage = min($request->get('per_page', 50), 100);
        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($logs);
    }
}
