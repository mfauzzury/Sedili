<?php

namespace App\Filament\Resources\Employees\Schemas;
use Filament\Forms\Components\{TextInput, DatePicker, Select, Toggle, TextArea};
use Filament\Schemas\Schema;
use App\Models\{Employee, Position, Department};
use Closure;
use Illuminate\Database\Eloquent\Model;
use Filament\Schemas\Components\{Section, Grid};
use Illuminate\Database\Eloquent\Builder;
class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Basic Information')
                    ->collapsible()
                    ->schema([


                        Grid::make(2)->schema([
                            TextInput::make('employee_code')
                                ->required()
                                ->maxLength(50)
                                ->label('Employee code')
                                ->placeholder('Enter employee number')
                                ->columnSpan(1)
                            ,
                            TextInput::make('first_name')
                                ->required(),
                            TextInput::make('last_name')
                                ->required(),
                            DatePicker::make('date_of_birth'),
                            Select::make('gender')
                                ->options(['Male' => 'Male', 'Female' => 'Female']),
                            Select::make('marital_status')
                                ->options([
                                    'Single' => 'Single',
                                    'Married' => 'Married',
                                    'Divorced' => 'Divorced',
                                    'Widowed' => 'Widowed'
                                ]),

                        ])
                    ])
                    ->columnSpanFull(),
                Section::make('Contact Information')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->label('Email Address')
                                    ->helperText('The employee sets a password with "Forgot password" on the portal login page.')
                                    ->unique(ignoreRecord: true)
                                    ->copyable()
                                ,
                                TextInput::make('phone')->tel()->required()->label('Phone Number')->unique(ignoreRecord: true),
                                TextInput::make('national_id')->required()->unique(ignoreRecord: true)
                                    ->integer()
                                ,
                                TextInput::make('kra_pin'),
                            ])
                    ])
                    ->columnSpanFull(),
                Section::make('Emergency Contact')
                    ->collapsible()
                    ->schema([

                        Grid::make(2)
                            ->schema([
                                TextInput::make('emergency_contact_name'),
                                TextInput::make('emergency_contact_phone'),
                            ])
                    ])
                    ->columnSpanFull(),
                Section::make('Next of Kin')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('next_of_kin_name')
                                    ->label('Name')
                                    ->required(),
                                TextInput::make('next_of_kin_relationship')
                                    ->label('Relationship')
                                    ->required(),
                                TextInput::make('next_of_kin_phone')
                                    ->required()
                                    ->tel()
                                    ->label('Phone'),
                                TextInput::make('next_of_kin_email')
                                    ->label('Email')
                                    ->email(),
                            ])
                    ])
                    ->columnSpanFull(),
                Section::make('Employment Details')
                    ->collapsible()
                    ->schema([
                        Grid::make(2)

                            ->schema([
                                Select::make('department_id')
                                    ->relationship(
                                        name: 'department',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn(Builder $query) => $query->select('id', 'name')->orderBy('name', 'asc')
                                    )
                                    ->label('Department')
                                    ->searchable()
                                    ->placeholder('Select a department')
                                    ->preload()
                                    // ->columnSpanFull()
                                    ->nullable(),
                                Select::make('position_id')
                                    ->options(
                                        Position::all()->pluck('title', 'id')

                                    )
                                    ->label('Position')
                                    ->searchable()
                                    ->placeholder('Select a position')
                                    ->preload()
                                    ->nullable()
                                    ->createOptionForm([
                                        TextInput::make('title')
                                            ->required()
                                            ->label('Position Title'),
                                        Select::make('department_id')
                                            ->options(
                                                Department::all()->pluck('name', 'id')
                                            ),
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('code')
                                                    ->label('Position Code')
                                                    ->unique(ignoreRecord: true)
                                                    ->nullable(),
                                                TextInput::make('salary')
                                                    ->label('Salary')
                                                    ->numeric()
                                                    ->nullable(),
                                            ]),
                                        Textarea::make('description')
                                            ->label('Description')
                                            ->nullable()
                                            ->maxLength(255),

                                    ])
                                    ->createOptionUsing(function (array $data) {
                                        return Position::create([
                                            'title' => $data['title'],
                                            'department_id' => $data['department_id'],
                                            'code' => $data['code'] ?? null,
                                            'salary' => $data['salary'] ?? null,
                                            'description' => $data['description'] ?? null,
                                        ])->id;
                                    })
                                    ->native(false),
                                Select::make('supervisor_id')
                                    ->label('Supervisor')
                                    ->options(fn(?Model $record) => Employee::query()
                                        ->where('is_active', true)
                                        ->when($record, fn(Builder $query) => $query->whereKeyNot($record->getKey()))
                                        ->orderBy('first_name')->orderBy('last_name')
                                        ->get()->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('Use the department manager')
                                    ->helperText('Who this employee reports to. Leave empty to use the manager of the department.')
                                    ->rules([
                                        // Nobody can report, directly or not, to someone who reports to them
                                        fn(?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                                            if ($record === null || blank($value)) {
                                                return;
                                            }
                                            $id = (int) $value;
                                            $seen = [];
                                            while ($id && !isset($seen[$id])) {
                                                if ($id === $record->getKey()) {
                                                    $fail('This employee already reports to the selected supervisor.');
                                                    return;
                                                }
                                                $seen[$id] = true;
                                                $id = (int) Employee::whereKey($id)->value('supervisor_id');
                                            }
                                        },
                                    ])
                                    ->nullable(),
                                Select::make('employment_type')
                                    ->options([
                                        'Permanent' => 'Permanent',
                                        'Contract' => 'Contract',
                                        'Casual' => 'Casual',
                                    ])
                                    ->required(),
                                DatePicker::make('hire_date')->required(),
                                DatePicker::make('termination_date'),
                                Toggle::make('is_active')->default(true),
                            ])
                    ])
                    ->columnSpanFull(),
            ])


        ;
    }
}