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
5. Optional: `todos(Employee $employee, string $panelId)` returns what the employee has to do in
   this module, as a list of `App\Modules\Todo` (title, url, and optionally a detail line, a badge
   such as a due date, an urgency colour `danger|warning|gray` and an icon). The dashboard of the
   portal collects them from every enabled module under **To do**. Keep it short: the most urgent
   first, and one aggregated line ("3 unread messages") rather than one per item when there are many.
   Only answer for `$panelId === 'portal'`; a module that fails is skipped, it never breaks the dashboard.
6. Optional: `reports()` returns the reports of the module, as a list of `App\Modules\Report`. The
   **Reports** page (Reports module, admin panel) lists them under the module and runs them. A report
   gives a `key()`, a `title()`, a `description()`, the period filter it takes (`period()`: a date
   range, a year, or none for a picture of today) and `run(ReportFilters $filters)`, which returns a
   `ReportResult`: headline figures (`ReportStat`), an optional one-series chart (`ReportChart`), the
   columns (`ReportColumn::text|number|money|date`) and the rows as raw values. The page does the
   display, search, sorting and CSV export. Use `$filters->whereEmployee($query)` for the department
   filter and `$filters->whereInPeriod($query, 'date')` for the period.
7. Optional: `settingsUrl()` returns the page where the module is configured (usually a page of the
   module in the admin panel). **System > Modules** shows a "Settings" link to it while the module is enabled.
8. Optional: `chatTools()` returns the lookups the module offers to the chat assistant (Chatbox module),
   as a list of `App\Modules\ChatTool` ("my leave balance"). Claude calls them itself while answering an
   employee, once the admin switched them on in **System > Chatbox > Data access** (all are off until
   then). A tool gives a `key()`, a `label()`, a `description()` for Claude, `dataSent()` (what personal
   data it sends to Claude, shown to the admin), an `inputSchema()` and `run(Employee $employee, array $input)`.
   A tool only reads, and only for the `$employee` it is given, who is the one chatting: never take an
   employee or an id from `$input`, which comes from the model and can be steered by what the employee types.
   Validate every input value and return only what answers the question (it is sent to Claude).

The module appears in **System > Modules**, where an admin enables or disables it.

## What disabling does

A disabled module disappears from the menu, its screens return 404 and its observers are not
registered. Its tables and data are kept, and the migrations still run. Enabling it again brings
everything back. A module cannot be disabled while an enabled module requires it.

## Rules

- A module can use the core models (`App\Models\Employee`, `Department`, `Position`).
- The core must not use a module's classes: it would break when the module is disabled.
- Declare the dependencies between modules with `requires()` instead of importing another module.
