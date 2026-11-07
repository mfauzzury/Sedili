<?php

namespace App\Filament\Pages;

use App\Modules\Module;
use App\Modules\ModuleManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * Enable or disable the pluggable modules. A disabled module disappears from the menu and its
 * screens are no longer reachable; its data stays in the database.
 */
class Modules extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-puzzle-piece';
    protected static string|\UnitEnum|null $navigationGroup = 'System';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Modules';

    protected string $view = 'filament.pages.modules';

    /**
     * @return array<string, Module>
     */
    public function getModules(): array
    {
        return app(ModuleManager::class)->all();
    }

    public function isEnabled(string $key): bool
    {
        return app(ModuleManager::class)->isEnabled($key);
    }

    public function toggle(string $key): void
    {
        $manager = app(ModuleManager::class);
        $module = $manager->find($key);
        if ($module === null) {
            return;
        }

        $enable = !$manager->isEnabled($key);
        try {
            $manager->setEnabled($key, $enable);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            return;
        }

        Notification::make()
            ->title($module->label() . ($enable ? ' enabled' : ' disabled'))
            ->success()
            ->send();

        // Reload the whole page so that the menu is rebuilt with the new state of the modules
        $this->redirect(static::getUrl());
    }
}
