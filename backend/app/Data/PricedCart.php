<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Coupon;

/**
 * The fully-costed result of pricing a cart. Every figure here is what will be
 * written onto the order — the checkout endpoint never recalculates.
 */
final readonly class PricedCart
{
    /** @param array<int, PricedLine> $lines */
    public function __construct(
        public array $lines,
        public float $subtotal,
        public float $discountTotal,
        public float $taxTotal,
        public float $serviceCharge,
        public float $deliveryFee,
        public float $grandTotal,
        public string $currency,
        public ?Coupon $coupon = null,
        public float $couponDiscount = 0.0,
        public float $offerDiscount = 0.0,
        public int $estimatedMinutes = 0,
    ) {}

    public function itemCount(): int
    {
        return array_sum(array_map(static fn (PricedLine $line) => $line->quantity, $this->lines));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (PricedLine $line) => $line->toArray(), $this->lines),
            'item_count' => $this->itemCount(),
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal,
            'coupon_discount' => $this->couponDiscount,
            'offer_discount' => $this->offerDiscount,
            'tax_total' => $this->taxTotal,
            'service_charge' => $this->serviceCharge,
            'delivery_fee' => $this->deliveryFee,
            'grand_total' => $this->grandTotal,
            'currency' => $this->currency,
            'estimated_minutes' => $this->estimatedMinutes,
            'coupon' => $this->coupon ? [
                'code' => $this->coupon->code,
                'name' => $this->coupon->name,
                'type' => $this->coupon->type->value,
                'value' => (float) $this->coupon->value,
            ] : null,
        ];
    }
}
