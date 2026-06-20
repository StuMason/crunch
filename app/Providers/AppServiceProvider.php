<?php

namespace App\Providers;

use App\Inference\InferenceManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Telescope\TelescopeServiceProvider as BaseTelescopeServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Telescope only when Redis extension is available
        // This prevents build failures during package:discover
        if (extension_loaded('redis')) {
            $this->app->register(BaseTelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Warm inference engines: a singleton so models stay loaded across
        // requests under Octane (holds no request state — safe to persist).
        $this->app->singleton(InferenceManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
