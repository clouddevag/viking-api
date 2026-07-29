<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The order lifecycle, and the only source of truth for which transitions are
 * legal. Both the API controllers and the kitchen/cashier screens ask this enum
 * rather than hard-coding their own rules.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Statuses this order may move to next.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Preparing, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => [self::Served, self::Completed, self::Cancelled],
            self::Served => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Terminal statuses can never change again. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** Statuses the kitchen display is responsible for. */
    public function isActiveInKitchen(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Preparing, self::Ready], true);
    }

    /** Column on `orders` that records when the order entered this status. */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Pending => 'placed_at',
            self::Confirmed => 'confirmed_at',
            self::Preparing => 'preparing_at',
            self::Ready => 'ready_at',
            self::Served => 'served_at',
            self::Completed => 'completed_at',
            self::Cancelled => 'cancelled_at',
        };
    }

    public function label(string $locale = 'en'): string
    {
        $labels = [
            'en' => [
                'pending' => 'Pending', 'confirmed' => 'Confirmed', 'preparing' => 'Preparing',
                'ready' => 'Ready', 'served' => 'Served', 'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ],
            'ar' => [
                'pending' => 'قيد الانتظار', 'confirmed' => 'مؤكد', 'preparing' => 'قيد التحضير',
                'ready' => 'جاهز', 'served' => 'تم التقديم', 'completed' => 'مكتمل',
                'cancelled' => 'ملغي',
            ],
        ];

        return $labels[$locale][$this->value] ?? $labels['en'][$this->value];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
