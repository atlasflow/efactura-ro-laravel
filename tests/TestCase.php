<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Tests;

use AtlasFlow\EFacturaRo\Laravel\EFacturaServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [EFacturaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connection());

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('filesystems.disks.local.root', sys_get_temp_dir().'/efactura-tests-'.getmypid());

        $app['config']->set('efactura.environment', 'test');
        $app['config']->set('efactura.client_id', 'app-id');
        $app['config']->set('efactura.client_secret', 'app-secret');
        $app['config']->set('efactura.redirect_uri', 'https://app.example/efactura/callback');
        $app['config']->set('efactura.submissions.defer_seconds', 300);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * EFACTURA_TEST_DB=pgsql|mysql runs the suite on a real engine; sqlite in
     * memory otherwise. The engine settings come from EFACTURA_TEST_<ENGINE>_*
     * with Kbox's local defaults.
     *
     * @return array<string, mixed>
     */
    private static function connection(): array
    {
        return match (getenv('EFACTURA_TEST_DB') ?: 'sqlite') {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('EFACTURA_TEST_PGSQL_HOST') ?: '/var/run/postgresql',
                'port' => (int) (getenv('EFACTURA_TEST_PGSQL_PORT') ?: 5432),
                'database' => getenv('EFACTURA_TEST_PGSQL_DATABASE') ?: 'efactura_test',
                'username' => getenv('EFACTURA_TEST_PGSQL_USER') ?: 'robert',
                'password' => getenv('EFACTURA_TEST_PGSQL_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('EFACTURA_TEST_MYSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('EFACTURA_TEST_MYSQL_PORT') ?: 3306),
                'database' => getenv('EFACTURA_TEST_MYSQL_DATABASE') ?: 'atlas_verify_efactura',
                'username' => getenv('EFACTURA_TEST_MYSQL_USER') ?: 'robert',
                'password' => getenv('EFACTURA_TEST_MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_0900_ai_ci',
                'prefix' => '',
                'strict' => true,
            ],
            default => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        };
    }
}
