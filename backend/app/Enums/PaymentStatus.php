<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function isSettled(): bool
    {
        return $this !== self::Unpaid;
    }

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::Unpaid => $locale === 'ar' ? 'غير مدفوع' : 'Unpaid',
            self::Paid => $locale === 'ar' ? 'مدفوع' : 'Paid',
            self::Refunded => $locale === 'ar' ? 'مسترجع' : 'Refunded',
            self::PartiallyRefunded => $locale === 'ar' ? 'مسترجع جزئياً' : 'Partially refunded',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
