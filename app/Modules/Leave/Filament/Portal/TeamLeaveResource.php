<?php

namespace App\Modules\Leave\Filament\Portal;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\Pages\ListTeamLeaves;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveApproval;
use App\Modules\Leave\Services\LeaveAttachment;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Employee portal: a supervisor follows and decides the leave requests of the employees who
 * report to them. Only visible to employees who have a team.
 *
 * Everything is scoped on the server: the query only returns the requests of the team of the
 * logged in employee, and a decision is checked again by LeaveApproval.
 */
class TeamLeaveResource extends Resource
{
    protected static ?string $model = Leave::class;
    protected static ?string $slug = 'team-leave';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'Team Leave';
    protected static ?string $modelLabel = 'Leave request';
    protected static ?string $pluralModelLabel = 'Team Leave';

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        return parent::getEloquentQuery()
            ->with('employee')
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

    // Requests are only decided with the actions of the list
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
        $pending = static::getEloquentQuery()->where('status', 'Pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date')
            ->columns([
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('leave_type')->label('Leave type')->searchable(),
                TextColumn::make('start_date')->date()->label('From')->sortable(),
                TextColumn::make('end_date')->date()->label('To'),
                TextColumn::make('days')->label('Days'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'Pending' => 'warning',
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('notes')->label('Notes')->placeholder('-')->limit(60),
                TextColumn::make('attachment')->label('Evidence')
                    ->state(fn(Leave $record) => $record->attachment
                        ? 'View'
                        : (LeaveType::requiresEvidence($record->leave_type) ? 'Missing' : '-'))
                    ->color(fn(Leave $record) => $record->attachment ? 'primary' : (LeaveType::requiresEvidence($record->leave_type) ? 'danger' : null))
                    ->url(fn(Leave $record) => LeaveAttachment::url($record))
                    ->openUrlInNewTab(),
                TextColumn::make('rejection_reason')->label('Reason of the rejection')->placeholder('-')->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['Pending' => 'Pending', 'Approved' => 'Approved', 'Rejected' => 'Rejected'])
                    ->default('Pending'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn(Leave $record) => "Approve the leave of {$record->employee->name}?")
                    ->visible(fn(Leave $record) => self::canDecide($record))
                    ->action(fn(Leave $record) => self::decide($record, 'Approved')),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->modalHeading(fn(Leave $record) => "Reject the leave of {$record->employee->name}?")
                    ->schema([
                        Textarea::make('reason')
                            ->label('Reason of the rejection')
                            ->helperText('The employee receives it by email.')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->visible(fn(Leave $record) => self::canDecide($record))
                    ->action(fn(Leave $record, array $data) => self::decide($record, 'Rejected', $data['reason'] ?? null)),
            ]);
    }

    public static function canDecide(Leave $record): bool
    {
        $user = Auth::user();

        return $user instanceof Employee && app(LeaveApproval::class)->canDecide($user, $record);
    }

    /**
     * @param 'Approved'|'Rejected' $decision
     */
    private static function decide(Leave $record, string $decision, ?string $reason = null): void
    {
        // Checked again on the server: the visibility only hides the buttons
        $errors = app(LeaveApproval::class)->decide($record, Auth::user(), $decision, $reason);

        if ($errors !== []) {
            Notification::make()->title('The request was not decided')->body(implode("\n", $errors))->danger()->send();
            return;
        }

        Notification::make()
            ->title($decision === 'Approved' ? 'Leave request approved' : 'Leave request rejected')
            ->success()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamLeaves::route('/'),
        ];
    }
}
