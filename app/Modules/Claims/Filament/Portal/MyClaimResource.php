<?php

namespace App\Modules\Claims\Filament\Portal;

use App\Models\Employee;
use App\Modules\Claims\Filament\ClaimActions;
use App\Modules\Claims\Filament\ClaimInfolist;
use App\Modules\Claims\Filament\Portal\Pages\CreateMyClaim;
use App\Modules\Claims\Filament\Portal\Pages\EditMyClaim;
use App\Modules\Claims\Filament\Portal\Pages\ListMyClaims;
use App\Modules\Claims\Filament\Portal\Pages\ViewMyClaim;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimType;
use App\Modules\Claims\Services\ClaimReceipt;
use App\Modules\Claims\Services\ClaimSubmission;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Employee portal: an employee makes expense claims and follows them.
 *
 * Everything is scoped to the logged in employee: the query only returns their claims, the employee
 * and the status are set on the server (never read from the form), and a claim can only be changed
 * while it is a draft of its owner (ClaimSubmission, and ClaimItem for the items).
 */
class MyClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static ?string $slug = 'my-claims';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Claims';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'My Claims';

    protected static ?string $modelLabel = 'Claim';

    protected static ?string $pluralModelLabel = 'My Claims';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('employee_id', Auth::id());
    }

    public static function canEdit(Model $record): bool
    {
        $user = Auth::user();

        return $user instanceof Employee && $record instanceof Claim && app(ClaimSubmission::class)->canEdit($user, $record);
    }

    // Drafts are deleted with ClaimActions::delete(), which checks the rules
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('For example: Client visit to Penang, November travel'),
                    Textarea::make('notes')->rows(2)->maxLength(2000),
                ]),
            Repeater::make('items')
                ->relationship()
                ->label('Items')
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('Add item')
                ->columns(3)
                ->columnSpanFull()
                ->itemLabel(fn (array $state) => filled($state['description'] ?? null) ? $state['description'] : null)
                ->schema([
                    Select::make('claim_type_id')
                        ->label('Type')
                        ->options(fn () => ClaimType::query()->active()->pluck('name', 'id')->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::previewMileage($get, $set)),
                    DatePicker::make('date')
                        ->required()
                        ->maxDate(now())
                        ->default(now()),
                    TextInput::make('description')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('For example: Lunch with the client'),
                    TextInput::make('distance_km')
                        ->label('Distance')
                        ->suffix('km')
                        ->numeric()
                        ->minValue(0.1)
                        ->maxValue(10000)
                        ->required(fn (Get $get) => self::isMileage($get('claim_type_id')))
                        ->visible(fn (Get $get) => self::isMileage($get('claim_type_id')))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::previewMileage($get, $set)),
                    TextInput::make('amount')
                        ->prefix(Claim::CURRENCY)
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(1000000)
                        ->required(fn (Get $get) => ! self::isMileage($get('claim_type_id')))
                        // A mileage amount is calculated on the server (ClaimItem): the field only shows it
                        ->disabled(fn (Get $get) => self::isMileage($get('claim_type_id')))
                        ->dehydrated(fn (Get $get) => ! self::isMileage($get('claim_type_id')))
                        ->helperText(fn (Get $get) => self::isMileage($get('claim_type_id'))
                            ? 'Distance × '.Claim::formatAmount(self::rate($get('claim_type_id'))).' per km.'
                            : null),
                    ClaimReceipt::field(fn (Get $get): bool => ClaimType::requiresReceipt($get('claim_type_id')))
                        ->columnSpan(2),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ClaimInfolist::configure($schema, showEmployee: false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('items_count')->counts('items')->label('Items'),
                TextColumn::make('total')->money(Claim::CURRENCY)->sortable(),
                TextColumn::make('status')->badge()->color(fn (?string $state) => Claim::statusColor($state)),
                TextColumn::make('submitted_at')->label('Submitted')->date()->placeholder('-')->sortable(),
                TextColumn::make('paid_at')->label('Paid on')->date()->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rejection_reason')->label('Reason of the rejection')->placeholder('-')->limit(60),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ClaimActions::submit(),
                ClaimActions::withdraw(),
                ClaimActions::delete(static::getUrl('index')),
            ]);
    }

    public static function isMileage(mixed $typeId): bool
    {
        return filled($typeId) && (bool) ClaimType::query()->whereKey($typeId)->value('is_mileage');
    }

    private static function rate(mixed $typeId): float
    {
        return filled($typeId) ? (float) ClaimType::query()->whereKey($typeId)->value('rate_per_km') : 0.0;
    }

    /**
     * Shows the amount of a mileage item while the employee types the distance (the saved amount is
     * calculated again on the server).
     */
    private static function previewMileage(Get $get, Set $set): void
    {
        if (self::isMileage($get('claim_type_id'))) {
            $set('amount', number_format((float) $get('distance_km') * self::rate($get('claim_type_id')), 2, '.', ''));
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyClaims::route('/'),
            'create' => CreateMyClaim::route('/create'),
            'view' => ViewMyClaim::route('/{record}'),
            'edit' => EditMyClaim::route('/{record}/edit'),
        ];
    }
}
