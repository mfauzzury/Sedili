<?php

namespace App\Modules\Claims\Filament\Resources\Claims\Pages;

use App\Modules\Claims\Filament\Resources\Claims\ClaimResource;
use App\Modules\Claims\Models\Claim;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListClaims extends ListRecords
{
    protected static string $resource = ClaimResource::class;

    public function getTabs(): array
    {
        $counts = ClaimResource::getEloquentQuery()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tab = fn (string $label, string $status, ?string $color = null) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status))
            ->badge(($counts[$status] ?? 0) ?: null)
            ->badgeColor($color);

        return [
            'submitted' => $tab('To approve', Claim::SUBMITTED, 'warning'),
            'approved' => $tab('To pay', Claim::APPROVED, 'info'),
            'paid' => $tab('Paid', Claim::PAID),
            'rejected' => $tab('Rejected', Claim::REJECTED),
            'all' => Tab::make('All'),
        ];
    }
}
