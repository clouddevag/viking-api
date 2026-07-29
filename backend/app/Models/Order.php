<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The order aggregate.
 *
 * Status changes never happen by assignment — they go through
 * {@see \App\Services\Orders\OrderStatusService} so the transition is
 * validated, timestamped, journalled and broadcast as one unit.
 */
class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'manual_discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'guest_count' => 'integer',
            'estimated_minutes' => 'integer',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // Relationships ----------------------------------------------------------

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // Scopes -----------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrderStatus::Pending->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
        ]);
    }

    /** Orders the kitchen still has work on. */
    public function scopeForKitchen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            OrderStatus::Pending->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
        ])->orderBy('placed_at');
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId ? $query->where('branch_id', $branchId) : $query;
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('payment_status', PaymentStatus::Unpaid);
    }

    public function scopePlacedBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('placed_at', [$from, $to]);
    }

    /**
     * Restricts to orders belonging to a specific guest device, used so an
     * anonymous customer can still track and re-open their own orders.
     */
    public function scopeOwnedBy(Builder $query, ?int $userId, ?string $guestToken): Builder
    {
        return $query->where(function (Builder $inner) use ($userId, $guestToken) {
            if ($userId) {
                $inner->orWhere('user_id', $userId);
            }
            if ($guestToken) {
                $inner->orWhere('guest_token', $guestToken);
            }
            // No identity at all must match nothing rather than everything.
            if (! $userId && ! $guestToken) {
                $inner->whereRaw('1 = 0');
            }
        });
    }

    // Derived state ----------------------------------------------------------

    public function isEditable(): bool
    {
        return in_array($this->status, [OrderStatus::Pending, OrderStatus::Confirmed], true);
    }

    /** Total actually captured across all successful payments. */
    public function paidAmount(): float
    {
        return (float) $this->payments()->where('status', 'captured')->sum('amount');
    }

    public function outstandingAmount(): float
    {
        return round(max(0, (float) $this->grand_total - $this->paidAmount() + (float) $this->refunded_total), 2);
    }

    /** Minutes since the order was placed — drives the kitchen ageing colours. */
    public function ageInMinutes(): int
    {
        return (int) ($this->placed_at?->diffInMinutes(now()) ?? 0);
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }
}
