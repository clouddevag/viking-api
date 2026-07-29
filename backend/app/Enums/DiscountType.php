<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscountType: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    /**
     * Applies this discount to an amount, honouring an optional cap.
     * Rounded to 2dp and never allowed to exceed the amount itself.
     */
    public function computeOn(float $amount, float $value, ?float $maximum = null): float
    {
        $discount = $this === self::Percentage
            ? $amount * ($value / 100)
            : $value;

        if ($maximum !== null) {
            $discount = min($discount, $maximum);
        }

        return round(max(0.0, min($discount, $amount)), 2);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
