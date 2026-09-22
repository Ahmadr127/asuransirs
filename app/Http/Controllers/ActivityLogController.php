<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::with(['causer', 'subject'])->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                  ->orWhere('log_name', 'ilike', "%{$search}%")
                  ->orWhere('event', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('log_name')) {
            $query->where('log_name', $request->log_name);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->causer_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = in_array((int) $request->input('per_page', 10), [5, 10, 25, 50, 100]) ? (int) $request->input('per_page', 10) : 10;

        $logs = $query->paginate($perPage)->withQueryString();

        $users = User::select('id', 'name')->orderBy('name')->get();
        $logNames = ActivityLog::select('log_name')->distinct()->pluck('log_name');
        $events = ActivityLog::select('event')->distinct()->pluck('event');

        return view('activity-logs.index', compact('logs', 'users', 'logNames', 'events'));
    }

    public function show(ActivityLog $activityLog)
    {
        $activityLog->load(['causer', 'subject']);
        return view('activity-logs.show', compact('activityLog'));
    }

    public function forSubject(Request $request, string $type, int $id)
    {
        // contoh: /activity-logs/subject/user/2  -> tampilkan log untuk user id 2
        $modelClass = match ($type) {
            'user' => \App\Models\User::class,
            'role' => \App\Models\Role::class,
            'organization-unit' => \App\Models\OrganizationUnit::class,
            'organization-type' => \App\Models\OrganizationType::class,
            default => abort(404, 'Unknown subject type')
        };

        $query = ActivityLog::where('subject_type', $modelClass)->where('subject_id', $id)->latest('id');
        $perPage = in_array((int) $request->input('per_page', 10), [5, 10, 25, 50, 100]) ? (int) $request->input('per_page', 10) : 10;
        $logs = $query->paginate($perPage)->withQueryString();
        $subject = $modelClass::findOrFail($id);

        return view('activity-logs.index', compact('logs', 'subject'));
    }
}
