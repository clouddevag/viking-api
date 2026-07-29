<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderItemStatus;
use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line on an order. Names and prices are snapshots — the `product`
 * relationship exists only for reporting and may be null once a product is
 * deleted.
 *
 * @property-read string $product_name
 */
class OrderItem extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;

    /** @var array<int, string> */
    protected array $localized = ['product_name'];

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'options_total' => 0,
        'discount_total' => 0,
        'prep_time_minutes' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderItemStatus::class,
            'unit_price' => 'decimal:2',
            'options_total' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
            'prep_time_minutes' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
