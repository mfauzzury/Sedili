<?php

namespace App\Modules\Leave\Filament\Portal;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\Pages\ListMyLeaves;
use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveAttachment;
use App\Modules\Leave\Services\LeaveService;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\{DatePicker, Select, Textarea};
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Employee portal: an employee requests leave and follows their own requests.
 *
 * Everything is scoped to the logged in employee: the query only returns their requests, the
 * employee and the status of a new request are set on the server (never read from the form),
 * and a request can only be cancelled by its owner while it is pending.
 */
class MyLeaveResource extends Resource
{
    protected static ?string $model = Leave::class;
    protected static ?string $slug = 'my-leave';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-minus';
    protected static ?string $navigationLabel = 'My Leave';
    protected static ?string $modelLabel = 'Leave request';
    protected static ?string $pluralModelLabel = 'My Leave';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('employee_id', Auth::id());
    }

    // Requests are created and cancelled with the actions of the list, never edited or deleted
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

    /**
     * Leave types the logged in employee may request.
     *
     * @return array<string, string>
     */
    public static function eligibleTypes(?Employee $employee): array
    {
        return LeaveType::query()
            ->where('is_active', true)
            ->where(fn(Builder $query) => $query
                ->whereNull('gender_restriction')
                ->orWhere('gender_restriction', $employee?->gender))
            ->orderBy('id')
            ->pluck('name', 'name')
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('leave_type')
                ->label('Leave type')
                ->options(fn() => self::eligibleTypes(Auth::user()))
                ->live()
                ->helperText(fn(Get $get) => self::balanceText($get))
                ->required(),
            DatePicker::make('start_date')
                ->required()
                ->live()
                ->label('Start Date'),
            DatePicker::make('end_date')
                ->required()
                ->label('End Date')
                ->rules([
                    fn(Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                        if (blank($get('leave_type'))) {
                            return;
                        }
                        $errors = app(LeaveService::class)->validate(
                            (int) Auth::id(),
                            (string) $get('leave_type'),
                            $get('start_date'),
                            $value
                        );
                        foreach ($errors as $error) {
                            $fail($error);
                        }
                    },
                ]),
            Textarea::make('notes')
                ->label('Reason / notes')
                ->nullable()
                ->columnSpanFull(),
            LeaveAttachment::field(fn(Get $get): bool => LeaveType::requiresEvidence($get('leave_type')))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
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
                TextColumn::make('attachment')->label('Evidence')
                    ->state(fn(Leave $record) => $record->attachment ? 'View' : '-')
                    ->color('primary')
                    ->url(fn(Leave $record) => LeaveAttachment::url($record))
                    ->openUrlInNewTab(),
                TextColumn::make('rejection_reason')->label('Reason of the rejection')->placeholder('-')->limit(60),
                TextColumn::make('notes')->label('Notes')->placeholder('-')->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('attachEvidence')
                    ->label('Attach evidence')
                    ->icon('heroicon-o-paper-clip')
                    ->modalHeading('Attach evidence')
                    ->modalDescription('For example a medical certificate. It is only visible to you, your supervisor and HR.')
                    ->modalSubmitActionLabel('Attach')
                    ->schema([
                        LeaveAttachment::field(fn(): bool => true),
                    ])
                    ->visible(fn(Leave $record) => self::canAttachEvidence($record))
                    ->action(function (Leave $record, array $data) {
                        // Checked again on the server: the visibility only hides the button
                        if (!self::canAttachEvidence($record) || blank($data['attachment'] ?? null)) {
                            return;
                        }
                        $record->update(['attachment' => $data['attachment']]);
                        Notification::make()->title('Evidence attached')->success()->send();
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this leave request?')
                    ->visible(fn(Leave $record) => self::isCancellable($record))
                    ->action(function (Leave $record) {
                        // Checked again on the server: the visibility only hides the button
                        if (!self::isCancellable($record)) {
                            return;
                        }
                        $record->delete();
                        Notification::make()->title('Leave request cancelled')->success()->send();
                    }),
            ]);
    }

    /**
     * The owner can add the document later to a request that has none, unless it was rejected.
     */
    public static function canAttachEvidence(Leave $record): bool
    {
        return blank($record->attachment)
            && $record->status !== 'Rejected'
            && (int) $record->employee_id === (int) Auth::id();
    }

    public static function isCancellable(Leave $record): bool
    {
        return $record->status === 'Pending' && (int) $record->employee_id === (int) Auth::id();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyLeaves::route('/'),
        ];
    }

    /**
     * Remaining balance shown under the leave type.
     */
    private static function balanceText(Get $get): ?string
    {
        $employee = Auth::user();
        $type = filled($get('leave_type')) ? LeaveType::where('name', $get('leave_type'))->first() : null;
        if (!$employee instanceof Employee || $type === null) {
            return null;
        }
        $service = app(LeaveService::class);
        $year = filled($get('start_date')) ? Carbon::parse($get('start_date'))->year : (int) now()->year;
        $entitlement = $service->entitlement($employee, $type, $year);
        if ($entitlement === null) {
            return "{$type->name}: no limit.";
        }
        $balance = $service->balance($employee, $type, $year);
        return sprintf(
            '%s balance in %d: %s of %s day(s) left.',
            $type->name,
            $year,
            $service->format($balance),
            $service->format($entitlement)
        );
    }
}
