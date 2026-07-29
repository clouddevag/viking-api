<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Data\CartLineInput;
use App\Data\CartOptionInput;
use App\Data\PricedCart;
use App\Data\PricedLine;
use App\Data\PricedOption;
use App\Enums\OrderType;
use App\Exceptions\CartValidationException;
use App\Exceptions\CouponException;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Services\Coupons\CouponService;
use Illuminate\Support\Collection;

/**
 * Prices a cart server-side.
 *
 * The client's totals are never trusted: it may send product ids, option ids
 * and quantities, and everything else — prices, discounts, tax, the final
 * total — is derived here from current database state. This is the single
 * place where cart maths lives, shared by the "preview totals" endpoint and by
 * checkout, so what the customer is quoted is exactly what gets charged.
 */
class CartPricingService
{
    public function __construct(
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  array<int, CartLineInput>  $inputs
     *
     * @throws CartValidationException
     * @throws CouponException
     */
    public function price(
        array $inputs,
        Branch $branch,
        OrderType $type = OrderType::DineIn,
        ?string $couponCode = null,
        ?int $userId = null,
        ?string $guestToken = null,
        bool $throwOnInvalidCoupon = true,
    ): PricedCart {
        $inputs = $this->mergeDuplicateLines($inputs);

        if ($inputs === []) {
            throw CartValidationException::empty();
        }

        $maxLines = (int) config('viking.max_order_lines', 60);
        if (count($inputs) > $maxLines) {
            throw CartValidationException::tooManyLines($maxLines);
        }

        $products = $this->loadProducts($inputs);
        $lines = [];

        foreach ($inputs as $input) {
            $lines[] = $this->priceLine($input, $products);
        }

        $subtotal = round(array_sum(array_map(
            static fn (PricedLine $line) => $line->lineSubtotal,
            $lines
        )), 2);

        // Automatic offer discounts first, then the manually entered coupon on
        // top of what remains — a coupon should never double-discount an item
        // that is already on promotion.
        [$lines, $offerDiscount] = $this->applyOffers($lines);
        $afterOffers = round($subtotal - $offerDiscount, 2);

        $coupon = null;
        $couponDiscount = 0.0;

        if ($couponCode !== null && $couponCode !== '') {
            try {
                [$coupon, $couponDiscount] = $this->coupons->evaluate(
                    code: $couponCode,
                    lines: $lines,
                    eligibleSubtotal: $afterOffers,
                    userId: $userId,
                    guestToken: $guestToken,
                );
            } catch (CouponException $e) {
                if ($throwOnInvalidCoupon) {
                    throw $e;
                }
            }
        }

        $discountTotal = round($offerDiscount + $couponDiscount, 2);
        $discountedSubtotal = round(max(0, $subtotal - $discountTotal), 2);

        $taxTotal = $this->percentOf($discountedSubtotal, (float) config('viking.tax_percent', 0));
        $serviceCharge = $type === OrderType::DineIn
            ? $this->percentOf($discountedSubtotal, (float) config('viking.service_charge_percent', 0))
            : 0.0;
        $deliveryFee = $type === OrderType::Delivery ? (float) $branch->delivery_fee : 0.0;

        $grandTotal = round($discountedSubtotal + $taxTotal + $serviceCharge + $deliveryFee, 2);

        return new PricedCart(
            lines: $lines,
            subtotal: $subtotal,
            discountTotal: $discountTotal,
            taxTotal: $taxTotal,
            serviceCharge: $serviceCharge,
            deliveryFee: $deliveryFee,
            grandTotal: $grandTotal,
            currency: (string) config('viking.currency', 'IQD'),
            coupon: $coupon,
            couponDiscount: $couponDiscount,
            offerDiscount: $offerDiscount,
            estimatedMinutes: $this->estimatePrepMinutes($lines),
        );
    }

    /**
     * Collapses identical lines so a cart with "Burger x1" twice becomes one
     * line of two, which keeps the kitchen ticket readable.
     *
     * @param  array<int, CartLineInput>  $inputs
     * @return array<int, CartLineInput>
     */
    private function mergeDuplicateLines(array $inputs): array
    {
        $merged = [];

        foreach ($inputs as $input) {
            $key = $input->fingerprint();

            if (isset($merged[$key])) {
                $existing = $merged[$key];
                $merged[$key] = new CartLineInput(
                    productId: $existing->productId,
                    quantity: $existing->quantity + $input->quantity,
                    options: $existing->options,
                    specialInstructions: $existing->specialInstructions,
                );

                continue;
            }

            $merged[$key] = $input;
        }

        $max = (int) config('viking.max_item_quantity', 50);

        return array_values(array_map(
            static fn (CartLineInput $line) => $line->quantity > $max
                ? new CartLineInput($line->productId, $max, $line->options, $line->specialInstructions)
                : $line,
            $merged
        ));
    }

    /**
     * Eager-loads every product and its option groups in two queries rather
     * than N per line.
     *
     * @param  array<int, CartLineInput>  $inputs
     * @return Collection<int, Product>
     */
    private function loadProducts(array $inputs): Collection
    {
        $ids = array_values(array_unique(array_map(
            static fn (CartLineInput $line) => $line->productId,
            $inputs
        )));

        return Product::query()
            ->with([
                'image',
                'ownOptionGroups.options',
                'sharedOptionGroups.options',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Product>  $products
     *
     * @throws CartValidationException
     */
    private function priceLine(CartLineInput $input, Collection $products): PricedLine
    {
        /** @var Product|null $product */
        $product = $products->get($input->productId);

        if (! $product) {
            throw CartValidationException::productMissing($input->productId);
        }

        if (! $product->is_active || ! $product->is_available) {
            throw CartValidationException::productUnavailable($product->name, $product->id);
        }

        $groups = $product->allOptionGroups()->keyBy('id');
        $optionsById = $this->indexOptions($groups);

        $selected = $this->resolveSelections($input->options, $optionsById, $groups, $product);
        $this->assertGroupRules($groups, $selected);

        $optionsTotal = round(array_sum(array_map(
            static fn (PricedOption $option) => $option->total(),
            $selected
        )), 2);

        $unitPrice = round((float) $product->base_price, 2);
        $lineSubtotal = round(($unitPrice + $optionsTotal) * $input->quantity, 2);

        return new PricedLine(
            product: $product,
            quantity: $input->quantity,
            unitPrice: $unitPrice,
            optionsTotal: $optionsTotal,
            lineSubtotal: $lineSubtotal,
            discountTotal: 0.0,
            lineTotal: $lineSubtotal,
            options: $selected,
            specialInstructions: $input->specialInstructions,
            prepTimeMinutes: (int) $product->prep_time_minutes,
        );
    }

    /**
     * @param  Collection<int, OptionGroup>  $groups
     * @return Collection<int, Option>
     */
    private function indexOptions(Collection $groups): Collection
    {
        return $groups
            ->flatMap(static fn (OptionGroup $group) => $group->options)
            ->keyBy('id');
    }

    /**
     * Turns submitted option ids into priced snapshots, rejecting anything
     * that does not belong to this product or is sold out.
     *
     * @param  array<int, CartOptionInput>  $selections
     * @param  Collection<int, Option>  $optionsById
     * @param  Collection<int, OptionGroup>  $groups
     * @return array<int, PricedOption>
     *
     * @throws CartValidationException
     */
    private function resolveSelections(
        array $selections,
        Collection $optionsById,
        Collection $groups,
        Product $product,
    ): array {
        $priced = [];
        $seen = [];

        foreach ($selections as $selection) {
            // Silently ignore a repeated id rather than charging twice.
            if (in_array($selection->optionId, $seen, true)) {
                continue;
            }
            $seen[] = $selection->optionId;

            /** @var Option|null $option */
            $option = $optionsById->get($selection->optionId);

            if (! $option) {
                throw CartValidationException::optionNotAllowed($selection->optionId, $product->id);
            }

            if (! $option->is_available) {
                throw CartValidationException::optionUnavailable($option->name, $option->id);
            }

            /** @var OptionGroup $group */
            $group = $groups->get($option->option_group_id);

            $quantity = min(max(1, $selection->quantity), max(1, (int) $option->max_quantity));

            $priced[] = new PricedOption(
                optionId: $option->id,
                groupNameEn: (string) $group->name_en,
                groupNameAr: (string) $group->name_ar,
                groupKind: $group->kind,
                optionNameEn: (string) $option->name_en,
                optionNameAr: (string) $option->name_ar,
                priceDelta: round((float) $option->price_delta, 2),
                quantity: $quantity,
            );
        }

        return $priced;
    }

    /**
     * Enforces each group's min/max selection rules against what was chosen.
     *
     * @param  Collection<int, OptionGroup>  $groups
     * @param  array<int, PricedOption>  $selected
     *
     * @throws CartValidationException
     */
    private function assertGroupRules(Collection $groups, array $selected): void
    {
        $countsByGroup = [];

        foreach ($selected as $option) {
            $groupId = $groups->first(
                fn (OptionGroup $group) => $group->options->contains('id', $option->optionId)
            )?->id;

            if ($groupId !== null) {
                $countsByGroup[$groupId] = ($countsByGroup[$groupId] ?? 0) + 1;
            }
        }

        foreach ($groups as $group) {
            if (! $group->is_active) {
                continue;
            }

            $chosen = $countsByGroup[$group->id] ?? 0;
            $min = $group->effectiveMinSelections();
            $max = $group->effectiveMaxSelections();

            if ($chosen < $min) {
                throw CartValidationException::selectionsTooFew($group->name, $min, $group->id);
            }

            if ($chosen > $max) {
                throw CartValidationException::selectionsTooMany($group->name, $max, $group->id);
            }
        }
    }

    /**
     * Applies every live `discount` offer to the lines it covers. Each line
     * takes the single best offer rather than stacking them.
     *
     * @param  array<int, PricedLine>  $lines
     * @return array{0: array<int, PricedLine>, 1: float}
     */
    private function applyOffers(array $lines): array
    {
        $offers = Offer::query()
            ->live()
            ->where('type', 'discount')
            ->whereNotNull('discount_type')
            ->with('products:id')
            ->get();

        if ($offers->isEmpty()) {
            return [$lines, 0.0];
        }

        $total = 0.0;
        $discounted = [];

        foreach ($lines as $line) {
            $best = 0.0;

            foreach ($offers as $offer) {
                $productIds = $offer->products->pluck('id')->all();

                // An offer with no explicit products applies to the whole menu.
                if ($productIds !== [] && ! in_array($line->product->id, $productIds, true)) {
                    continue;
                }

                $amount = $offer->discount_type->computeOn(
                    $line->lineSubtotal,
                    (float) $offer->discount_value
                );

                $best = max($best, $amount);
            }

            $total += $best;
            $discounted[] = $best > 0 ? $line->withDiscount($best) : $line;
        }

        return [$discounted, round($total, 2)];
    }

    /**
     * Kitchen throughput estimate: the slowest item sets the floor, and every
     * additional item adds a little, which models a line cooking in parallel
     * better than either summing or taking the max alone.
     *
     * @param  array<int, PricedLine>  $lines
     */
    private function estimatePrepMinutes(array $lines): int
    {
        if ($lines === []) {
            return 0;
        }

        $slowest = max(array_map(static fn (PricedLine $line) => $line->prepTimeMinutes, $lines));
        $units = array_sum(array_map(static fn (PricedLine $line) => $line->quantity, $lines));

        return (int) max(
            (int) config('viking.kitchen.default_prep_minutes', 10),
            $slowest + (int) floor(max(0, $units - 1) * 1.5)
        );
    }

    private function percentOf(float $amount, float $percent): float
    {
        return $percent <= 0 ? 0.0 : round($amount * ($percent / 100), 2);
    }
}
