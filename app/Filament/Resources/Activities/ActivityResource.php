<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit trail: who created, changed or deleted which record, and what changed. Read-only; the
 * entries are written by the models that use App\Models\Concerns\Audited.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $label = 'Activity';

    protected static ?string $pluralLabel = 'Activity log';

    protected static ?string $navigationLabel = 'Activity log';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 9;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['causer', 'subject' => fn ($morph) => $morph->withTrashed()]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('causer.name')->label('Who')->placeholder('System'),
                TextColumn::make('event')->label('Action')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('log_name')->label('Record')->sortable(),
                TextColumn::make('subject_id')->label('ID')
                    ->formatStateUsing(fn ($state) => $state ? "#$state" : null),
                TextColumn::make('changes_summary')->label('Changes')
                    ->state(fn (Activity $record) => self::summary($record))
                    ->limit(80)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Record')
                    ->options(fn () => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name', 'log_name')->all()),
                SelectFilter::make('event')->label('Action')
                    ->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
            ])
            ->recordActions([
                Action::make('changes')->label('Details')->icon('heroicon-o-eye')
                    ->modalHeading(fn (Activity $record) => "{$record->log_name} #{$record->subject_id} {$record->event}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Activity $record) => view('filament.activities.changes', ['activity' => $record])),
            ]);
    }

    /**
     * "field: old → new" for an update, the field names for a creation or a deletion.
     */
    public static function summary(Activity $activity): string
    {
        $new = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];

        if ($activity->event === 'updated') {
            return collect($new)->map(fn ($value, $field) => $field.': '.self::show($old[$field] ?? null).' → '.self::show($value))->implode('; ');
        }

        return collect($activity->event === 'deleted' ? ($old ?: $new) : $new)->keys()->implode(', ');
    }

    public static function show(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
