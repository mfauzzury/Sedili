<?php

namespace App\Modules\Claims\Filament\Resources\ClaimTypes;

use App\Modules\Claims\Filament\Resources\ClaimTypes\Pages\ListClaimTypes;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimType;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClaimTypeResource extends Resource
{
    protected static ?string $model = ClaimType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'HR Management';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'Claim Type';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('description')->maxLength(255),
            Toggle::make('is_mileage')
                ->label('Mileage')
                ->helperText('The employee enters a distance; the amount is the distance × the rate per km.')
                ->live(),
            TextInput::make('rate_per_km')
                ->label('Rate per km')
                ->prefix(Claim::CURRENCY)
                ->numeric()
                ->minValue(0.01)
                ->helperText('A new rate only applies to the items saved after the change.')
                ->visible(fn (Get $get) => (bool) $get('is_mileage'))
                ->required(fn (Get $get) => (bool) $get('is_mileage')),
            Toggle::make('requires_receipt')
                ->label('Receipt required')
                ->helperText('Each item of this type needs a receipt (PDF, JPG or PNG) before the claim can be submitted.'),
            TextInput::make('sort')
                ->label('Order')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('An inactive type can no longer be chosen for new items.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('description')->placeholder('-')->limit(60),
                IconColumn::make('is_mileage')->label('Mileage')->boolean(),
                TextColumn::make('rate_per_km')->label('Rate per km')->money(Claim::CURRENCY)->placeholder('-'),
                IconColumn::make('requires_receipt')->label('Receipt required')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('sort')->label('Order')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                // A type already used by a claim is kept (the claims show it): deactivate it instead
                DeleteAction::make()->visible(fn (ClaimType $record) => ! $record->items()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaimTypes::route('/'),
        ];
    }
}
