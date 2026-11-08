<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }
    // No password is set here: EmployeeObserver gives a random one, and the employee chooses theirs
    // with "Forgot password" on the login page. It is never derived from the email.
    protected function afterCreate(): void
    {
        $this->record->assignRole('employee');
    }

}
