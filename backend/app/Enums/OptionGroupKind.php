<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Variants define the item (size, doneness) and are normally required single
 * choices; add-ons extend it and are optional.
 */
enum OptionGroupKind: string
{
    case Variant = 'variant';
    case Addon = 'addon';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
