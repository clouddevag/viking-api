<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Setting;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /** All settings, grouped as the admin renders them. */
    public function index(): JsonResponse
    {
        $this->authorizePermission(Permissions::SETTINGS_MANAGE);

        $grouped = Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy('group')
            ->map(fn ($settings) => $settings->map(fn (Setting $setting) => [
                'key' => $setting->key,
                'value' => is_array($setting->value) && array_key_exists('_', $setting->value)
                    ? $setting->value['_']
                    : $setting->value,
                'type' => $setting->type,
                'is_public' => $setting->is_public,
            ])->values());

        return response()->json(['data' => $grouped]);
    }

    /**
     * Bulk update. Only keys that already exist may be written, so the request
     * cannot invent settings the application never reads.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::SETTINGS_MANAGE);

        $validated = $request->validate([
            'settings' => ['required', 'array', 'max:200'],
            'settings.*.key' => ['required', 'string', Rule::exists('settings', 'key')],
            'settings.*.value' => ['present'],
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['settings'] as $row) {
                Setting::query()
                    ->where('key', $row['key'])
                    ->update([
                        'value' => json_encode(['_' => $row['value']]),
                        'type' => gettype($row['value']),
                        'updated_at' => now(),
                    ]);
            }
        });

        Setting::flushCache();
        Cache::forget('viking:bootstrap');

        return response()->json(['message' => 'Settings saved.']);
    }

    // Branches are settings-adjacent, so they live on the same screen.

    public function branches(): JsonResponse
    {
        $this->authorizePermission(Permissions::BRANCHES_MANAGE, Permissions::SETTINGS_MANAGE);

        $branches = Branch::query()->withCount('tables')->orderBy('sort_order')->get();

        return response()->json(['data' => BranchResource::collection($branches)->resolve()]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::BRANCHES_MANAGE);

        $branch = Branch::create($this->validateBranch($request));

        Cache::forget('viking:bootstrap');

        return response()->json([
            'data' => (new BranchResource($branch))->resolve(),
            'message' => 'Branch created.',
        ], 201);
    }

    public function updateBranch(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizePermission(Permissions::BRANCHES_MANAGE);

        $branch->fill($this->validateBranch($request, $branch))->save();

        Cache::forget('viking:bootstrap');

        return response()->json([
            'data' => (new BranchResource($branch->fresh()))->resolve(),
            'message' => 'Branch updated.',
        ]);
    }

    public function destroyBranch(Branch $branch): JsonResponse
    {
        $this->authorizePermission(Permissions::BRANCHES_MANAGE);

        if ($branch->orders()->exists()) {
            return response()->json([
                'message' => 'This branch has orders and cannot be deleted. Deactivate it instead.',
                'error' => 'branch_has_orders',
            ], 422);
        }

        $branch->delete();
        Cache::forget('viking:bootstrap');

        return response()->json(['message' => 'Branch archived.']);
    }

    /** @return array<string, mixed> */
    private function validateBranch(Request $request, ?Branch $branch = null): array
    {
        $required = $branch ? 'sometimes' : 'required';

        return $request->validate([
            'slug' => [$required, 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', Rule::unique('branches', 'slug')->ignore($branch?->id)],
            'name_en' => [$required, 'string', 'max:255'],
            'name_ar' => [$required, 'string', 'max:255'],
            'address_en' => ['nullable', 'string', 'max:1000'],
            'address_ar' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'opens_at' => ['sometimes', 'date_format:H:i,H:i:s'],
            'closes_at' => ['sometimes', 'date_format:H:i,H:i:s'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'accepts_dine_in' => ['sometimes', 'boolean'],
            'accepts_takeaway' => ['sometimes', 'boolean'],
            'accepts_delivery' => ['sometimes', 'boolean'],
            'delivery_fee' => ['sometimes', 'numeric', 'min:0'],
            'minimum_order' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
