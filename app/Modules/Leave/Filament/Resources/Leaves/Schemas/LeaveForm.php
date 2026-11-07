<?php
namespace App\Modules\Leave\Filament\Resources\Leaves\Schemas;

use App\Models\{Employee};
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveService;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\{DatePicker, Select, Textarea};
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class LeaveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->options(function () {
                        return Employee::all()->pluck('name', 'id');
                    })
                    ->searchable(
                        [
                            'first_name',
                            'last_name',
                        ]
                    )
                    ->live()
                    ->required()
                    ->label('Employee'),
                Select::make('leave_type')
                    ->options(fn() => LeaveType::where('is_active', true)->orderBy('id')->pluck('name', 'name'))
                    ->live()
                    ->helperText(fn(Get $get, ?Model $record) => self::balanceText($get, $record))
                    ->required(),
                DatePicker::make('start_date')
                    ->required()
                    ->live()
                    ->label('Start Date'),
                DatePicker::make('end_date')
                    ->required()
                    ->label('End Date')
                    ->rules([
                        fn(Get $get, ?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                            if (blank($get('employee_id')) || blank($get('leave_type'))) {
                                return;
                            }
                            $errors = app(LeaveService::class)->validate(
                                (int) $get('employee_id'),
                                (string) $get('leave_type'),
                                $get('start_date'),
                                $value,
                                $get('status'),
                                $record?->id
                            );
                            foreach ($errors as $error) {
                                $fail($error);
                            }
                        },
                    ]),
                Select::make('status')
                    ->options([
                        'Pending' => 'Pending',
                        'Approved' => 'Approved',
                        'Rejected' => 'Rejected',
                    ])
                    ->default('Pending')
                    ->required(),
                Textarea::make('rejection_reason')
                    ->nullable()
                    ->columnSpan('full')
                    ->label('Rejection Reason'),
                Textarea::make('notes')
                    ->nullable()
                    ->columnSpan('full')
                    ->label('Notes'),
            ]);
    }

    /**
     * Remaining balance of the selected employee and leave type, shown under the leave type.
     */
    private static function balanceText(Get $get, ?Model $record): ?string
    {
        if (blank($get('employee_id')) || blank($get('leave_type'))) {
            return null;
        }
        $employee = Employee::find($get('employee_id'));
        $type = LeaveType::where('name', $get('leave_type'))->first();
        if ($employee === null || $type === null) {
            return null;
        }
        $service = app(LeaveService::class);
        $year = filled($get('start_date')) ? Carbon::parse($get('start_date'))->year : (int) now()->year;
        $balance = $service->balance($employee, $type, $year, $record?->id);
        if ($balance === null) {
            return "{$type->name}: no limit.";
        }
        $entitlement = $service->entitlement($employee, $type, $year);
        return sprintf(
            '%s balance in %d: %s of %s day(s) left.',
            $type->name,
            $year,
            $service->format($balance),
            $service->format($entitlement)
        );
    }
}
