<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $name
 */
class Option extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;

    /** @var array<int, string> */
    protected array $localized = ['name'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'max_quantity' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }
}
