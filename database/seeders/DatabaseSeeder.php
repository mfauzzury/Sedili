<?php

namespace Database\Seeders;


// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Modules\ModuleManager;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        // Each module brings its own seeders
        foreach (app(ModuleManager::class)->all() as $module) {
            $this->call($module->seeders());
        }

    }
}
