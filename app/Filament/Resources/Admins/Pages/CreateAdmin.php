<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }
    // No password is set here: EmployeeObserver gives a random one, and the admin chooses theirs
    // with "Forgot password" on the login page. It is never derived from the email.
    protected function afterCreate(): void
    {
        $this->record->assignRole('admin');
    }
}
