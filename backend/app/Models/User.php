<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * Both customers and staff live here, told apart by their roles: a customer
 * holds the `customer` role and no branch, while staff are scoped to the
 * branch they work at.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'branch_id', 'name', 'email', 'phone', 'password',
        'avatar_path', 'locale', 'is_active',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Anyone with a role other than `customer` is back-office staff. */
    public function scopeStaff(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', '!=', 'customer'));
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', 'customer'));
    }

    public function isStaff(): bool
    {
        return $this->roles->contains(fn ($role) => $role->name !== 'customer');
    }

    public function isCustomer(): bool
    {
        return ! $this->isStaff();
    }

    /**
     * Whether this user may act on records belonging to a branch. Admins are
     * unscoped; everyone else is confined to their own branch.
     */
    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->hasAnyRole(['super-admin', 'admin'])) {
            return true;
        }

        return $branchId !== null && $this->branch_id === $branchId;
    }

    public function recordLogin(?string $ip): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'branch_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('user');
    }
}
