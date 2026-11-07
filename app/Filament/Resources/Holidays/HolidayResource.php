<?php

namespace App\Filament\Resources\Holidays;

use App\Filament\Resources\Holidays\Pages\ListHolidays;
use App\Models\Holiday;
use Filament\Actions\{BulkActionGroup, DeleteAction, DeleteBulkAction, EditAction};
use Filament\Forms\Components\{DatePicker, TextInput};
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidayResource extends Resource
{
    protected static ?string $model = Holiday::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';
    protected static string|\UnitEnum|null $navigationGroup = 'HR Management';
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = 'Public Holiday';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')
                ->required()
                ->unique(ignoreRecord: true),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('name')->searchable(),
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
            'index' => ListHolidays::route('/'),
        ];
    }
}
