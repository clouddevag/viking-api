<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Everything the client needs before it can render anything: branding, public
 * settings, branches and currency rules.
 *
 * Served as one cached call so a cold start is a single round trip rather than
 * four.
 */
class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $payload = Cache::remember('viking:bootstrap', now()->addMinutes(10), function () {
            $settings = Setting::query()->public()->get()
                ->mapWithKeys(fn (Setting $setting) => [
                    $setting->key => is_array($setting->value) && array_key_exists('_', $setting->value)
                        ? $setting->value['_']
                        : $setting->value,
                ]);

            return [
                'settings' => $settings,
                'branches' => BranchResource::collection(
                    Branch::query()->active()->orderBy('sort_order')->get()
                )->resolve(),
                'currency' => [
                    'code' => config('viking.currency'),
                    'decimals' => config('viking.currency_decimals'),
                ],
                'locales' => [
                    ['code' => 'ar', 'name' => 'العربية', 'dir' => 'rtl'],
                    ['code' => 'en', 'name' => 'English', 'dir' => 'ltr'],
                ],
                'features' => [
                    'guest_orders' => (bool) config('viking.allow_guest_orders'),
                    'realtime' => config('broadcasting.default') === 'reverb',
                ],
            ];
        });

        return response()->json(['data' => $payload]);
    }
}
