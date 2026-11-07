<?php

namespace App\Modules\Leave\Filament\Pages;

use App\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Remaining leave days of every employee, one column per leave type, for the current year.
 */
class LeaveBalances extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-scale';
    protected static string|\UnitEnum|null $navigationGroup = 'HR Management';
    protected static ?int $navigationSort = 3;
    protected static ?string $title = 'Leave Balances';

    protected string $view = 'leave::leave-balances';

    /** @var array<int, array<string, float>>|null */
    private ?array $charged = null;

    public function table(Table $table): Table
    {
        $year = (int) now()->year;
        $service = app(LeaveService::class);

        $columns = [
            TextColumn::make('employee_code')->label('Code')->sortable(),
            TextColumn::make('name')
                ->label('Employee')
                ->state(fn(Employee $record) => $record->name)
                ->searchable(['first_name', 'last_name']),
        ];
        // Only the leave types with a limit have a balance
        foreach (LeaveType::where('is_active', true)->orderBy('id')->get() as $type) {
            $columns[] = TextColumn::make("balance_{$type->id}")
                ->label($type->name)
                ->state(function (Employee $record) use ($service, $type, $year) {
                    $this->charged ??= $service->chargedDaysByEmployee($year);
                    // Not available (gender restricted type) or no limit: no balance to display
                    if ($type->gender_restriction !== null && $record->gender !== $type->gender_restriction) {
                        return '-';
                    }
                    $entitlement = $service->entitlement($record, $type, $year);
                    if ($entitlement === null) {
                        return '-';
                    }
                    $used = $this->charged[$record->id][$type->name] ?? 0.0;
                    return $service->format($entitlement - $used) . ' / ' . $service->format($entitlement);
                })
                ->description('left / entitled')
                ->alignCenter();
        }

        return $table
            ->heading("Leave balances {$year}")
            ->description('Pending and approved requests are deducted. Weekends and public holidays are not counted.')
            ->query(Employee::query()->where('is_active', true)->orderBy('first_name'))
            ->columns($columns)
            ->paginated([10, 25, 50]);
    }
}
