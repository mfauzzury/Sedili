<?php

namespace App\Modules;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Registry of the modules found in app/Modules and of their enabled / disabled state
 * (stored in the modules table; a module without a row is enabled).
 */
class ModuleManager
{
    /** @var array<string, Module>|null */
    private ?array $modules = null;

    /** @var array<string, bool>|null */
    private ?array $states = null;

    /**
     * All the modules, enabled or not, keyed by module key.
     *
     * @return array<string, Module>
     */
    public function all(): array
    {
        if ($this->modules === null) {
            $this->modules = [];
            foreach (glob(app_path('Modules/*/*Module.php')) ?: [] as $file) {
                $directory = basename(dirname($file));
                $class = "App\\Modules\\{$directory}\\{$directory}Module";
                if (class_exists($class) && is_subclass_of($class, Module::class)) {
                    $module = new $class();
                    $this->modules[$module->key()] = $module;
                }
            }
            ksort($this->modules);
        }
        return $this->modules;
    }

    public function find(string $key): ?Module
    {
        return $this->all()[$key] ?? null;
    }

    public function isEnabled(string $key): bool
    {
        return $this->states()[$key] ?? true;
    }

    /**
     * @return array<string, Module>
     */
    public function enabled(): array
    {
        return array_filter($this->all(), fn(Module $module) => $this->isEnabled($module->key()));
    }

    /**
     * Enable or disable a module.
     *
     * @throws InvalidArgumentException if the module is unknown or if its dependencies forbid the change
     */
    public function setEnabled(string $key, bool $enabled): void
    {
        $module = $this->find($key) ?? throw new InvalidArgumentException("Unknown module [$key].");

        if ($enabled) {
            foreach ($module->requires() as $required) {
                if (!$this->isEnabled($required)) {
                    $label = $this->find($required)?->label() ?? $required;
                    throw new InvalidArgumentException("{$module->label()} requires the module {$label}.");
                }
            }
        } else {
            foreach ($this->enabled() as $other) {
                if (in_array($key, $other->requires(), true)) {
                    throw new InvalidArgumentException("{$other->label()} requires {$module->label()}.");
                }
            }
        }

        DB::table('modules')->updateOrInsert(
            ['module' => $key],
            ['enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()]
        );
        $this->states = null;
    }

    /**
     * Filament classes of the enabled modules for a panel.
     *
     * @param string $panelId id of the panel (admin, portal)
     * @param string $type resources, pages or widgets
     * @return list<class-string>
     */
    public function filament(string $panelId, string $type): array
    {
        $classes = [];
        foreach ($this->enabled() as $module) {
            array_push($classes, ...($module->filament($panelId)[$type] ?? []));
        }
        return $classes;
    }

    /**
     * @return array<string, bool>
     */
    private function states(): array
    {
        if ($this->states === null) {
            try {
                $this->states = DB::table('modules')->pluck('enabled', 'module')->map(fn($v) => (bool) $v)->all();
            } catch (Throwable) {
                // The table does not exist yet (before the first migration): everything is enabled
                return [];
            }
        }
        return $this->states;
    }
}
