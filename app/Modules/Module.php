<?php

namespace App\Modules;

use Illuminate\Database\Seeder;
use ReflectionClass;

/**
 * A pluggable feature of the application (leave, payroll, etc.).
 *
 * A module lives in app/Modules/{Name}/ and is discovered through its {Name}Module class.
 * The folder holds everything of the module: models, Filament screens, migrations, seeders,
 * views and tests. Migrations are always registered (the schema never depends on the state of
 * the module); the screens, routes and observers are only registered while the module is enabled.
 */
abstract class Module
{
    /**
     * Unique identifier, stored in the modules table (e.g. "leave").
     */
    abstract public function key(): string;

    /**
     * Name displayed in the Modules screen.
     */
    abstract public function label(): string;

    public function description(): string
    {
        return '';
    }

    /**
     * Keys of the modules that must be enabled for this one to work.
     *
     * @return list<string>
     */
    public function requires(): array
    {
        return [];
    }

    /**
     * Filament classes added to a panel while the module is enabled.
     *
     * @param string $panelId id of the panel (admin, portal)
     * "widgets" are shown on the dashboard. "livewire" registers a Livewire component without
     * showing it on the dashboard (for example a widget embedded in one of the pages of the module):
     * Livewire answers HTTP 419 to the requests of a component it does not know.
     *
     * @return array{resources?: list<class-string>, pages?: list<class-string>, widgets?: list<class-string>, livewire?: list<class-string>}
     */
    public function filament(string $panelId): array
    {
        return [];
    }

    /**
     * Seeders run by the DatabaseSeeder.
     *
     * @return list<class-string<Seeder>>
     */
    public function seeders(): array
    {
        return [];
    }

    /**
     * Called at boot, only when the module is enabled (observers, events, etc.).
     */
    public function boot(): void
    {
    }

    public function path(): string
    {
        return dirname((new ReflectionClass($this))->getFileName());
    }

    public function migrationsPath(): string
    {
        return $this->path() . '/Database/Migrations';
    }

    public function viewsPath(): string
    {
        return $this->path() . '/Resources/views';
    }
}
