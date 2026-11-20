<?php

namespace App\Modules\Claims\Filament\Portal;

use App\Models\Employee;
use App\Modules\Claims\Filament\ClaimActions;
use App\Modules\Claims\Filament\ClaimInfolist;
use App\Modules\Claims\Filament\Portal\Pages\ListTeamClaims;
use App\Modules\Claims\Filament\Portal\Pages\ViewTeamClaim;
use App\Modules\Claims\Models\Claim;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Employee portal: a supervisor follows and decides the claims of the employees who report to them.
 * Only visible to employees who have a team. Drafts stay private to their owner.
 *
 * Everything is scoped on the server: the query only returns the claims of the team of the logged in
 * employee, and a decision is checked again by ClaimApproval.
 */
class TeamClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static ?string $slug = 'team-claims';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Claims';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Team Claims';

    protected static ?string $modelLabel = 'Claim';

    protected static ?string $pluralModelLabel = 'Team Claims';

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        return parent::getEloquentQuery()
            ->with('employee')
            ->where('status', '!=', Claim::DRAFT)
            ->whereIn(
                'employee_id',
                $user instanceof Employee ? Employee::query()->reportingTo($user)->select('employees.id') : []
            );
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof Employee && Employee::query()->reportingTo($user)->exists();
    }

    // Claims are only decided with the actions
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
            ->defaultSort('submitted_at')
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('items_count')->counts('items')->label('Items'),
                TextColumn::make('total')->money(Claim::CURRENCY)->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state) => Claim::statusColor($state)),
                TextColumn::make('submitted_at')->label('Submitted')->date()->sortable(),
                TextColumn::make('rejection_reason')->label('Reason of the rejection')->placeholder('-')->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        [Claim::SUBMITTED, Claim::APPROVED, Claim::PAID, Claim::REJECTED],
                        ['Waiting for approval', 'Approved', 'Paid', 'Rejected'],
                    ))
                    ->default(Claim::SUBMITTED),
            ])
            ->recordActions([
                ViewAction::make(),
                ClaimActions::approve(),
                ClaimActions::reject(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamClaims::route('/'),
            'view' => ViewTeamClaim::route('/{record}'),
        ];
    }
}
