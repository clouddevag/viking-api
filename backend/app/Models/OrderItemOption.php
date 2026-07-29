<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OptionGroupKind;
use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $group_name
 * @property-read string $option_name
 */
class OrderItemOption extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;

    public $timestamps = false;

    /** @var array<int, string> */
    protected array $localized = ['group_name', 'option_name'];

    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'group_kind' => 'addon',
        'price_delta' => 0,
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'group_kind' => OptionGroupKind::class,
            'price_delta' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }
}
