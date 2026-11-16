<?php

namespace App\Providers;

use App\Models\Department;
use App\Observers\DepartmentObserver;
use App\Observers\EmployeeObserver;
use App\Services\MailSettings;
use App\Services\SettingsStore;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Facades\FilamentColor;
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
        $this->configureActions();
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
    /**
     * Same buttons on every page: a neutral outline for Edit, a red outline for Delete, black for Save.
     */
    private function configureActions(): void
    {
        EditAction::configureUsing(fn(EditAction $action) => $action->color('gray')->outlined());
        DeleteAction::configureUsing(fn(DeleteAction $action) => $action->color('danger')->outlined());

        // Black "Save changes" / "Create" buttons on every form page (the Save buttons of the settings
        // pages use color="black" in their views)
        FilamentColor::register([
            'black' => [
                50 => '#fafafa', 100 => '#f5f5f5', 200 => '#e5e5e5', 300 => '#d4d4d4', 400 => '#525252',
                500 => '#262626', 600 => '#000000', 700 => '#000000', 800 => '#000000', 900 => '#000000', 950 => '#000000',
            ],
        ]);
        Action::configureUsing(function (Action $action) {
            if (in_array($action->getName(), ['save', 'create'], true)) {
                $action->color('black');
            }
        });
    }

    public function configureUrl(): void
    {
        if ($this->app->environment('production')) {

            URL::forceScheme('https');
        }
    }
}
