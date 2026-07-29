<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OptionGroupKind;

/**
 * A chosen option, already resolved to the names and price that will be
 * written onto the order item.
 */
final readonly class PricedOption
{
    public function __construct(
        public int $optionId,
        public string $groupNameEn,
        public string $groupNameAr,
        public OptionGroupKind $groupKind,
        public string $optionNameEn,
        public string $optionNameAr,
        public float $priceDelta,
        public int $quantity,
    ) {}

    public function total(): float
    {
        return round($this->priceDelta * $this->quantity, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'option_id' => $this->optionId,
            'group_name_en' => $this->groupNameEn,
            'group_name_ar' => $this->groupNameAr,
            'group_kind' => $this->groupKind->value,
            'option_name_en' => $this->optionNameEn,
            'option_name_ar' => $this->optionNameAr,
            'price_delta' => $this->priceDelta,
            'quantity' => $this->quantity,
            'total' => $this->total(),
        ];
    }
}
