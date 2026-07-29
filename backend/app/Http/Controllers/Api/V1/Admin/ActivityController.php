<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail. Read-only by design — an audit log that can be edited is
 * not an audit log.
 */
class ActivityController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::ACTIVITY_VIEW);

        $query = Activity::query()
            ->with('causer:id,name')
            ->when($request->query('log_name'), fn ($q, $name) => $q->where('log_name', $name))
            ->when($request->query('subject_type'), fn ($q, $type) => $q->where('subject_type', $type))
            ->when($request->integer('causer_id'), fn ($q, $id) => $q->where('causer_id', $id))
            ->when($request->query('search'), fn ($q, $term) => $q->where('description', 'like', '%'.$term.'%'));

        $this->applyDateRange($query, $request, 'created_at');

        $activities = $query->latest()->paginate($this->perPage($request, 40, 100));

        return response()->json([
            'data' => $activities->getCollection()->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'causer' => $activity->causer?->name ?? 'System',
                'causer_id' => $activity->causer_id,
                'changes' => $activity->properties,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'log_names' => Activity::query()->distinct()->pluck('log_name')->filter()->values(),
            ],
        ]);
    }
}
