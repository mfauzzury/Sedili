<?php

namespace App\Modules\Attendance\Filament\Resources\Attendances\Schemas;

use Filament\Forms\Components\{TextInput, DatePicker, Select, TimePicker, Textarea};
use Filament\Schemas\Schema;
use App\Models\{Employee};
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\Attendance;
use Carbon\Carbon;
use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\{Section, Grid};
use Illuminate\Validation\Rules\Unique;

class AttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->options(function () {
                        return Employee::all()->pluck('name', 'id');
                    })
                    ->label('Employee')
                    ->required()
                    ->searchable([
                        'first_name',
                        'last_name'
                    ]),
                Select::make('shift_id')
                    ->options(function () {
                        return Shift::all()->pluck('name', 'id');
                    })
                    ->preload()
                    ->label('Shift')
                    ->searchable()
                    ->createOptionForm(
                        [
                            TextInput::make('name')
                                ->required()
                                ->label('Shift Name')

                            ,
                            Grid::make(2)->schema([

                                TimePicker::make('start_time')
                                    ->required()
                                    ->label('Start Time')
                                    ->time(),
                                TimePicker::make('end_time')
                                    ->required()
                                    ->label('End Time')
                                    ->time(),
                            ]),



                        ]
                    )
                    ->createOptionUsing(function (array $data) {
                        return Shift::create([
                            'name' => $data['name'],
                            'start_time' => $data['start_time'],
                            'end_time' => $data['end_time'],

                        ])->id;
                    })
                ,
                DatePicker::make('date')
                    // One record per employee, day and shift (as the database): colleagues share the same days
                    ->unique(
                        table: Attendance::class,
                        column: 'date',
                        ignoreRecord: true,
                        modifyRuleUsing: fn(Unique $rule, Get $get) => $rule
                            ->where('employee_id', $get('employee_id'))
                            ->where('shift_id', $get('shift_id') ?: null)
                    )
                    ->required()
                    ->label('Attendance Date'),
                TimePicker::make('clock_in')
                    ->required()
                    ->label('Clock In Time')
                    ->time(),
                TimePicker::make('clock_out')
                    // ->required()
                    ->label('Clock Out Time')
                    ->time()
                    ->rules([
                        // After the clock in, unless the shift runs past midnight (a night shift)
                        fn(Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                            $in = $get('clock_in');
                            if (blank($value) || blank($in)) {
                                return;
                            }
                            $overnight = Shift::find($get('shift_id'))?->isOvernight() ?? false;
                            if (!$overnight && Carbon::parse($value)->lte(Carbon::parse($in))) {
                                $fail('The clock out time must be after the clock in time.');
                            }
                        },
                    ]),

                Textarea::make('remarks')
                    ->label('Remarks')
                    ->maxLength(255)
                    ->nullable()
                    ->autosize()
                    ->columnSpanFull()
                ,
            ]);
    }
}