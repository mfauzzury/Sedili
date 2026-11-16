<?php

namespace App\Filament\Pages;

use App\Services\CompanyProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Logo, favicon and details of the company. The logo and the name are shown in both panels
 * (menu and login page), the favicon in the browser tab; the other details are available for documents.
 */
class CompanySettings extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-building-office-2';
    protected static string|\UnitEnum|null $navigationGroup = 'System';
    protected static ?int $navigationSort = 2;
    protected static ?string $title = 'Company';

    protected string $view = 'filament.pages.company-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(CompanyProfile::class)->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Branding')
                    ->description('The logo and name are shown in the menu and on the login page of the admin panel and of the employee portal; the favicon in the browser tab.')
                    ->schema([
                        FileUpload::make('logo')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(2048)
                            ->disk(CompanyProfile::DISK)
                            ->directory(CompanyProfile::DIRECTORY)
                            ->visibility('public')
                            ->imagePreviewHeight('80')
                            ->extraAttributes(['class' => 'company-logo-upload'])
                            ->helperText('PNG, JPG or WebP, 2 MB at most. A wide logo on a transparent background works best.'),
                        FileUpload::make('favicon')
                            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'])
                            ->maxSize(512)
                            ->disk(CompanyProfile::DISK)
                            ->directory(CompanyProfile::DIRECTORY)
                            ->visibility('public')
                            ->imagePreviewHeight('48')
                            ->extraAttributes(['class' => 'company-logo-upload'])
                            ->helperText('Icon shown in the browser tab. A square PNG of at least 64 x 64 px, or an ICO file, 512 KB at most.'),
                        TextInput::make('name')
                            ->label('Company name')
                            ->maxLength(255)
                            ->helperText('Replaces "Admin Panel" and "Portal" in the menu when no logo is set.'),
                    ]),
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('registration_no')->label('Registration number')->maxLength(100),
                        TextInput::make('website')->url()->maxLength(255),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(50),
                        Textarea::make('address')->rows(3)->maxLength(1000)->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $values = $this->form->getState();
        // A single file is returned as a path, or as an array depending on the version
        foreach (CompanyProfile::FILE_FIELDS as $field) {
            if (is_array($values[$field] ?? null)) {
                $values[$field] = array_values($values[$field])[0] ?? null;
            }
        }

        app(CompanyProfile::class)->update($values);

        Notification::make()->title('Company details saved')->success()->send();

        // Reload so that the new logo, favicon and name show up in the layout
        $this->redirect(static::getUrl());
    }
}
