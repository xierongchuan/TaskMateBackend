<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\FileValidatorInterface;
use App\Services\FileValidation\FileValidationConfig;
use App\Services\FileValidation\FileValidator;
use App\Services\FileValidation\MimeTypeResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Регистрация FileValidationConfig как singleton
        $this->app->singleton(FileValidationConfig::class, function ($app) {
            return new FileValidationConfig($app['config']);
        });

        // Регистрация MimeTypeResolver
        $this->app->singleton(MimeTypeResolver::class, function ($app) {
            return new MimeTypeResolver($app->make(FileValidationConfig::class));
        });

        // Регистрация FileValidator и привязка к интерфейсу
        $this->app->singleton(FileValidatorInterface::class, function ($app) {
            return new FileValidator(
                $app->make(FileValidationConfig::class),
                $app->make(MimeTypeResolver::class)
            );
        });

        // Alias для конкретного класса
        $this->app->alias(FileValidatorInterface::class, FileValidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Login rate limiting - 5 attempts per minute per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // General API rate limiting - 180 requests per minute per user/IP
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(180)->by($request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });

        // Downloads rate limiting - higher limit for file downloads (signed URLs)
        RateLimiter::for('downloads', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
