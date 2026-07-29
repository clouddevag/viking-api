<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OptionGroupKind;
use App\Enums\OptionSelection;
use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The full bilingual menu.
 *
 * Reusable option groups (sauces, drink upgrades) are created once as library
 * groups with a null `product_id` and attached to many products, while
 * product-defining choices like patty size are owned by their product.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->seedCategories();
        $library = $this->seedLibraryGroups();

        foreach ($this->menu() as $categorySlug => $products) {
            $category = $categories[$categorySlug];

            foreach ($products as $index => $data) {
                $shared = $data['shared_groups'] ?? [];
                $ownGroups = $data['groups'] ?? [];
                unset($data['shared_groups'], $data['groups']);

                $product = Product::updateOrCreate(
                    ['slug' => $data['slug']],
                    array_merge($data, [
                        'category_id' => $category->id,
                        'sort_order' => $index + 1,
                    ])
                );

                $this->seedProductGroups($product, $ownGroups);

                $product->sharedOptionGroups()->sync(
                    collect($shared)
                        ->filter(fn ($key) => isset($library[$key]))
                        ->mapWithKeys(fn ($key, $i) => [$library[$key]->id => ['sort_order' => 50 + $i]])
                        ->all()
                );
            }
        }
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $definitions = [
            ['burgers', 'Burgers', 'برغر', 'Fire-grilled beef, stacked the Viking way.', 'لحم مشوي على النار، بطريقة الفايكنج.', 'flame', '#E2574C'],
            ['chicken', 'Chicken', 'دجاج', 'Crispy, juicy, never dry.', 'مقرمش وطري، ولا يجف أبداً.', 'drumstick', '#D9A441'],
            ['sandwiches', 'Sandwiches', 'سندويشات', 'Hand-pressed on stone-baked bread.', 'محضّرة يدوياً على خبز حجري.', 'sandwich', '#B4763E'],
            ['sides', 'Sides', 'المقبلات', 'Everything that belongs beside the main event.', 'كل ما يليق بجانب الطبق الرئيسي.', 'fries', '#C98A2E'],
            ['salads', 'Salads', 'سلطات', 'Cut fresh through the day.', 'تُحضّر طازجة على مدار اليوم.', 'leaf', '#5A8F4A'],
            ['desserts', 'Desserts', 'الحلويات', 'A sweet ending, Nordic style.', 'ختام حلو على الطريقة الشمالية.', 'cake', '#9C5B8F'],
            ['drinks', 'Drinks', 'المشروبات', 'Cold, fizzy, or freshly pressed.', 'باردة، فوارة، أو طازجة.', 'cup', '#3C7A93'],
        ];

        $categories = [];

        foreach ($definitions as $index => [$slug, $nameEn, $nameAr, $descEn, $descAr, $icon, $color]) {
            $categories[$slug] = Category::updateOrCreate(['slug' => $slug], [
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'description_en' => $descEn,
                'description_ar' => $descAr,
                'icon' => $icon,
                'accent_color' => $color,
                'sort_order' => $index + 1,
                'is_featured' => in_array($slug, ['burgers', 'chicken'], true),
            ]);
        }

        return $categories;
    }

    /**
     * Groups shared across many products.
     *
     * @return array<string, OptionGroup>
     */
    private function seedLibraryGroups(): array
    {
        $definitions = [
            'sauces' => [
                'name_en' => 'Extra sauces', 'name_ar' => 'صلصات إضافية',
                'kind' => OptionGroupKind::Addon, 'selection' => OptionSelection::Multiple,
                'is_required' => false, 'min_selections' => 0, 'max_selections' => 4,
                'options' => [
                    ['Viking sauce', 'صلصة الفايكنج', 750],
                    ['Smoked garlic', 'ثوم مدخّن', 750],
                    ['Chilli honey', 'عسل حار', 1000],
                    ['Blue cheese', 'جبنة زرقاء', 1000],
                    ['Classic ketchup', 'كاتشب', 0],
                ],
            ],
            'extras' => [
                'name_en' => 'Add extras', 'name_ar' => 'إضافات',
                'kind' => OptionGroupKind::Addon, 'selection' => OptionSelection::Multiple,
                'is_required' => false, 'min_selections' => 0, 'max_selections' => 5,
                'options' => [
                    ['Extra cheese', 'جبن إضافي', 1500],
                    ['Smoked beef bacon', 'لحم بقري مدخّن', 3000],
                    ['Caramelised onion', 'بصل مكرمل', 1250],
                    ['Jalapeño', 'هالبينو', 1000],
                    ['Fried egg', 'بيضة مقلية', 1500],
                ],
            ],
            'remove' => [
                'name_en' => 'Remove', 'name_ar' => 'بدون',
                'kind' => OptionGroupKind::Addon, 'selection' => OptionSelection::Multiple,
                'is_required' => false, 'min_selections' => 0, 'max_selections' => 6,
                'options' => [
                    ['No onion', 'بدون بصل', 0],
                    ['No pickles', 'بدون مخلل', 0],
                    ['No tomato', 'بدون طماطم', 0],
                    ['No lettuce', 'بدون خس', 0],
                    ['No sauce', 'بدون صلصة', 0],
                ],
            ],
            'make_it_meal' => [
                'name_en' => 'Make it a meal', 'name_ar' => 'اجعلها وجبة',
                'kind' => OptionGroupKind::Addon, 'selection' => OptionSelection::Single,
                'is_required' => false, 'min_selections' => 0, 'max_selections' => 1,
                'options' => [
                    ['Fries + soft drink', 'بطاطا + مشروب غازي', 5000],
                    ['Loaded fries + soft drink', 'بطاطا محمّلة + مشروب غازي', 7500],
                    ['Onion rings + soft drink', 'حلقات بصل + مشروب غازي', 6500],
                ],
            ],
        ];

        $groups = [];

        foreach ($definitions as $key => $definition) {
            $options = $definition['options'];
            unset($definition['options']);

            $group = OptionGroup::updateOrCreate(
                ['product_id' => null, 'name_en' => $definition['name_en']],
                $definition
            );

            foreach ($options as $index => [$nameEn, $nameAr, $price]) {
                Option::updateOrCreate(
                    ['option_group_id' => $group->id, 'name_en' => $nameEn],
                    ['name_ar' => $nameAr, 'price_delta' => $price, 'sort_order' => $index + 1]
                );
            }

            $groups[$key] = $group->load('options');
        }

        return $groups;
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function seedProductGroups(Product $product, array $groups): void
    {
        foreach ($groups as $index => $definition) {
            $options = $definition['options'];
            unset($definition['options']);

            $group = OptionGroup::updateOrCreate(
                ['product_id' => $product->id, 'name_en' => $definition['name_en']],
                array_merge($definition, ['sort_order' => $index + 1])
            );

            foreach ($options as $optionIndex => [$nameEn, $nameAr, $price, $isDefault]) {
                Option::updateOrCreate(
                    ['option_group_id' => $group->id, 'name_en' => $nameEn],
                    [
                        'name_ar' => $nameAr,
                        'price_delta' => $price,
                        'is_default' => $isDefault,
                        'sort_order' => $optionIndex + 1,
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function menu(): array
    {
        $sizeGroup = fn (int $doubleDelta, int $tripleDelta) => [
            'name_en' => 'Patty', 'name_ar' => 'قطعة اللحم',
            'kind' => OptionGroupKind::Variant, 'selection' => OptionSelection::Single,
            'is_required' => true, 'min_selections' => 1, 'max_selections' => 1,
            'options' => [
                ['Single', 'مفردة', 0, true],
                ['Double', 'مزدوجة', $doubleDelta, false],
                ['Triple', 'ثلاثية', $tripleDelta, false],
            ],
        ];

        $doneness = [
            'name_en' => 'Cooked', 'name_ar' => 'درجة الطهي',
            'kind' => OptionGroupKind::Variant, 'selection' => OptionSelection::Single,
            'is_required' => true, 'min_selections' => 1, 'max_selections' => 1,
            'options' => [
                ['Medium', 'متوسطة', 0, true],
                ['Medium well', 'متوسطة ناضجة', 0, false],
                ['Well done', 'ناضجة تماماً', 0, false],
            ],
        ];

        return [
            'burgers' => [
                [
                    'slug' => 'longship-classic', 'sku' => 'BRG-001',
                    'name_en' => 'Longship Classic', 'name_ar' => 'كلاسيك السفينة',
                    'short_description_en' => 'Aged beef, aged cheddar, house pickles.',
                    'short_description_ar' => 'لحم بقري معتّق، شيدر، مخلل البيت.',
                    'description_en' => 'Our founding burger. A 160g chuck-and-brisket patty seared hard on the flat-top, aged cheddar melted under a cloche, house pickles and Viking sauce in a toasted potato bun.',
                    'description_ar' => 'البرغر الأول عندنا. قطعة لحم ١٦٠ غرام من الرقبة والصدر تُشوى على حرارة عالية، مع شيدر معتّق ذائب، مخلل البيت وصلصة الفايكنج داخل خبز البطاطا المحمّص.',
                    'base_price' => 12000, 'compare_at_price' => 14000, 'cost_price' => 5200,
                    'calories' => 780, 'prep_time_minutes' => 12, 'spice_level' => 0,
                    'allergens' => ['gluten', 'dairy', 'egg'], 'tags' => ['bestseller', 'signature'],
                    'is_featured' => true,
                    'groups' => [$sizeGroup(5000, 9500), $doneness],
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'shield-wall-smash', 'sku' => 'BRG-002',
                    'name_en' => 'Shield Wall Smash', 'name_ar' => 'سماش الدرع',
                    'short_description_en' => 'Two thin patties, crisp lacy edges.',
                    'short_description_ar' => 'قطعتان رفيعتان بحواف مقرمشة.',
                    'description_en' => 'Two 90g balls smashed paper-thin so every edge turns to crackling crust, doubled American cheese, diced onion pressed into the meat, mustard and pickles.',
                    'description_ar' => 'كرتان بوزن ٩٠ غرام تُضغطان حتى تصبحا رقيقتين وتتحول حوافهما إلى قشرة مقرمشة، مع جبنة أمريكية مضاعفة وبصل مفروم مضغوط في اللحم، خردل ومخلل.',
                    'base_price' => 13500, 'cost_price' => 5800,
                    'calories' => 840, 'prep_time_minutes' => 11,
                    'allergens' => ['gluten', 'dairy'], 'tags' => ['bestseller'],
                    'is_featured' => true,
                    'groups' => [$sizeGroup(5500, 10500)],
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'ragnar-blue', 'sku' => 'BRG-003',
                    'name_en' => 'Ragnar Blue', 'name_ar' => 'راغنار الأزرق',
                    'short_description_en' => 'Blue cheese, caramelised onion, rocket.',
                    'short_description_ar' => 'جبنة زرقاء، بصل مكرمل، جرجير.',
                    'description_en' => 'For people who like a burger with an argument in it: melted blue cheese, forty-minute caramelised onions, peppery rocket and a black pepper aioli.',
                    'description_ar' => 'لمن يحب برغر بنكهة جريئة: جبنة زرقاء ذائبة، بصل مكرمل لأربعين دقيقة، جرجير حاد وصلصة أيولي بالفلفل الأسود.',
                    'base_price' => 15000, 'cost_price' => 6400,
                    'calories' => 810, 'prep_time_minutes' => 13,
                    'allergens' => ['gluten', 'dairy', 'egg'], 'tags' => ['chef-pick'],
                    'groups' => [$sizeGroup(5500, 10500), $doneness],
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'berserker', 'sku' => 'BRG-004',
                    'name_en' => 'Berserker', 'name_ar' => 'البيرسيركر',
                    'short_description_en' => 'Chilli honey, jalapeño, pepper jack.',
                    'short_description_ar' => 'عسل حار، هالبينو، جبنة حارة.',
                    'description_en' => 'Heat that builds rather than shouts. Chilli honey glaze, pickled and fresh jalapeño, pepper jack, crisp shallots.',
                    'description_ar' => 'حرارة تتصاعد تدريجياً. طبقة عسل حار، هالبينو مخلل وطازج، جبنة حارة، وشالوت مقرمش.',
                    'base_price' => 14500, 'cost_price' => 6100,
                    'calories' => 795, 'prep_time_minutes' => 12, 'spice_level' => 3,
                    'allergens' => ['gluten', 'dairy'], 'tags' => ['spicy'],
                    'is_new' => true,
                    'groups' => [$sizeGroup(5500, 10500), $doneness],
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'shieldmaiden-garden', 'sku' => 'BRG-005',
                    'name_en' => 'Shieldmaiden Garden', 'name_ar' => 'حديقة المحاربة',
                    'short_description_en' => 'Charred mushroom and lentil patty.',
                    'short_description_ar' => 'قرص فطر وعدس مشوي.',
                    'description_en' => 'A properly built vegetarian burger: portobello and black lentil patty with smoked paprika, halloumi, roasted red pepper and herb yoghurt.',
                    'description_ar' => 'برغر نباتي محضّر بعناية: قرص من فطر البورتوبيللو والعدس الأسود مع بابريكا مدخّنة، حلوم، فلفل أحمر مشوي ولبن بالأعشاب.',
                    'base_price' => 11500, 'cost_price' => 4300,
                    'calories' => 560, 'prep_time_minutes' => 11,
                    'allergens' => ['gluten', 'dairy'], 'tags' => ['vegetarian'],
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
            ],

            'chicken' => [
                [
                    'slug' => 'raven-crispy', 'sku' => 'CHK-001',
                    'name_en' => 'Raven Crispy', 'name_ar' => 'الغراب المقرمش',
                    'short_description_en' => 'Buttermilk-brined thigh, double crunch.',
                    'short_description_ar' => 'فخذ منقوع باللبن، قرمشة مضاعفة.',
                    'description_en' => 'Thigh fillet brined twelve hours in spiced buttermilk, dredged twice and fried to order. Slaw, pickles, garlic mayo.',
                    'description_ar' => 'فيليه فخذ منقوع اثنتي عشرة ساعة في لبن متبّل، مغطّى مرتين ويُقلى عند الطلب. مع سلطة كول سلو، مخلل ومايونيز بالثوم.',
                    'base_price' => 11000, 'cost_price' => 4100,
                    'calories' => 690, 'prep_time_minutes' => 14,
                    'allergens' => ['gluten', 'dairy', 'egg'], 'tags' => ['bestseller'],
                    'is_featured' => true,
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'nordic-grilled-chicken', 'sku' => 'CHK-002',
                    'name_en' => 'Nordic Grilled Chicken', 'name_ar' => 'دجاج الشمال المشوي',
                    'short_description_en' => 'Charcoal-grilled breast, herb butter.',
                    'short_description_ar' => 'صدر مشوي على الفحم، زبدة أعشاب.',
                    'description_en' => 'Flattened breast marinated in dill, lemon and garlic, grilled over charcoal and finished with herb butter.',
                    'description_ar' => 'صدر مفرود منقوع بالشبت والليمون والثوم، مشوي على الفحم ومغطّى بزبدة الأعشاب.',
                    'base_price' => 12500, 'cost_price' => 4800,
                    'calories' => 520, 'prep_time_minutes' => 15,
                    'allergens' => ['dairy'], 'tags' => ['grilled'],
                    'shared_groups' => ['sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'thunder-wings', 'sku' => 'CHK-003',
                    'name_en' => 'Thunder Wings', 'name_ar' => 'أجنحة الرعد',
                    'short_description_en' => 'Eight wings, your glaze.',
                    'short_description_ar' => 'ثمانية أجنحة، بالصلصة التي تختارها.',
                    'description_en' => 'Eight wings brined, dried and twice-fried so the skin shatters, then tossed in the glaze you pick.',
                    'description_ar' => 'ثمانية أجنحة منقوعة ومجفّفة ومقلية مرتين حتى تصبح القشرة هشّة، ثم تُقلّب بالصلصة التي تختارها.',
                    'base_price' => 9500, 'cost_price' => 3400,
                    'calories' => 620, 'prep_time_minutes' => 16, 'spice_level' => 2,
                    'allergens' => ['gluten'], 'tags' => ['sharing'],
                    'groups' => [[
                        'name_en' => 'Glaze', 'name_ar' => 'الصلصة',
                        'kind' => OptionGroupKind::Variant, 'selection' => OptionSelection::Single,
                        'is_required' => true, 'min_selections' => 1, 'max_selections' => 1,
                        'options' => [
                            ['Buffalo', 'بافلو', 0, true],
                            ['Chilli honey', 'عسل حار', 0, false],
                            ['Smoked BBQ', 'باربكيو مدخّن', 0, false],
                            ['Garlic parmesan', 'ثوم وبارميزان', 1000, false],
                        ],
                    ]],
                    'shared_groups' => ['sauces'],
                ],
            ],

            'sandwiches' => [
                [
                    'slug' => 'saga-steak-sandwich', 'sku' => 'SND-001',
                    'name_en' => 'Saga Steak Sandwich', 'name_ar' => 'ساندويش الستيك',
                    'short_description_en' => 'Sliced ribeye, melted provolone.',
                    'short_description_ar' => 'شرائح ريب آي، جبنة بروفولون ذائبة.',
                    'description_en' => 'Ribeye rested and sliced thin, provolone melted straight onto the meat, sweet peppers and onions on a stone-baked roll.',
                    'description_ar' => 'ريب آي مرتاح ومقطّع رفيعاً، بروفولون ذائبة فوق اللحم مباشرة، فلفل حلو وبصل داخل خبز حجري.',
                    'base_price' => 16000, 'cost_price' => 7200,
                    'calories' => 730, 'prep_time_minutes' => 14,
                    'allergens' => ['gluten', 'dairy'], 'tags' => ['premium'],
                    'shared_groups' => ['extras', 'sauces', 'remove', 'make_it_meal'],
                ],
                [
                    'slug' => 'harbour-fish-bap', 'sku' => 'SND-002',
                    'name_en' => 'Harbour Fish Bap', 'name_ar' => 'ساندويش سمك الميناء',
                    'short_description_en' => 'Beer-battered cod, tartare, dill.',
                    'short_description_ar' => 'سمك القد المقرمش، صلصة تارتار، شبت.',
                    'description_en' => 'Cod in a light crisp batter, dill tartare, shredded lettuce and a squeeze of lemon in a soft bap.',
                    'description_ar' => 'سمك القد بطبقة مقرمشة خفيفة، صلصة تارتار بالشبت، خس مبشور وعصرة ليمون داخل خبز طري.',
                    'base_price' => 13000, 'cost_price' => 5600,
                    'calories' => 610, 'prep_time_minutes' => 13,
                    'allergens' => ['gluten', 'fish', 'egg'], 'tags' => [],
                    'shared_groups' => ['sauces', 'remove', 'make_it_meal'],
                ],
            ],

            'sides' => [
                [
                    'slug' => 'viking-fries', 'sku' => 'SID-001',
                    'name_en' => 'Viking Fries', 'name_ar' => 'بطاطا الفايكنج',
                    'short_description_en' => 'Triple-cooked, rosemary salt.',
                    'short_description_ar' => 'مقلية ثلاث مرات، بملح إكليل الجبل.',
                    'description_en' => 'Cut thick, cooked three times, finished with rosemary salt.',
                    'description_ar' => 'مقطّعة سميكة، مطهوّة ثلاث مرات، ومتبّلة بملح إكليل الجبل.',
                    'base_price' => 4500, 'cost_price' => 1200,
                    'calories' => 420, 'prep_time_minutes' => 8,
                    'allergens' => [], 'tags' => ['vegan', 'bestseller'],
                    'is_featured' => true,
                    'groups' => [[
                        'name_en' => 'Size', 'name_ar' => 'الحجم',
                        'kind' => OptionGroupKind::Variant, 'selection' => OptionSelection::Single,
                        'is_required' => true, 'min_selections' => 1, 'max_selections' => 1,
                        'options' => [
                            ['Regular', 'عادي', 0, true],
                            ['Large', 'كبير', 2000, false],
                            ['Sharing', 'للمشاركة', 4500, false],
                        ],
                    ]],
                    'shared_groups' => ['sauces'],
                ],
                [
                    'slug' => 'loaded-fries', 'sku' => 'SID-002',
                    'name_en' => 'Loaded Fries', 'name_ar' => 'بطاطا محمّلة',
                    'short_description_en' => 'Cheese sauce, beef, pickled onion.',
                    'short_description_ar' => 'صلصة جبن، لحم، بصل مخلل.',
                    'description_en' => 'Our fries under a blanket of cheese sauce, seasoned beef and quick-pickled red onion.',
                    'description_ar' => 'بطاطانا مغطّاة بصلصة الجبن، لحم متبّل وبصل أحمر مخلل سريعاً.',
                    'base_price' => 8000, 'cost_price' => 2900,
                    'calories' => 690, 'prep_time_minutes' => 10,
                    'allergens' => ['dairy'], 'tags' => ['sharing'],
                    'shared_groups' => ['sauces'],
                ],
                [
                    'slug' => 'onion-rings', 'sku' => 'SID-003',
                    'name_en' => 'Onion Rings', 'name_ar' => 'حلقات البصل',
                    'short_description_en' => 'Thick-cut, beer batter.',
                    'short_description_ar' => 'مقطّعة سميكة بطبقة مقرمشة.',
                    'description_en' => 'Thick rings in a light crisp batter, six to a basket.',
                    'description_ar' => 'حلقات سميكة بطبقة مقرمشة خفيفة، ستة في كل طبق.',
                    'base_price' => 5500, 'cost_price' => 1700,
                    'calories' => 480, 'prep_time_minutes' => 9,
                    'allergens' => ['gluten'], 'tags' => ['vegetarian'],
                    'shared_groups' => ['sauces'],
                ],
                [
                    'slug' => 'mozzarella-sticks', 'sku' => 'SID-004',
                    'name_en' => 'Mozzarella Sticks', 'name_ar' => 'أصابع الموزاريلا',
                    'short_description_en' => 'Six sticks, marinara dip.',
                    'short_description_ar' => 'ستة أصابع مع صلصة مارينارا.',
                    'description_en' => 'Six panko-crumbed sticks with a warm marinara dip.',
                    'description_ar' => 'ستة أصابع مغطّاة ببقسماط البانكو مع صلصة مارينارا دافئة.',
                    'base_price' => 6500, 'cost_price' => 2400,
                    'calories' => 540, 'prep_time_minutes' => 8,
                    'allergens' => ['gluten', 'dairy'], 'tags' => ['vegetarian'],
                ],
            ],

            'salads' => [
                [
                    'slug' => 'north-sea-caesar', 'sku' => 'SAL-001',
                    'name_en' => 'North Sea Caesar', 'name_ar' => 'سيزر بحر الشمال',
                    'short_description_en' => 'Grilled chicken, parmesan, rye croutons.',
                    'short_description_ar' => 'دجاج مشوي، بارميزان، خبز شيلم محمّص.',
                    'description_en' => 'Cos lettuce, grilled chicken, shaved parmesan and rye croutons in a proper anchovy caesar dressing.',
                    'description_ar' => 'خس روماني، دجاج مشوي، بارميزان مبشور وخبز شيلم محمّص مع صلصة سيزر أصلية.',
                    'base_price' => 10500, 'cost_price' => 3800,
                    'calories' => 430, 'prep_time_minutes' => 7,
                    'allergens' => ['gluten', 'dairy', 'fish', 'egg'], 'tags' => [],
                ],
                [
                    'slug' => 'fjord-green', 'sku' => 'SAL-002',
                    'name_en' => 'Fjord Green', 'name_ar' => 'أخضر المضيق',
                    'short_description_en' => 'Cucumber, dill, apple, buttermilk.',
                    'short_description_ar' => 'خيار، شبت، تفاح، لبن.',
                    'description_en' => 'Cucumber ribbons, green apple, dill and toasted seeds in a buttermilk dressing.',
                    'description_ar' => 'شرائح خيار رفيعة، تفاح أخضر، شبت وبذور محمّصة مع صلصة اللبن.',
                    'base_price' => 8500, 'cost_price' => 2600,
                    'calories' => 260, 'prep_time_minutes' => 6,
                    'allergens' => ['dairy'], 'tags' => ['vegetarian', 'light'],
                ],
            ],

            'desserts' => [
                [
                    'slug' => 'burnt-honey-cheesecake', 'sku' => 'DES-001',
                    'name_en' => 'Burnt Honey Cheesecake', 'name_ar' => 'تشيز كيك العسل المحروق',
                    'short_description_en' => 'Basque-style, deeply caramelised.',
                    'short_description_ar' => 'على الطريقة الباسكية، بكراميل غني.',
                    'description_en' => 'Baked hot and fast until the top goes dark, served just warm with burnt honey.',
                    'description_ar' => 'يُخبز على حرارة عالية حتى يصبح سطحه داكناً، ويُقدّم دافئاً مع العسل المحروق.',
                    'base_price' => 7000, 'cost_price' => 2300,
                    'calories' => 520, 'prep_time_minutes' => 5,
                    'allergens' => ['dairy', 'egg', 'gluten'], 'tags' => ['bestseller'],
                    'is_featured' => true,
                ],
                [
                    'slug' => 'chocolate-rune-brownie', 'sku' => 'DES-002',
                    'name_en' => 'Chocolate Rune Brownie', 'name_ar' => 'براوني الرموز',
                    'short_description_en' => 'Warm, fudgy, sea salt.',
                    'short_description_ar' => 'دافئ وطري مع ملح البحر.',
                    'description_en' => 'Dark chocolate brownie served warm with sea salt and vanilla ice cream.',
                    'description_ar' => 'براوني شوكولاتة داكنة يُقدّم دافئاً مع ملح البحر وآيس كريم الفانيليا.',
                    'base_price' => 6500, 'cost_price' => 2100,
                    'calories' => 610, 'prep_time_minutes' => 6,
                    'allergens' => ['dairy', 'egg', 'gluten', 'nuts'], 'tags' => [],
                ],
            ],

            'drinks' => [
                [
                    'slug' => 'house-lemonade', 'sku' => 'DRK-001',
                    'name_en' => 'House Lemonade', 'name_ar' => 'ليموناضة البيت',
                    'short_description_en' => 'Pressed to order, mint.',
                    'short_description_ar' => 'تُعصر عند الطلب، مع النعناع.',
                    'description_en' => 'Lemons pressed to order with mint and a little honey.',
                    'description_ar' => 'ليمون يُعصر عند الطلب مع النعناع وقليل من العسل.',
                    'base_price' => 4000, 'cost_price' => 1100,
                    'calories' => 140, 'prep_time_minutes' => 4,
                    'allergens' => [], 'tags' => ['fresh'],
                    'groups' => [[
                        'name_en' => 'Size', 'name_ar' => 'الحجم',
                        'kind' => OptionGroupKind::Variant, 'selection' => OptionSelection::Single,
                        'is_required' => true, 'min_selections' => 1, 'max_selections' => 1,
                        'options' => [
                            ['Glass', 'كوب', 0, true],
                            ['Jug', 'إبريق', 6000, false],
                        ],
                    ]],
                ],
                [
                    'slug' => 'cola', 'sku' => 'DRK-002',
                    'name_en' => 'Cola', 'name_ar' => 'كولا',
                    'short_description_en' => 'Served over ice.',
                    'short_description_ar' => 'تُقدّم مع الثلج.',
                    'description_en' => 'Chilled cola served over ice with a wedge of lime.',
                    'description_ar' => 'كولا مبرّدة تُقدّم مع الثلج وشريحة ليمون.',
                    'base_price' => 2500, 'cost_price' => 800,
                    'calories' => 160, 'prep_time_minutes' => 2,
                    'allergens' => [], 'tags' => [],
                ],
                [
                    'slug' => 'sparkling-water', 'sku' => 'DRK-003',
                    'name_en' => 'Sparkling Water', 'name_ar' => 'مياه فوارة',
                    'short_description_en' => 'Chilled, 500ml.',
                    'short_description_ar' => 'مبرّدة، ٥٠٠ مل.',
                    'description_en' => 'Chilled sparkling mineral water, 500ml.',
                    'description_ar' => 'مياه معدنية فوارة مبرّدة، ٥٠٠ مل.',
                    'base_price' => 2000, 'cost_price' => 700,
                    'calories' => 0, 'prep_time_minutes' => 1,
                    'allergens' => [], 'tags' => [],
                ],
                [
                    'slug' => 'nordic-berry-shake', 'sku' => 'DRK-004',
                    'name_en' => 'Nordic Berry Shake', 'name_ar' => 'شيك التوت الشمالي',
                    'short_description_en' => 'Lingonberry, vanilla, thick.',
                    'short_description_ar' => 'توت بري، فانيليا، كثيف.',
                    'description_en' => 'Thick vanilla shake blended with tart lingonberry.',
                    'description_ar' => 'شيك فانيليا كثيف ممزوج بالتوت البري الحامض.',
                    'base_price' => 6000, 'cost_price' => 2000,
                    'calories' => 480, 'prep_time_minutes' => 5,
                    'allergens' => ['dairy'], 'tags' => ['bestseller'],
                    'is_new' => true,
                ],
            ],
        ];
    }
}
