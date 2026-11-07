<?php

use App\Models\Employee;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Create an administrator (self-registration is disabled on the admin panel)
Artisan::command('app:create-admin {email} {--first=Admin} {--last=User}', function (string $email) {
    if (Employee::where('email', $email)->exists()) {
        $this->error("An employee with the email $email already exists.");
        return 1;
    }
    $password = $this->secret('Password (at least 8 characters)');
    if (strlen((string) $password) < 8) {
        $this->error('The password must contain at least 8 characters.');
        return 1;
    }
    $admin = new Employee();
    $admin->forceFill([
        'first_name' => $this->option('first'),
        'last_name' => $this->option('last'),
        'email' => $email,
        'password' => $password,
        'employment_type' => 'Permanent',
    ])->save();
    $admin->assignRole('admin');
    $this->info("Administrator $email created.");
    return 0;
})->purpose('Create an administrator account');
