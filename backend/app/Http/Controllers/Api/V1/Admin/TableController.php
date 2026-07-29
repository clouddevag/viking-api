<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TableStatus;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\DiningTableResource;
use App\Models\DiningTable;
use App\Services\Tables\QrCodeService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Table management and QR code generation.
 */
class TableController extends Controller
{
    use HandlesApiQueries;

    public function __construct(
        private readonly QrCodeService $qr,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permissions::TABLES_VIEW);

        $query = DiningTable::query()
            ->with(['branch', 'activeSession'])
            ->withCount(['orders' => fn ($q) => $q->active()])
            ->when($request->query('zone'), fn ($q, $zone) => $q->where('zone', $zone));

        $this->applyFilters($query, $request, ['branch_id', 'status', 'is_active', 'zone']);
        $this->applySorting($query, $request, ['number', 'sort_order', 'capacity', 'created_at'], 'sort_order');

        return DiningTableResource::collection(
            $query->paginate($this->perPage($request, 50, 200))->withQueryString()
        );
    }

    public function show(DiningTable $table): DiningTableResource
    {
        $this->authorizePermission(Permissions::TABLES_VIEW);

        return new DiningTableResource($table->load(['branch', 'activeSession']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::TABLES_MANAGE);

        $table = DiningTable::create($this->validateTable($request));

        return response()->json([
            'data' => (new DiningTableResource($table->load('branch')))->resolve(),
            'message' => 'Table created.',
        ], 201);
    }

    public function update(Request $request, DiningTable $table): JsonResponse
    {
        $this->authorizePermission(Permissions::TABLES_MANAGE);

        $table->fill($this->validateTable($request, $table))->save();

        return response()->json([
            'data' => (new DiningTableResource($table->fresh('branch')))->resolve(),
            'message' => 'Table updated.',
        ]);
    }

    public function destroy(DiningTable $table): JsonResponse
    {
        $this->authorizePermission(Permissions::TABLES_MANAGE);

        $table->delete();

        return response()->json(['message' => 'Table archived.']);
    }

    /**
     * Creates a block of tables at once, which is how a venue is set up the
     * first time rather than clicking "new table" forty times.
     */
    public function bulkCreate(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::TABLES_MANAGE);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'from' => ['required', 'integer', 'min:1', 'max:9999'],
            'to' => ['required', 'integer', 'min:1', 'max:9999', 'gte:from'],
            'zone' => ['nullable', 'string', 'max:64'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        if ($validated['to'] - $validated['from'] > 300) {
            return response()->json([
                'message' => 'You can create at most 300 tables at a time.',
            ], 422);
        }

        $created = DB::transaction(function () use ($validated) {
            $created = [];

            for ($number = $validated['from']; $number <= $validated['to']; $number++) {
                $exists = DiningTable::where('branch_id', $validated['branch_id'])
                    ->where('number', (string) $number)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $created[] = DiningTable::create([
                    'branch_id' => $validated['branch_id'],
                    'number' => (string) $number,
                    'zone' => $validated['zone'] ?? null,
                    'capacity' => $validated['capacity'] ?? 4,
                    'sort_order' => $number,
                ]);
            }

            return $created;
        });

        return response()->json([
            'data' => DiningTableResource::collection(collect($created))->resolve(),
            'message' => count($created).' table(s) created.',
        ], 201);
    }

    /** Returns the QR as an SVG document, ready to print. */
    public function qrCode(Request $request, DiningTable $table): Response
    {
        $this->authorizePermission(Permissions::TABLES_VIEW);

        $size = max(128, min(2048, $request->integer('size') ?: 512));

        return response($this->qr->svgFor($table, $size), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline; filename="table-'.$table->number.'.svg"',
        ]);
    }

    /**
     * Every table's QR in one payload, for printing a whole venue's table
     * tents in a single pass.
     */
    public function qrSheet(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::TABLES_VIEW);

        $tables = DiningTable::query()
            ->active()
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->with('branch')
            ->orderBy('sort_order')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $tables->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'number' => $table->number,
                'name' => $table->displayName(),
                'zone' => $table->zone,
                'branch' => $table->branch->name,
                'url' => $this->qr->urlFor($table),
                'svg' => $this->qr->svgFor($table, 320),
            ])->all(),
        ]);
    }

    /**
     * Issues a new QR token, which invalidates every code already printed for
     * this table. Used when a sticker is photographed and shared.
     */
    public function rotateQr(DiningTable $table): JsonResponse
    {
        $this->authorizePermission(Permissions::TABLES_MANAGE);

        $table->rotateToken();

        return response()->json([
            'data' => (new DiningTableResource($table->fresh('branch')))->resolve(),
            'message' => 'QR code rotated. Reprint this table\'s code.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validateTable(Request $request, ?DiningTable $table = null): array
    {
        $required = $table ? 'sometimes' : 'required';
        $branchId = $request->input('branch_id', $table?->branch_id);

        return $request->validate([
            'branch_id' => [$required, 'integer', 'exists:branches,id'],
            'number' => [
                $required, 'string', 'max:16',
                Rule::unique('dining_tables', 'number')
                    ->where(fn ($query) => $query->where('branch_id', $branchId))
                    ->ignore($table?->id)
                    ->whereNull('deleted_at'),
            ],
            'name_en' => ['nullable', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'zone' => ['nullable', 'string', 'max:64'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', TableStatus::values())],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
