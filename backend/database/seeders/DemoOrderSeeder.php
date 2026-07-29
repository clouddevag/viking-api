<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\CartLineInput;
use App\Data\CartOptionInput;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\CartPricingService;
use App\Services\Orders\OrderCreationService;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Generates believable order history so the dashboard, reports and kitchen
 * display have something to show on a fresh install.
 *
 * Orders are created through the real services rather than raw inserts, which
 * means the demo data exercises the same pricing and status rules as
 * production traffic — and any regression there shows up immediately.
 */
class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        // Seeding is not a live service: nothing should be broadcast.
        Event::fake();

        $pricing = app(CartPricingService::class);
        $creator = app(OrderCreationService::class);
        $payments = app(PaymentService::class);

        $branch = Branch::where('slug', 'downtown')->first();

        if (! $branch) {
            return;
        }

        $products = Product::with(['ownOptionGroups.options', 'sharedOptionGroups.options'])
            ->available()
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $tables = DiningTable::where('branch_id', $branch->id)->get();
        $customer = User::where('email', 'customer@viking.example')->first();
        $cashier = User::where('email', 'cashier@viking.example')->first();

        // Fourteen days of history, busier at weekends and around meal times.
        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo);
            $isWeekend = in_array($date->dayOfWeek, [4, 5], true);
            $count = $isWeekend ? random_int(9, 16) : random_int(4, 10);

            for ($i = 0; $i < $count; $i++) {
                $placedAt = $date->copy()
                    ->setTime(random_int(11, 22), random_int(0, 59), random_int(0, 59));

                if ($placedAt->isFuture()) {
                    continue;
                }

                $this->makeOrder(
                    $pricing, $creator, $payments,
                    $branch, $products, $tables,
                    $daysAgo === 0 ? null : $placedAt,
                    $customer, $cashier,
                    isHistoric: $daysAgo > 0,
                );
            }
        }
    }

    private function makeOrder(
        CartPricingService $pricing,
        OrderCreationService $creator,
        PaymentService $payments,
        Branch $branch,
        $products,
        $tables,
        $placedAt,
        ?User $customer,
        ?User $cashier,
        bool $isHistoric,
    ): void {
        $lines = [];
        $picked = $products->random(random_int(1, 4));

        foreach ($picked as $product) {
            $options = [];

            // Satisfy every required group, then sometimes add an extra.
            foreach ($product->allOptionGroups() as $group) {
                $available = $group->options->where('is_available', true);

                if ($available->isEmpty()) {
                    continue;
                }

                if ($group->is_required) {
                    $options[] = new CartOptionInput($available->random()->id);
                } elseif (random_int(0, 3) === 0) {
                    $options[] = new CartOptionInput($available->random()->id);
                }
            }

            $lines[] = new CartLineInput(
                productId: $product->id,
                quantity: random_int(1, 3),
                options: $options,
                specialInstructions: random_int(0, 6) === 0 ? 'No cutlery, please.' : null,
            );
        }

        $type = [OrderType::DineIn, OrderType::DineIn, OrderType::Takeaway][random_int(0, 2)];
        $table = $type === OrderType::DineIn && $tables->isNotEmpty() ? $tables->random() : null;
        $useAccount = $customer && random_int(0, 2) === 0;

        try {
            $cart = $pricing->price(
                inputs: $lines,
                branch: $branch,
                type: $type,
                couponCode: random_int(0, 9) === 0 ? 'VIKING10' : null,
                userId: $useAccount ? $customer->id : null,
                throwOnInvalidCoupon: false,
            );
        } catch (\Throwable) {
            // A random cart can legitimately violate a group rule; skip it.
            return;
        }

        $order = $creator->create(
            cart: $cart,
            branch: $branch,
            type: $type,
            attributes: [
                'customer_name' => $useAccount ? $customer->name : $this->guestName(),
                'customer_phone' => '+96477'.random_int(10000000, 99999999),
                'guest_count' => $table ? random_int(1, 4) : null,
            ],
            user: $useAccount ? $customer : null,
            table: $table,
            guestToken: $useAccount ? null : Str::lower(Str::random(32)),
        );

        if ($placedAt) {
            $order->forceFill(['placed_at' => $placedAt, 'created_at' => $placedAt])->save();
        }

        if ($isHistoric) {
            $this->completeHistorically($order, $payments, $cashier);

            return;
        }

        // Today's orders are left spread across the live lanes so the kitchen
        // display has something to work with straight away.
        $this->advanceToLiveStatus($order);
    }

    private function completeHistorically(Order $order, PaymentService $payments, ?User $cashier): void
    {
        $placed = $order->placed_at ?? now();
        $cancelled = random_int(0, 19) === 0;

        if ($cancelled) {
            $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => $placed->copy()->addMinutes(random_int(2, 10)),
                'cancel_reason' => 'Guest changed their mind',
            ])->save();

            return;
        }

        $order->forceFill([
            'status' => OrderStatus::Completed,
            'confirmed_at' => $placed->copy()->addMinutes(1),
            'preparing_at' => $placed->copy()->addMinutes(random_int(2, 5)),
            'ready_at' => $placed->copy()->addMinutes(random_int(9, 22)),
            'served_at' => $placed->copy()->addMinutes(random_int(23, 30)),
            'completed_at' => $placed->copy()->addMinutes(random_int(31, 60)),
        ])->save();

        $payments->capture(
            order: $order,
            method: [PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::Card][random_int(0, 2)],
            amount: (float) $order->grand_total,
            cashier: $cashier,
            tendered: (float) $order->grand_total,
        );

        // A small share of completed orders get refunded, so the refunds report
        // is not empty.
        if (random_int(0, 24) === 0) {
            $payments->refund(
                order: $order,
                amount: round((float) $order->grand_total / 2, 2),
                reason: 'Item arrived cold',
                actor: $cashier,
            );
        }
    }

    private function advanceToLiveStatus(Order $order): void
    {
        $status = [
            OrderStatus::Pending,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::Preparing,
            OrderStatus::Ready,
            OrderStatus::Served,
            OrderStatus::Completed,
        ][random_int(0, 6)];

        if ($status === OrderStatus::Pending) {
            return;
        }

        $placed = $order->placed_at ?? now();
        $updates = ['status' => $status, 'confirmed_at' => $placed->copy()->addMinute()];

        if (in_array($status, [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Served, OrderStatus::Completed], true)) {
            $updates['preparing_at'] = $placed->copy()->addMinutes(3);
        }
        if (in_array($status, [OrderStatus::Ready, OrderStatus::Served, OrderStatus::Completed], true)) {
            $updates['ready_at'] = $placed->copy()->addMinutes(14);
        }
        if (in_array($status, [OrderStatus::Served, OrderStatus::Completed], true)) {
            $updates['served_at'] = $placed->copy()->addMinutes(17);
        }
        if ($status === OrderStatus::Completed) {
            $updates['completed_at'] = $placed->copy()->addMinutes(40);
        }

        $order->forceFill($updates)->save();
    }

    private function guestName(): string
    {
        $names = [
            'Ahmed', 'Layla', 'Omar', 'Zainab', 'Hassan', 'Noor',
            'Yousif', 'Maryam', 'Kareem', 'Sara', 'Mustafa', 'Dina',
        ];

        return $names[array_rand($names)];
    }
}
