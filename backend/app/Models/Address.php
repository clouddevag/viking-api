<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Exactly one default address per customer.
        static::saved(function (self $address) {
            if ($address->is_default) {
                static::where('user_id', $address->user_id)
                    ->whereKeyNot($address->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toSingleLine(): string
    {
        return collect([$this->building, $this->street, $this->area, $this->city])
            ->filter()
            ->implode('، ');
    }
}
