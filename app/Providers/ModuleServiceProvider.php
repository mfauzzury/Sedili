<?php

namespace App\Providers;

use App\Modules\ModuleManager;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);
    }

    public function boot(): void
    {
        $manager = $this->app->make(ModuleManager::class);

        foreach ($manager->all() as $module) {
            // Migrations and views are always available: the schema does not depend on the state of the module
            if (is_dir($module->migrationsPath())) {
                $this->loadMigrationsFrom($module->migrationsPath());
            }
            if (is_dir($module->viewsPath())) {
                $this->loadViewsFrom($module->viewsPath(), $module->key());
            }
        }

        foreach ($manager->enabled() as $module) {
            $module->boot();
        }
    }
}
