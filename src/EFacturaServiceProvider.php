<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel;

use AtlasFlow\EFacturaRo\Anaf\AnafClient;
use AtlasFlow\EFacturaRo\Anaf\Environment;
use AtlasFlow\EFacturaRo\Anaf\OAuth\OAuthClient;
use AtlasFlow\EFacturaRo\Laravel\Console\PollCommand;
use AtlasFlow\EFacturaRo\Laravel\Console\RefreshTokensCommand;
use AtlasFlow\EFacturaRo\Laravel\Console\SyncInboxCommand;
use AtlasFlow\EFacturaRo\Laravel\Console\ValidateCommand;
use AtlasFlow\EFacturaRo\Laravel\Contracts\BundleStore;
use AtlasFlow\EFacturaRo\Laravel\Http\LaravelHttpClient;
use AtlasFlow\EFacturaRo\Laravel\Storage\DiskBundleStore;
use AtlasFlow\EFacturaRo\Support\Clock;
use AtlasFlow\EFacturaRo\Support\SystemClock;
use AtlasFlow\EFacturaRo\Validation\LocalValidator;
use AtlasFlow\EFacturaRo\Validation\RemoteValidator;
use AtlasFlow\EFacturaRo\Validation\Validator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class EFacturaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/efactura.php', 'efactura');

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(Psr17Factory::class);
        $this->app->bindIf(RequestFactoryInterface::class, Psr17Factory::class);
        $this->app->bindIf(StreamFactoryInterface::class, Psr17Factory::class);

        // Scoped, not singleton: Http::fake() swaps the factory per test, and Octane must not pin one.
        $this->app->scoped(LaravelHttpClient::class, fn (Application $app) => new LaravelHttpClient(
            $app->make(HttpFactory::class),
            (int) $app['config']->get('efactura.http.timeout', 60),
            (int) $app['config']->get('efactura.http.connect_timeout', 10),
        ));
        $this->app->bindIf(ClientInterface::class, LaravelHttpClient::class);

        $this->app->scoped(AnafClient::class, fn (Application $app) => new AnafClient(
            $app->make(ClientInterface::class),
            $app->make(RequestFactoryInterface::class),
            $app->make(StreamFactoryInterface::class),
            Environment::from((string) $app['config']->get('efactura.environment', 'test')),
            $app->make(Clock::class),
        ));

        $this->app->scoped(OAuthClient::class, fn (Application $app) => new OAuthClient(
            $app->make(ClientInterface::class),
            $app->make(RequestFactoryInterface::class),
            $app->make(StreamFactoryInterface::class),
            (string) $app['config']->get('efactura.client_id'),
            (string) $app['config']->get('efactura.client_secret'),
            $app->make(Clock::class),
        ));

        $this->app->singleton(LocalValidator::class);
        $this->app->scoped(RemoteValidator::class);
        $this->app->scoped(Validator::class, fn (Application $app) => new Validator($app->make(LocalValidator::class), $app->make(RemoteValidator::class)));

        $this->app->bindIf(BundleStore::class, fn (Application $app) => new DiskBundleStore(
            $app->make('filesystem')->disk((string) $app['config']->get('efactura.bundles.disk', 'local')),
            (string) $app['config']->get('efactura.bundles.prefix', 'efactura'),
        ));

        $this->app->scoped(EFactura::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/efactura.php' => $this->app->configPath('efactura.php')], 'efactura-config');
        $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'efactura-migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RefreshTokensCommand::class, PollCommand::class, SyncInboxCommand::class, ValidateCommand::class]);
        }

        if ($this->app['config']->get('efactura.routes.enabled', false)) {
            Route::middleware($this->app['config']->get('efactura.routes.middleware', ['web', 'auth']))
                ->prefix((string) $this->app['config']->get('efactura.routes.prefix', 'efactura'))
                ->group(__DIR__.'/../routes/efactura.php');
        }
    }
}
