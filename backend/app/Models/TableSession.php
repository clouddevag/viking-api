<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TableSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One dining visit at a table. Orders placed during the visit share it, which
 * is what lets the cashier settle a whole table in a single transaction.
 */
class TableSession extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TableSessionStatus::class,
            'party_size' => 'integer',
            'opened_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->session_token ??= Str::lower(Str::random(32));
            $session->opened_at ??= now();
            $session->last_activity_at ??= now();
        });
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TableSessionStatus::Open);
    }

    public function isOpen(): bool
    {
        return $this->status === TableSessionStatus::Open;
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }

    /** Sum of every non-cancelled order placed during this visit. */
    public function runningTotal(): float
    {
        return (float) $this->orders()
            ->whereNotIn('status', ['cancelled'])
            ->sum('grand_total');
    }
}
