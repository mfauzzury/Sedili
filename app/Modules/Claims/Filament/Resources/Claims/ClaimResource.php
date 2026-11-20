<?php

namespace App\Modules\Claims\Filament\Resources\Claims;

use App\Modules\Claims\Filament\ClaimActions;
use App\Modules\Claims\Filament\ClaimInfolist;
use App\Modules\Claims\Filament\Resources\Claims\Pages\ListClaims;
use App\Modules\Claims\Filament\Resources\Claims\Pages\ViewClaim;
use App\Modules\Claims\Models\Claim;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin panel: the claims of every employee, to approve or reject and then to mark as paid. Drafts
 * stay private to their owner. Claims are made by the employees in the portal, never here.
 */
class ClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Claims';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'Claim';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('employee')
            ->where('status', '!=', Claim::DRAFT);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getEloquentQuery()->where('status', Claim::SUBMITTED)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClaimInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('items_count')->counts('items')->label('Items'),
                TextColumn::make('total')->money(Claim::CURRENCY)->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state) => Claim::statusColor($state)),
                TextColumn::make('submitted_at')->label('Submitted')->date()->sortable(),
                TextColumn::make('paid_at')->label('Paid on')->date()->placeholder('-')->sortable(),
                TextColumn::make('payment_reference')->label('Payment reference')->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                ClaimActions::approve(),
                ClaimActions::reject(),
                ClaimActions::markPaid(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClaims::route('/'),
            'view' => ViewClaim::route('/{record}'),
        ];
    }
}
