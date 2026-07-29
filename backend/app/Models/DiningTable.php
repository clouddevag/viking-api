<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TableSessionStatus;
use App\Enums\TableStatus;
use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * A physical table. The QR code encodes `qr_token`, never the table number, so
 * a printed code cannot be guessed and can be rotated if a sticker leaks.
 *
 * @property-read string|null $name
 */
class DiningTable extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<int, string> */
    protected array $localized = ['name'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
            'is_active' => 'boolean',
            'capacity' => 'integer',
            'qr_rotated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $table) {
            $table->qr_token ??= self::generateToken();
            $table->qr_rotated_at ??= now();
        });
    }

    public static function generateToken(): string
    {
        return Str::lower(Str::random(24));
    }

    /** Issues a fresh token, invalidating every previously printed QR code. */
    public function rotateToken(): string
    {
        $this->forceFill([
            'qr_token' => self::generateToken(),
            'qr_rotated_at' => now(),
        ])->save();

        return $this->qr_token;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class)
            ->where('status', TableSessionStatus::Open->value)
            ->latestOfMany();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return $this->name ?: "#{$this->number}";
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'is_active', 'branch_id', 'qr_rotated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('table');
    }
}
