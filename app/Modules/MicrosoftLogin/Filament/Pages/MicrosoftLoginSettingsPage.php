<?php

namespace App\Modules\MicrosoftLogin\Filament\Pages;

use App\Modules\MicrosoftLogin\Services\MicrosoftLoginSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The Microsoft Entra ID app registration used for "Sign in with Microsoft".
 */
class MicrosoftLoginSettingsPage extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Microsoft sign-in';

    protected static ?string $title = 'Microsoft sign-in';

    protected static ?string $slug = 'microsoft-sign-in';

    protected string $view = 'microsoft_login::settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $settings = app(MicrosoftLoginSettings::class);
        $this->getSchema('form')?->fill($settings->all() + [
            'client_secret' => null,
            'redirect_uri' => $settings->redirectUri(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $enabled = fn (Get $get): bool => (bool) $get('enabled');
        $guid = 'regex:'.MicrosoftLoginSettings::GUID_PATTERN;

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Sign-in page')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Show "Sign in with Microsoft" on the sign-in page')
                            ->helperText('Employees sign in with their Microsoft work account. Only existing, active employees whose email is the same as their Microsoft email can sign in. Signing in with a password still works.')
                            ->live(),
                    ]),
                Section::make('Microsoft Entra app registration')
                    ->description('Create it in the Microsoft Entra admin center: App registrations > New registration, "Accounts in this organizational directory only", platform "Web".')
                    ->columns(2)
                    ->schema([
                        TextInput::make('redirect_uri')
                            ->label('Redirect URI')
                            ->helperText('Add it to the app registration exactly as it is (Authentication > Web > Redirect URIs). Outside your own computer it must start with https://.')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            ->columnSpanFull(),
                        TextInput::make('tenant_id')
                            ->label('Directory (tenant) ID')
                            ->placeholder('00000000-0000-0000-0000-000000000000')
                            ->helperText('On the Overview page of the app registration.')
                            ->required($enabled)
                            ->rule($guid)
                            ->validationMessages(['regex' => 'Enter the ID as shown in Microsoft Entra, for example 1b2c3d4e-....'])
                            ->maxLength(36),
                        TextInput::make('client_id')
                            ->label('Application (client) ID')
                            ->placeholder('00000000-0000-0000-0000-000000000000')
                            ->helperText('On the Overview page of the app registration.')
                            ->required($enabled)
                            ->rule($guid)
                            ->validationMessages(['regex' => 'Enter the ID as shown in Microsoft Entra, for example 1b2c3d4e-....'])
                            ->maxLength(36),
                        TextInput::make('client_secret')
                            ->label('Client secret (value)')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('enabled') && ! app(MicrosoftLoginSettings::class)->hasSecret())
                            ->helperText(fn (): string => app(MicrosoftLoginSettings::class)->hasSecret()
                                ? 'A secret is saved. Leave empty to keep it. Microsoft secrets expire: enter the new one here before that.'
                                : 'Certificates & secrets > New client secret. Copy the Value (not the Secret ID).')
                            ->columnSpanFull(),
                        Text::make('Microsoft asks for the "User.Read" permission (Microsoft Graph, delegated). It is added by default to a new app registration.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(): void
    {
        app(MicrosoftLoginSettings::class)->update($this->getSchema('form')?->getState() ?? []);

        Notification::make()->title('Microsoft sign-in settings saved')->success()->send();

        $this->fillForm();
    }
}
