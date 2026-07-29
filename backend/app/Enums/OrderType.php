<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function requiresTable(): bool
    {
        return $this === self::DineIn;
    }

    public function requiresAddress(): bool
    {
        return $this === self::Delivery;
    }

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::DineIn => $locale === 'ar' ? 'داخل المطعم' : 'Dine in',
            self::Takeaway => $locale === 'ar' ? 'طلب خارجي' : 'Takeaway',
            self::Delivery => $locale === 'ar' ? 'توصيل' : 'Delivery',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
