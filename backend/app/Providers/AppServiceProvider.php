<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureAuthorization();
        $this->configureUrls();
    }

    private function configureModels(): void
    {
        // Fail loudly on a missing eager load instead of silently issuing N+1
        // queries; production keeps serving rather than 500ing on a lazy load.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }

    private function configureAuthorization(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // The owner role bypasses every check. Returning null (rather than
        // false) for everyone else lets the normal policies run.
        Gate::before(fn (User $user, string $ability) => $user->hasRole('super-admin') ? true : null);
    }

    private function configureUrls(): void
    {
        // Behind a TLS-terminating proxy Laravel would otherwise generate
        // http:// links in signed URLs and password reset mails.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
