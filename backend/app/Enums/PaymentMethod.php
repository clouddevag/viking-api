<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Online = 'online';
    case Wallet = 'wallet';

    /** Cash is the only method where the cashier tenders and returns change. */
    public function requiresTendering(): bool
    {
        return $this === self::Cash;
    }

    public function label(string $locale = 'en'): string
    {
        return match ($this) {
            self::Cash => $locale === 'ar' ? 'نقداً' : 'Cash',
            self::Card => $locale === 'ar' ? 'بطاقة' : 'Card',
            self::Online => $locale === 'ar' ? 'دفع إلكتروني' : 'Online',
            self::Wallet => $locale === 'ar' ? 'محفظة' : 'Wallet',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
