<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasLocalizedAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $short_description
 */
class Product extends Model
{
    use HasFactory;
    use HasLocalizedAttributes;
    use LogsActivity;
    use SoftDeletes;

    /** @var array<int, string> */
    protected array $localized = ['name', 'description', 'short_description'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'rating_average' => 'decimal:2',
            'allergens' => 'array',
            'tags' => 'array',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** Option groups defined specifically for this product. */
    public function ownOptionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class)->orderBy('sort_order');
    }

    /** Reusable library groups shared across products. */
    public function sharedOptionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'option_group_product')
            ->withPivot('sort_order')
            ->orderBy('option_group_product.sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class)->withPivot('quantity');
    }

    /**
     * Own groups plus shared ones, merged and sorted as a single list for the
     * product detail screen.
     *
     * @return \Illuminate\Support\Collection<int, OptionGroup>
     */
    public function allOptionGroups()
    {
        return $this->ownOptionGroups
            ->concat($this->sharedOptionGroups)
            ->sortBy('sort_order')
            ->values();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_available', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Bilingual search. Uses MySQL full-text where available and degrades to
     * LIKE on SQLite so the same query object works in tests.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && mb_strlen($term) >= 3) {
            return $query->whereRaw(
                'MATCH(name_en, name_ar, short_description_en, short_description_ar) AGAINST (? IN BOOLEAN MODE)',
                [$this->toBooleanFulltextTerm($term)]
            );
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $inner) use ($like) {
            $inner->where('name_en', 'like', $like)
                ->orWhere('name_ar', 'like', $like)
                ->orWhere('short_description_en', 'like', $like)
                ->orWhere('short_description_ar', 'like', $like)
                ->orWhere('sku', 'like', $like);
        });
    }

    /** Turns a user phrase into a safe prefix-matching boolean-mode query. */
    protected function toBooleanFulltextTerm(string $term): string
    {
        $words = preg_split('/\s+/u', preg_replace('/[+\-><()~*"@]+/u', ' ', $term)) ?: [];

        return collect($words)
            ->filter(fn ($word) => mb_strlen($word) > 1)
            ->map(fn ($word) => $word.'*')
            ->implode(' ');
    }

    public function incrementOrderCount(int $by = 1): void
    {
        // Raw statement so concurrent checkouts cannot clobber each other.
        static::withoutTimestamps(fn () => $this->newQuery()
            ->whereKey($this->getKey())
            ->update(['order_count' => DB::raw("order_count + {$by}")]));
    }

    /** Recomputes the denormalised rating aggregate from approved reviews. */
    public function refreshRating(): void
    {
        $stats = $this->reviews()
            ->where('is_approved', true)
            ->selectRaw('COUNT(*) as total, COALESCE(AVG(rating), 0) as average')
            ->first();

        static::withoutTimestamps(fn () => $this->newQuery()
            ->whereKey($this->getKey())
            ->update([
                'rating_count' => (int) $stats->total,
                'rating_average' => round((float) $stats->average, 2),
            ]));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name_en', 'name_ar', 'base_price', 'category_id', 'is_active', 'is_available'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('product');
    }
}
