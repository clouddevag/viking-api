<?php

declare(strict_types=1);

namespace App\Enums;

enum TableStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Disabled = 'disabled';

    /** A guest may only open a session on a table that is free or already theirs. */
    public function acceptsNewSession(): bool
    {
        return in_array($this, [self::Available, self::Occupied], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
