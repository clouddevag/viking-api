<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OptionGroupKind;
use App\Enums\OptionSelection;
use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string $name
 * @property-read string|null $description
 */
class OptionGroup extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;

    /** @var array<int, string> */
    protected array $localized = ['name', 'description'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => OptionGroupKind::class,
            'selection' => OptionSelection::class,
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'min_selections' => 'integer',
            'max_selections' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'option_group_product')->withPivot('sort_order');
    }

    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Groups not bound to one product are reusable across the menu. */
    public function scopeLibrary(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }

    /**
     * Effective lower bound on selections: a required group always needs at
     * least one, even if `min_selections` was left at zero.
     */
    public function effectiveMinSelections(): int
    {
        return $this->is_required ? max(1, (int) $this->min_selections) : (int) $this->min_selections;
    }

    /** Single-choice groups can never take more than one option. */
    public function effectiveMaxSelections(): int
    {
        return $this->selection === OptionSelection::Single
            ? 1
            : max(1, (int) $this->max_selections);
    }
}
