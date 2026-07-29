<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property-read string $name
 * @property-read string|null $address
 */
class Branch extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<int, string> */
    protected array $localized = ['name', 'address'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'delivery_fee' => 'decimal:2',
            'minimum_order' => 'decimal:2',
            'accepts_dine_in' => 'boolean',
            'accepts_takeaway' => 'boolean',
            'accepts_delivery' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether the branch is currently taking orders. Ranges that wrap past
     * midnight (e.g. 18:00 → 02:00) are handled by inverting the comparison.
     */
    public function isOpenNow(?Carbon $at = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = ($at ?? Carbon::now($this->timezone))->format('H:i:s');
        $opens = (string) $this->opens_at;
        $closes = (string) $this->closes_at;

        return $opens <= $closes
            ? $now >= $opens && $now <= $closes
            : $now >= $opens || $now <= $closes;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name_en', 'name_ar', 'is_active', 'accepts_delivery', 'delivery_fee'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('branch');
    }
}
