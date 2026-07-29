<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CartLineInput;
use App\Data\CartOptionInput;
use App\Enums\DiscountType;
use App\Enums\OptionGroupKind;
use App\Enums\OptionSelection;
use App\Enums\OrderType;
use App\Exceptions\CartValidationException;
use App\Exceptions\CouponException;
use App\Models\Coupon;
use App\Models\Offer;
use App\Services\Orders\CartPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cart pricing is where money is decided, so these tests are the ones that
 * matter most — a bug here silently overcharges or undercharges every order.
 */
class CartPricingTest extends TestCase
{
    use RefreshDatabase;

    private CartPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->pricing = app(CartPricingService::class);
    }

    public function test_it_prices_a_simple_line_from_the_base_price(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 12000]);

        $cart = $this->pricing->price(
            [new CartLineInput($product->id, 2)],
            $branch,
        );

        $this->assertSame(24000.0, $cart->subtotal);
        $this->assertSame(24000.0, $cart->grandTotal);
        $this->assertSame(2, $cart->itemCount());
    }

    public function test_it_adds_option_price_deltas_per_unit(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 10000]);

        $group = $this->makeOptionGroup($product, [['Single', 0], ['Double', 5000]]);
        $double = $group->options->firstWhere('name_en', 'Double');

        $cart = $this->pricing->price(
            [new CartLineInput($product->id, 3, [new CartOptionInput($double->id)])],
            $branch,
        );

        // (10000 + 5000) × 3 — the delta applies to each unit, not the line.
        $this->assertSame(45000.0, $cart->subtotal);
    }

    public function test_it_rejects_a_cart_missing_a_required_option(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();
        $this->makeOptionGroup($product, [['Single', 0]], ['is_required' => true]);

        $this->expectException(CartValidationException::class);

        $this->pricing->price([new CartLineInput($product->id, 1)], $branch);
    }

    public function test_it_rejects_more_selections_than_a_group_allows(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();

        $group = $this->makeOptionGroup(
            $product,
            [['Cheese', 1000], ['Bacon', 2000], ['Egg', 1500]],
            [
                'kind' => OptionGroupKind::Addon,
                'selection' => OptionSelection::Multiple,
                'is_required' => false,
                'min_selections' => 0,
                'max_selections' => 2,
            ],
        );

        $this->expectException(CartValidationException::class);

        $this->pricing->price(
            [new CartLineInput(
                $product->id,
                1,
                $group->options->map(fn ($option) => new CartOptionInput($option->id))->all(),
            )],
            $branch,
        );
    }

    public function test_it_rejects_an_option_from_another_product(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();
        $other = $this->makeProduct();

        $foreign = $this->makeOptionGroup($other, [['Large', 2000]]);

        $this->expectException(CartValidationException::class);

        $this->pricing->price(
            [new CartLineInput($product->id, 1, [new CartOptionInput($foreign->options->first()->id)])],
            $branch,
        );
    }

    public function test_it_rejects_an_unavailable_product(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['is_available' => false]);

        $this->expectException(CartValidationException::class);

        $this->pricing->price([new CartLineInput($product->id, 1)], $branch);
    }

    public function test_it_merges_identical_lines_into_one(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 5000]);

        $cart = $this->pricing->price(
            [
                new CartLineInput($product->id, 1),
                new CartLineInput($product->id, 2),
            ],
            $branch,
        );

        // One kitchen ticket line of three, not two lines.
        $this->assertCount(1, $cart->lines);
        $this->assertSame(3, $cart->lines[0]->quantity);
        $this->assertSame(15000.0, $cart->subtotal);
    }

    public function test_it_keeps_lines_apart_when_notes_differ(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct();

        $cart = $this->pricing->price(
            [
                new CartLineInput($product->id, 1, [], 'No onion'),
                new CartLineInput($product->id, 1, [], 'Extra spicy'),
            ],
            $branch,
        );

        $this->assertCount(2, $cart->lines);
    }

    public function test_it_applies_a_percentage_coupon_with_a_cap(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 100000]);

        Coupon::create([
            'code' => 'SAVE10',
            'name_en' => '10%',
            'name_ar' => '١٠٪',
            'type' => DiscountType::Percentage,
            'value' => 10,
            'maximum_discount_amount' => 5000,
            'is_active' => true,
        ]);

        $cart = $this->pricing->price(
            [new CartLineInput($product->id, 1)],
            $branch,
            couponCode: 'SAVE10',
        );

        // 10% of 100,000 is 10,000 but the cap holds it to 5,000.
        $this->assertSame(5000.0, $cart->couponDiscount);
        $this->assertSame(95000.0, $cart->grandTotal);
    }

    public function test_it_rejects_a_coupon_below_its_minimum(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 5000]);

        Coupon::create([
            'code' => 'BIG',
            'name_en' => 'Big',
            'name_ar' => 'كبير',
            'type' => DiscountType::Fixed,
            'value' => 1000,
            'minimum_order_amount' => 50000,
            'is_active' => true,
        ]);

        $this->expectException(CouponException::class);

        $this->pricing->price(
            [new CartLineInput($product->id, 1)],
            $branch,
            couponCode: 'BIG',
        );
    }

    public function test_an_invalid_coupon_can_be_tolerated_for_previews(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 5000]);

        // The cart preview endpoint must still return totals when a code is
        // wrong, rather than showing the customer nothing at all.
        $cart = $this->pricing->price(
            [new CartLineInput($product->id, 1)],
            $branch,
            couponCode: 'NOPE',
            throwOnInvalidCoupon: false,
        );

        $this->assertNull($cart->coupon);
        $this->assertSame(5000.0, $cart->grandTotal);
    }

    public function test_an_automatic_offer_discounts_before_the_coupon(): void
    {
        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 20000]);

        $offer = Offer::create([
            'slug' => 'half-off',
            'title_en' => 'Half off',
            'title_ar' => 'نصف السعر',
            'type' => 'discount',
            'discount_type' => DiscountType::Percentage,
            'discount_value' => 50,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $offer->products()->attach($product->id, ['quantity' => 1]);

        $cart = $this->pricing->price([new CartLineInput($product->id, 1)], $branch);

        $this->assertSame(10000.0, $cart->offerDiscount);
        $this->assertSame(10000.0, $cart->grandTotal);
    }

    public function test_delivery_fee_applies_only_to_delivery_orders(): void
    {
        $branch = $this->makeBranch(['delivery_fee' => 3000]);
        $product = $this->makeProduct(['base_price' => 10000]);

        $dineIn = $this->pricing->price([new CartLineInput($product->id, 1)], $branch, OrderType::DineIn);
        $delivery = $this->pricing->price([new CartLineInput($product->id, 1)], $branch, OrderType::Delivery);

        $this->assertSame(0.0, $dineIn->deliveryFee);
        $this->assertSame(3000.0, $delivery->deliveryFee);
        $this->assertSame(13000.0, $delivery->grandTotal);
    }

    public function test_it_applies_tax_and_service_charge_from_config(): void
    {
        config(['viking.tax_percent' => 10, 'viking.service_charge_percent' => 5]);

        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 10000]);

        $cart = $this->pricing->price([new CartLineInput($product->id, 1)], $branch, OrderType::DineIn);

        $this->assertSame(1000.0, $cart->taxTotal);
        // Service charge is dine-in only.
        $this->assertSame(500.0, $cart->serviceCharge);
        $this->assertSame(11500.0, $cart->grandTotal);

        $takeaway = $this->pricing->price([new CartLineInput($product->id, 1)], $branch, OrderType::Takeaway);
        $this->assertSame(0.0, $takeaway->serviceCharge);
    }

    public function test_it_rejects_an_empty_cart(): void
    {
        $this->expectException(CartValidationException::class);

        $this->pricing->price([], $this->makeBranch());
    }

    public function test_it_clamps_quantity_to_the_configured_maximum(): void
    {
        config(['viking.max_item_quantity' => 5]);

        $branch = $this->makeBranch();
        $product = $this->makeProduct(['base_price' => 1000]);

        $cart = $this->pricing->price([new CartLineInput($product->id, 99)], $branch);

        $this->assertSame(5, $cart->lines[0]->quantity);
    }
}
