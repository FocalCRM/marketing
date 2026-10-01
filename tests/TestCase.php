<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use DoPHP\MailBuilder\MailBuilderServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Focal\Core\CoreServiceProvider;
use Focal\Marketing\MarketingServiceProvider;
use Focal\Marketing\Tests\Fixtures\User;
use Focal\Sales\SalesServiceProvider;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithLaravelMigrations;

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
    }
}
