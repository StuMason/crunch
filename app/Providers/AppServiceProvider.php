<?php

namespace App\Providers;

use App\Http\Middleware\CrunchAuth;
use App\Inference\InferenceManager;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        $this->configureApiDocs();
    }

    /**
     * Document only the inference endpoints (root-level, behind CrunchAuth) and
     * make the interactive reference at /docs/api publicly viewable.
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => in_array(CrunchAuth::class, $route->gatherMiddleware(), true))
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        Gate::define('viewApiDocs', fn ($user = null): bool => true);
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
