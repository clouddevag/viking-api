<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // group, key, value, public
            ['brand', 'restaurant_name_en', 'Viking', true],
            ['brand', 'restaurant_name_ar', 'فايكنج', true],
            ['brand', 'tagline_en', 'Fire-grilled burgers, Nordic appetite.', true],
            ['brand', 'tagline_ar', 'برغر مشوي على النار، بنكهة الشمال.', true],
            ['brand', 'support_phone', '+964 770 000 0000', true],
            ['brand', 'support_email', 'hello@viking.example', true],
            ['brand', 'instagram', 'https://instagram.com', true],

            ['ordering', 'currency', 'IQD', true],
            ['ordering', 'tax_percent', 0, true],
            ['ordering', 'service_charge_percent', 0, true],
            ['ordering', 'allow_guest_orders', true, true],
            ['ordering', 'min_order_amount', 0, true],
            ['ordering', 'accept_orders', true, true],

            ['kitchen', 'warning_after_minutes', 10, false],
            ['kitchen', 'critical_after_minutes', 20, false],
            ['kitchen', 'sound_enabled', true, false],

            // Keys are fully qualified rather than scoped by `group`, because
            // Setting::map() is a flat lookup and `header_en` alone would
            // collide the moment a second group wants a header.
            ['receipt', 'receipt_header_en', 'VIKING RESTAURANT', false],
            ['receipt', 'receipt_header_ar', 'مطعم فايكنج', false],
            ['receipt', 'receipt_footer_en', 'Thank you — see you again!', false],
            ['receipt', 'receipt_footer_ar', 'شكراً لزيارتكم — نراكم قريباً!', false],
            ['receipt', 'receipt_show_qr', true, false],
        ];

        foreach ($defaults as [$group, $key, $value, $isPublic]) {
            // updateOrCreate on `key` keeps operator edits from being
            // overwritten on a re-seed... except the value, which is the point
            // of a default. Only create when missing.
            if (! Setting::where('key', $key)->exists()) {
                Setting::put($key, $value, $group, $isPublic);
            }
        }

        Setting::flushCache();
    }
}
