<?php

declare(strict_types=1);

namespace App\Enums;

enum TableSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Abandoned = 'abandoned';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
