<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One raw line as submitted by a client, before validation or pricing.
 */
final readonly class CartLineInput
{
    /** @param array<int, CartOptionInput> $options */
    public function __construct(
        public int $productId,
        public int $quantity,
        public array $options = [],
        public ?string $specialInstructions = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $options = array_map(
            static fn (array $option) => CartOptionInput::fromArray($option),
            $payload['options'] ?? []
        );

        return new self(
            productId: (int) $payload['product_id'],
            quantity: max(1, (int) ($payload['quantity'] ?? 1)),
            options: $options,
            specialInstructions: isset($payload['special_instructions'])
                ? trim((string) $payload['special_instructions']) ?: null
                : null,
        );
    }

    /**
     * Two lines with the same product, options and note are the same line and
     * can be merged into one with a summed quantity.
     */
    public function fingerprint(): string
    {
        $optionKeys = array_map(
            static fn (CartOptionInput $option) => "{$option->optionId}x{$option->quantity}",
            $this->options
        );
        sort($optionKeys);

        return md5($this->productId.'|'.implode(',', $optionKeys).'|'.($this->specialInstructions ?? ''));
    }
}
