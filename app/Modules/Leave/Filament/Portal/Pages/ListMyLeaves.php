<?php

namespace App\Modules\Leave\Filament\Portal\Pages;

use App\Models\Employee;
use App\Modules\Leave\Filament\Portal\MyLeaveResource;
use App\Modules\Leave\Filament\Widgets\MyLeaveBalances;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListMyLeaves extends ListRecords
{
    protected static string $resource = MyLeaveResource::class;

    protected function getHeaderWidgets(): array
    {
        return [MyLeaveBalances::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Request leave')
                ->modalHeading('Request leave')
                ->modalSubmitActionLabel('Send request')
                ->createAnother(false)
                // The employee and the status are set here, whatever the form contains
                ->mutateDataUsing(fn(array $data): array => [
                    ...$data,
                    'employee_id' => Auth::id(),
                    'status' => 'Pending',
                ])
                ->successNotificationTitle('Leave request sent')
                ->after(function () {
                    $employee = Auth::user();
                    Notification::make()
                        ->title('New leave request')
                        ->body("{$employee->name} requested leave.")
                        ->icon('heroicon-o-user-minus')
                        ->sendToDatabase(Employee::role('admin')->get());
                }),
        ];
    }
}
