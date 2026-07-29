<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Enums\OfferType;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Database\Seeder;

class MarketingSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOffers();
        $this->seedCoupons();
    }

    private function seedOffers(): void
    {
        $offers = [
            [
                'slug' => 'raiding-season',
                'title_en' => 'Raiding Season',
                'title_ar' => 'موسم الغزو',
                'description_en' => 'Two signature burgers and a sharing basket of fries.',
                'description_ar' => 'برغرين مميزين مع طبق بطاطا للمشاركة.',
                'type' => OfferType::Combo,
                'combo_price' => 30000,
                'badge_en' => 'Save 20%',
                'badge_ar' => 'وفّر ٢٠٪',
                'sort_order' => 1,
                'products' => ['longship-classic', 'shield-wall-smash', 'viking-fries'],
            ],
            [
                'slug' => 'midweek-wings',
                'title_en' => 'Midweek Wings',
                'title_ar' => 'أجنحة منتصف الأسبوع',
                'description_en' => '15% off every basket of Thunder Wings.',
                'description_ar' => 'خصم ١٥٪ على كل طبق من أجنحة الرعد.',
                'type' => OfferType::Discount,
                'discount_type' => DiscountType::Percentage,
                'discount_value' => 15,
                'badge_en' => '-15%',
                'badge_ar' => '-١٥٪',
                'sort_order' => 2,
                'products' => ['thunder-wings'],
            ],
            [
                'slug' => 'new-berry-shake',
                'title_en' => 'New: Nordic Berry Shake',
                'title_ar' => 'جديد: شيك التوت الشمالي',
                'description_en' => 'Thick vanilla and tart lingonberry, now on the menu.',
                'description_ar' => 'فانيليا كثيفة وتوت بري حامض، الآن ضمن القائمة.',
                'type' => OfferType::Banner,
                'cta_url' => '/menu/nordic-berry-shake',
                'badge_en' => 'New',
                'badge_ar' => 'جديد',
                'sort_order' => 3,
                'products' => ['nordic-berry-shake'],
            ],
        ];

        foreach ($offers as $data) {
            $slugs = $data['products'];
            unset($data['products']);

            $offer = Offer::updateOrCreate(['slug' => $data['slug']], array_merge($data, [
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(3),
                'is_active' => true,
            ]));

            $offer->products()->sync(
                Product::whereIn('slug', $slugs)->pluck('id')->mapWithKeys(
                    fn ($id) => [$id => ['quantity' => 1]]
                )->all()
            );
        }
    }

    private function seedCoupons(): void
    {
        $coupons = [
            [
                'code' => 'VIKING10',
                'name_en' => '10% off your order',
                'name_ar' => 'خصم ١٠٪ على طلبك',
                'description_en' => 'Ten percent off, up to 5,000 IQD.',
                'description_ar' => 'خصم عشرة بالمئة بحد أقصى ٥٬٠٠٠ دينار.',
                'type' => DiscountType::Percentage,
                'value' => 10,
                'minimum_order_amount' => 15000,
                'maximum_discount_amount' => 5000,
                'usage_limit' => 1000,
                'usage_limit_per_user' => 3,
            ],
            [
                'code' => 'FIRSTRAID',
                'name_en' => '5,000 IQD off your first order',
                'name_ar' => 'خصم ٥٬٠٠٠ دينار على أول طلب',
                'description_en' => 'A welcome discount for new customers.',
                'description_ar' => 'خصم ترحيبي للعملاء الجدد.',
                'type' => DiscountType::Fixed,
                'value' => 5000,
                'minimum_order_amount' => 20000,
                'first_order_only' => true,
                'usage_limit_per_user' => 1,
            ],
            [
                'code' => 'SWEETEND',
                'name_en' => '25% off desserts',
                'name_ar' => 'خصم ٢٥٪ على الحلويات',
                'description_en' => 'A quarter off anything from the dessert menu.',
                'description_ar' => 'ربع الثمن خصماً على قائمة الحلويات.',
                'type' => DiscountType::Percentage,
                'value' => 25,
                'applies_to' => 'categories',
                'usage_limit_per_user' => 5,
            ],
        ];

        foreach ($coupons as $data) {
            $coupon = Coupon::updateOrCreate(['code' => $data['code']], array_merge($data, [
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addMonths(6),
                'is_active' => true,
            ]));

            if (($data['applies_to'] ?? 'all') === 'categories') {
                $coupon->categories()->sync(
                    \App\Models\Category::where('slug', 'desserts')->pluck('id')->all()
                );
            }
        }
    }
}
