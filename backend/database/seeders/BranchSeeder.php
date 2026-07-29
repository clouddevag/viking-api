<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DiningTable;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'slug' => 'downtown',
                'name_en' => 'Viking Downtown',
                'name_ar' => 'فايكنج المركز',
                'address_en' => 'Al-Karrada Street, Baghdad',
                'address_ar' => 'شارع الكرادة، بغداد',
                'phone' => '+964 770 111 1111',
                'latitude' => 33.3061,
                'longitude' => 44.4269,
                'opens_at' => '11:00:00',
                'closes_at' => '23:59:00',
                'accepts_delivery' => true,
                'delivery_fee' => 3000,
                'minimum_order' => 10000,
                'sort_order' => 1,
                'zones' => ['Main Hall' => 12, 'Terrace' => 6, 'Family' => 6],
            ],
            [
                'slug' => 'riverside',
                'name_en' => 'Viking Riverside',
                'name_ar' => 'فايكنج ضفاف النهر',
                'address_en' => 'Abu Nuwas Corniche, Baghdad',
                'address_ar' => 'كورنيش أبو نؤاس، بغداد',
                'phone' => '+964 770 222 2222',
                'latitude' => 33.3128,
                'longitude' => 44.4212,
                'opens_at' => '12:00:00',
                'closes_at' => '02:00:00',
                'accepts_delivery' => false,
                'delivery_fee' => 0,
                'minimum_order' => 0,
                'sort_order' => 2,
                'zones' => ['Main Hall' => 10, 'Riverside' => 8],
            ],
        ];

        foreach ($branches as $data) {
            $zones = $data['zones'];
            unset($data['zones']);

            $branch = Branch::updateOrCreate(['slug' => $data['slug']], $data);

            $number = 1;

            foreach ($zones as $zone => $count) {
                for ($i = 0; $i < $count; $i++, $number++) {
                    DiningTable::firstOrCreate(
                        ['branch_id' => $branch->id, 'number' => (string) $number],
                        [
                            'zone' => $zone,
                            // A mix of two- and four-tops with the odd large table.
                            'capacity' => $number % 5 === 0 ? 6 : ($number % 3 === 0 ? 2 : 4),
                            'sort_order' => $number,
                        ]
                    );
                }
            }
        }
    }
}
