<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Product;

/**
 * A validated, costed cart line. Carries the live Product so the order writer
 * can snapshot its names, and the already-resolved option snapshots.
 */
final readonly class PricedLine
{
    /** @param array<int, PricedOption> $options */
    public function __construct(
        public Product $product,
        public int $quantity,
        public float $unitPrice,
        public float $optionsTotal,
        public float $lineSubtotal,
        public float $discountTotal,
        public float $lineTotal,
        public array $options = [],
        public ?string $specialInstructions = null,
        public int $prepTimeMinutes = 0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->product->id,
            'name' => $this->product->name,
            'sku' => $this->product->sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'options_total' => $this->optionsTotal,
            'line_subtotal' => $this->lineSubtotal,
            'discount_total' => $this->discountTotal,
            'line_total' => $this->lineTotal,
            'special_instructions' => $this->specialInstructions,
            'options' => array_map(static fn (PricedOption $o) => $o->toArray(), $this->options),
        ];
    }

    /** Same line with a different discount applied, used by the discount pass. */
    public function withDiscount(float $discount): self
    {
        return new self(
            product: $this->product,
            quantity: $this->quantity,
            unitPrice: $this->unitPrice,
            optionsTotal: $this->optionsTotal,
            lineSubtotal: $this->lineSubtotal,
            discountTotal: round($discount, 2),
            lineTotal: round($this->lineSubtotal - $discount, 2),
            options: $this->options,
            specialInstructions: $this->specialInstructions,
            prepTimeMinutes: $this->prepTimeMinutes,
        );
    }
}
