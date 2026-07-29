<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->with(['roles.permissions', 'permissions', 'branch:id,name_en,name_ar'])
            ->when($request->query('search'), function ($q, string $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like));
            })
            ->when($request->query('role'), fn ($q, $role) => $q->role($role))
            ->when($request->boolean('staff_only'), fn ($q) => $q->staff())
            ->when($request->boolean('customers_only'), fn ($q) => $q->customers());

        $this->applyFilters($query, $request, ['branch_id', 'is_active']);
        $this->applySorting($query, $request, ['name', 'email', 'created_at', 'last_login_at'], '-created_at');

        return UserResource::collection(
            $query->paginate($this->perPage($request, 25, 100))->withQueryString()
        );
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load(['roles.permissions', 'permissions', 'branch']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->whereNull('deleted_at')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $this->assertMayGrantRoles($request, $validated['roles']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'branch_id' => $validated['branch_id'] ?? null,
            'locale' => $validated['locale'] ?? 'en',
            'is_active' => $validated['is_active'] ?? true,
            'email_verified_at' => now(),
        ]);

        $user->syncRoles($validated['roles']);

        return response()->json([
            'data' => (new UserResource($user->load(['roles.permissions', 'permissions', 'branch'])))->resolve(),
            'message' => 'User created.',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)->whereNull('deleted_at')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($user->id)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        if (isset($validated['roles'])) {
            $this->assertMayGrantRoles($request, $validated['roles']);
        }

        // Deactivating someone must also cut their live sessions, or they keep
        // working until their token happens to expire.
        $deactivating = array_key_exists('is_active', $validated)
            && $validated['is_active'] === false
            && $user->is_active;

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        unset($validated['password'], $validated['roles']);

        $user->fill($validated)->save();

        if ($request->has('roles')) {
            $user->syncRoles($request->input('roles'));
        }

        if ($deactivating) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => (new UserResource($user->fresh(['roles.permissions', 'permissions', 'branch'])))->resolve(),
            'message' => 'User updated.',
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User archived.']);
    }

    /** Roles and the permission catalogue, for the user and role editors. */
    public function roles(): JsonResponse
    {
        $this->authorizePermission(Permissions::USERS_VIEW, Permissions::ROLES_MANAGE);

        return response()->json([
            'data' => [
                'roles' => Role::with('permissions:id,name')->get()->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name')->all(),
                    'users_count' => $role->users()->count(),
                ])->all(),
                'permissions' => Permissions::grouped(),
            ],
        ]);
    }

    /** Updates a role's permission set. */
    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $this->authorizePermission(Permissions::ROLES_MANAGE);

        if (in_array($role->name, ['super-admin', 'customer'], true)) {
            return response()->json([
                'message' => "The \"{$role->name}\" role is managed by the system and cannot be edited.",
                'error' => 'protected_role',
            ], 422);
        }

        $validated = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'data' => [
                'name' => $role->name,
                'permissions' => $role->fresh('permissions')->permissions->pluck('name')->all(),
            ],
            'message' => 'Role updated.',
        ]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::ROLES_MANAGE);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9\-]*$/', Rule::unique('roles', 'name')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);

        return response()->json([
            'data' => ['name' => $role->name, 'permissions' => $validated['permissions'] ?? []],
            'message' => 'Role created.',
        ], 201);
    }

    /**
     * Nobody may hand out a role that outranks their own — otherwise a manager
     * could create an admin and escalate through it.
     *
     * @param  array<int, string>  $roles
     */
    private function assertMayGrantRoles(Request $request, array $roles): void
    {
        $actor = $request->user();

        if ($actor->hasRole('super-admin')) {
            return;
        }

        if (in_array('super-admin', $roles, true)) {
            abort(403, 'You cannot grant the owner role.');
        }

        if (in_array('admin', $roles, true) && ! $actor->hasRole('admin')) {
            abort(403, 'You cannot grant the admin role.');
        }
    }
}
