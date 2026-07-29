<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * @property-read string $name
 * @property-read string|null $description
 */
class Coupon extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<int, string> */
    protected array $localized = ['name', 'description'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'maximum_discount_amount' => 'decimal:2',
            'usage_limit' => 'integer',
            'usage_limit_per_user' => 'integer',
            'used_count' => 'integer',
            'first_order_only' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $coupon) {
            $coupon->code = strtoupper(trim($coupon->code));
        });
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active and inside its validity window right now. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));
    }

    public function hasStarted(): bool
    {
        return $this->starts_at === null || $this->starts_at->isPast();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasReachedGlobalLimit(): bool
    {
        return $this->usage_limit !== null && $this->used_count >= $this->usage_limit;
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'type', 'value', 'is_active', 'usage_limit', 'expires_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('coupon');
    }
}
