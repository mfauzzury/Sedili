<?php

namespace App\Filament\Pages;

use App\Services\CompanyProfile;
use App\Services\OrgChart as OrgChartData;
use Filament\Pages\Page;

/**
 * The hierarchy of the company, from the C-level down: each person under the one they report to,
 * each department under the person its head reports to.
 */
class OrgChart extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-share';
    protected static string|\UnitEnum|null $navigationGroup = 'Organization';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Org Chart';
    protected static ?string $title = 'Org Chart';

    protected string $view = 'filament.pages.org-chart';

    /**
     * @return array{company: string, logo: string|null, tree: list<array<string, mixed>>, departments: int, employees: int}
     */
    public function getChart(): array
    {
        $company = app(CompanyProfile::class);

        return [
            'company' => $company->name() ?? config('app.name'),
            'logo' => $company->logoUrl(),
            ...app(OrgChartData::class)->build(),
        ];
    }
}
