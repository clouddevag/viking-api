<?php

declare(strict_types=1);

namespace App\Data;

final readonly class CartOptionInput
{
    public function __construct(
        public int $optionId,
        public int $quantity = 1,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            optionId: (int) $payload['option_id'],
            quantity: max(1, (int) ($payload['quantity'] ?? 1)),
        );
    }
}
