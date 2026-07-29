<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'avatar_url' => $this->avatar_path
                ? Storage::disk(config('filesystems.default'))->url($this->avatar_path)
                : null,
            'branch_id' => $this->branch_id,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'is_active' => $this->is_active,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all()),
            // getAllPermissions() reads the direct relation *and* each role's,
            // so it is only safe once all three are loaded — otherwise
            // preventLazyLoading turns a list request into a 500.
            'permissions' => $this->when(
                $this->hasLoadedPermissions(),
                fn () => $this->getAllPermissions()->pluck('name')->values()->all()
            ),
            'is_staff' => $this->when($this->relationLoaded('roles'), fn () => $this->isStaff()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Whether every relation `getAllPermissions()` touches is already loaded. */
    private function hasLoadedPermissions(): bool
    {
        return $this->relationLoaded('permissions')
            && $this->relationLoaded('roles')
            && $this->roles->every(fn ($role) => $role->relationLoaded('permissions'));
    }
}
