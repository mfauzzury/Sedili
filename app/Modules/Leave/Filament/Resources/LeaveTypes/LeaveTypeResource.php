<?php

namespace App\Modules\Leave\Filament\Resources\LeaveTypes;

use App\Modules\Leave\Filament\Resources\LeaveTypes\Pages\ListLeaveTypes;
use App\Modules\Leave\Models\LeaveType;
use Filament\Actions\{BulkActionGroup, DeleteAction, DeleteBulkAction, EditAction};
use Filament\Forms\Components\{ColorPicker, Repeater, Select, TextInput, Toggle};
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\{ColorColumn, IconColumn, TextColumn};
use Filament\Tables\Table;

class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup = 'HR Management';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'Leave Type';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            ColorPicker::make('color')
                ->label('Calendar colour')
                ->helperText('Colour of this leave type in the leave calendar.'),
            TextInput::make('days_per_year')
                ->label('Days per year')
                ->numeric()
                ->minValue(0)
                ->helperText('Leave empty for no limit. Ignored when entitlement tiers are set.'),
            Repeater::make('tiers')
                ->label('Entitlement by years of service')
                ->helperText('Example: from 0 years 8 days, from 2 years 12 days, from 5 years 16 days.')
                ->schema([
                    TextInput::make('min_years')->label('From (years of service)')->numeric()->minValue(0)->required(),
                    TextInput::make('days')->label('Days per year')->numeric()->minValue(0)->required(),
                ])
                ->columns(2)
                ->addActionLabel('Add tier')
                ->columnSpanFull(),
            Select::make('gender_restriction')
                ->label('Only for')
                ->options(['Male' => 'Male', 'Female' => 'Female'])
                ->placeholder('Everyone'),
            Toggle::make('count_calendar_days')
                ->label('Count weekends and holidays')
                ->helperText('For example maternity leave is counted in consecutive days.'),
            Toggle::make('requires_attachment')
                ->label('Evidence required')
                ->helperText('The employee must attach a document, for example a medical certificate, to request this leave.'),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('color')->label('Colour')->state(fn(LeaveType $record) => $record->displayColor()),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('days_per_year')->label('Days per year')->default('No limit'),
                TextColumn::make('tiers')
                    ->label('Tiers')
                    ->state(fn(LeaveType $record) => collect($record->tiers ?? [])
                        ->map(fn($tier) => "{$tier['min_years']}y+: {$tier['days']}")
                        ->implode(' / ') ?: '-'),
                TextColumn::make('gender_restriction')->label('Only for')->default('Everyone'),
                IconColumn::make('count_calendar_days')->label('Calendar days')->boolean(),
                IconColumn::make('requires_attachment')->label('Evidence required')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeaveTypes::route('/'),
        ];
    }
}
