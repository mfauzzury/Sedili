<?php

namespace App\Providers;

use App\Models\Department;
use App\Observers\DepartmentObserver;
use App\Observers\EmployeeObserver;
use App\Services\MailSettings;
use App\Services\SettingsStore;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Models\{Employee};
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        $this->configureCommands();
        $this->configureModels();
        $this->configureUrl();
        // SMTP server chosen in System > Email (when it is enabled), instead of the .env values
        $this->app->make(MailSettings::class)->apply();
        Department::observe(DepartmentObserver::class);
        Employee::observe(EmployeeObserver::class);

    }
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands(
            $this->app->environment('production')
        );
    }
    private function configureModels(): void
    {
        //
        Model::shouldBeStrict();
        Model::unguard();
    }
    public function configureUrl(): void
    {
        if ($this->app->environment('production')) {

            URL::forceScheme('https');
        }
    }
}
