<?php

declare(strict_types=1);

namespace IvoWmg\Borica;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use IvoWmg\Borica\Console\PruneLogsCommand;
use IvoWmg\Borica\Logging\DatabaseLog;
use IvoWmg\Borica\Signature\Signer;

class BoricaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/borica.php', 'borica');

        $this->app->singleton(Signer::class, function ($app): Signer {
            $config = $app['config']['borica'];

            return new Signer(
                privateKeyPath: (string) $config['private_key'],
                publicKeyPath: (string) $config['public_key'],
                privateKeyPassword: (string) $config['private_key_password'],
            );
        });

        $this->app->singleton(HttpClient::class, function ($app): HttpClient {
            return new LaravelHttpClient((int) $app['config']['borica.timeout']);
        });

        $this->app->singleton(DatabaseLog::class, fn ($app): DatabaseLog => new DatabaseLog($app['db']->connection()));

        $this->app->singleton(Borica::class, function ($app): Borica {
            return new Borica(
                config: $app['config']['borica'],
                signer: $app->make(Signer::class),
                http: $app->make(HttpClient::class),
                log: $app['config']['borica.logging.database'] ? $app->make(DatabaseLog::class) : null,
            );
        });

        $this->app->alias(Borica::class, 'borica');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneLogsCommand::class]);

        $this->publishes([
            __DIR__.'/../config/borica.php' => $this->app->configPath('borica.php'),
        ], 'borica-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_borica_logs_table.php.stub' => $this->migrationPath(),
        ], 'borica-migrations');
    }

    private function migrationPath(): string
    {
        return $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_borica_logs_table.php');
    }
}

/**
 * Laravel's HTTP client behind the package's interface.
 *
 * Defined here rather than in its own file to keep the framework binding in
 * one place: everything else in src/ runs without Laravel.
 */
final class LaravelHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeout = 60) {}

    /** @param array<string, string> $fields */
    public function post(string $url, array $fields): string
    {
        return Http::asForm()
            ->timeout($this->timeout)
            ->post($url, $fields)
            ->body();
    }
}
