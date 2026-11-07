# Modules

Each folder of `app/Modules` is a pluggable feature of the application. Employees, Departments,
Admins and the dashboard are the core and are not modules.

```
app/Modules/Leave/
├── LeaveModule.php            ← describes the module (found automatically: {Name}/{Name}Module.php)
├── Models/
├── Filament/                  ← resources, pages, widgets
├── Services/
├── Database/
│   ├── Migrations/            ← always run, whatever the state of the module
│   ├── Seeders/
│   └── Factories/
├── Resources/views/           ← views, used as view('leave::name')
└── Tests/                     ← run with the "Modules" test suite
```

## Create a module

1. Create `app/Modules/Invoices/InvoicesModule.php` extending `App\Modules\Module`.
   Give it a `key()` (stored in the `modules` table), a `label()` and a `description()`.
2. List its Filament classes per panel (`admin`, `portal`) in `filament($panelId)`:
   `['resources' => [...], 'pages' => [...], 'widgets' => [...], 'livewire' => [...]]`.
   `widgets` are shown on the dashboard; a widget that is only embedded in a page of the module
   goes in `livewire` (Livewire refuses, with HTTP 419, the requests of a component it does not know).
3. Optional: `seeders()` (run by `db:seed`), `requires()` (keys of the modules it depends on),
   `boot()` (observers, events; only called while the module is enabled).
4. Put the migrations in `Database/Migrations`, then run `php artisan migrate`.

The module appears in **System > Modules**, where an admin enables or disables it.

## What disabling does

A disabled module disappears from the menu, its screens return 404 and its observers are not
registered. Its tables and data are kept, and the migrations still run. Enabling it again brings
everything back. A module cannot be disabled while an enabled module requires it.

## Rules

- A module can use the core models (`App\Models\Employee`, `Department`, `Position`).
- The core must not use a module's classes: it would break when the module is disabled.
- Declare the dependencies between modules with `requires()` instead of importing another module.
