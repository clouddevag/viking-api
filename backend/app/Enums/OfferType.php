<?php

declare(strict_types=1);

namespace App\Enums;

enum OfferType: string
{
    case Banner = 'banner';
    case Combo = 'combo';
    case Discount = 'discount';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
