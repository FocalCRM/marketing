<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Odden\Core\CoreServiceProvider;
use Odden\MailBuilder\MailBuilderServiceProvider;
use Odden\Marketing\MarketingServiceProvider;
use Odden\Marketing\Tests\Fixtures\User;
use Odden\Sales\SalesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

use function Orchestra\Testbench\after_resolving;
use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    public const API_TOKEN = 'test-api-token';

    /**
     * Boots marketing with its required dependencies (core, mail builder), plus sales for the closed-loop attribution tests.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        // Filament is a dev dependency, used only by the slot builder component test.
        $filament = array_values(array_filter([
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PowerJoinsServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
        ], class_exists(...)));

        return [
            ...$filament,
            CoreServiceProvider::class,
            MailBuilderServiceProvider::class,
            MarketingServiceProvider::class,
            SalesServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('odden-marketing.api.token', self::API_TOKEN);
    }

    /**
     * Every request carries the API token, so server-to-server endpoints can be exercised
     * directly. Security tests call flushHeaders() to test missing or wrong tokens.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('X-Odden-Token', self::API_TOKEN);
    }

    /**
     * Laravel's own migrations (users, cache, jobs). Registered on the migrator rather than
     * run and rolled back per test: RefreshDatabase owns the schema, and rolling back
     * users fails on databases that enforce foreign keys (PostgreSQL, MySQL).
     */
    protected function defineDatabaseMigrations(): void
    {
        after_resolving($this->app, 'migrator', static function ($migrator): void {
            $migrator->path(default_migration_path());
        });
    }
}
