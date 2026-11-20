<?php

namespace App\Modules\Claims\Filament;

use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Models\ClaimItem;
use App\Modules\Claims\Services\ClaimReceipt;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The claim as everybody sees it (owner, supervisor, admin): its state and its items. The pages that
 * use it load the relations first (see ViewsClaim).
 */
final class ClaimInfolist
{
    public static function configure(Schema $schema, bool $showEmployee = true): Schema
    {
        return $schema->components([
            Section::make('Claim')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('employee.name')->label('Employee')->visible($showEmployee),
                    TextEntry::make('title'),
                    TextEntry::make('status')->badge()->color(fn (?string $state) => Claim::statusColor($state)),
                    TextEntry::make('total')->money(Claim::CURRENCY)->weight('bold'),
                    TextEntry::make('submitted_at')->label('Submitted')->dateTime()->placeholder('Not submitted'),
                    TextEntry::make('actionedBy.name')->label('Decided by')->placeholder('-')
                        ->visible(fn (Claim $record) => $record->actioned_by !== null),
                    TextEntry::make('rejection_reason')->label('Reason of the rejection')->color('danger')
                        ->visible(fn (Claim $record) => $record->status === Claim::REJECTED),
                    TextEntry::make('paid_at')->label('Paid on')->date()
                        ->visible(fn (Claim $record) => $record->status === Claim::PAID),
                    TextEntry::make('payment_reference')->label('Payment reference')->placeholder('-')
                        ->visible(fn (Claim $record) => $record->status === Claim::PAID),
                    TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('Items')
                ->columnSpanFull()
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Date'),
                            TableColumn::make('Type'),
                            TableColumn::make('Description'),
                            TableColumn::make('Distance'),
                            TableColumn::make('Amount'),
                            TableColumn::make('Receipt'),
                        ])
                        ->schema([
                            TextEntry::make('date')->date(),
                            TextEntry::make('type.name'),
                            TextEntry::make('description'),
                            TextEntry::make('distance_km')
                                ->state(fn (ClaimItem $record) => $record->distance_km !== null
                                    ? "{$record->distance_km} km × ".Claim::formatAmount($record->rate)
                                    : '-'),
                            TextEntry::make('amount')->money(Claim::CURRENCY),
                            TextEntry::make('receipt')
                                ->state(fn (ClaimItem $record) => $record->receipt
                                    ? 'View'
                                    : ($record->type?->requires_receipt ? 'Missing' : '-'))
                                ->color(fn (ClaimItem $record) => $record->receipt
                                    ? 'primary'
                                    : ($record->type?->requires_receipt ? 'danger' : null))
                                ->url(fn (ClaimItem $record) => ClaimReceipt::url($record))
                                ->openUrlInNewTab(),
                        ]),
                ]),
        ]);
    }

    /**
     * The relations the infolist shows (lazy loading is forbidden in this application).
     *
     * @return list<string>
     */
    public static function relations(): array
    {
        return ['employee', 'items.type', 'actionedBy', 'paidBy'];
    }
}
