<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of an individual payment row, as opposed to the order-level
 * {@see PaymentStatus} which is derived from all of them.
 */
enum PaymentRecordStatus: string
{
    case Pending = 'pending';
    case Captured = 'captured';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
