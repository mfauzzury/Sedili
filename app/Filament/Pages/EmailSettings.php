<?php

namespace App\Filament\Pages;

use App\Modules\ModuleManager;
use App\Services\MailSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SMTP server used to send the notification emails (leave requests, etc.).
 * While it is off, the MAIL_* values of the .env file are used.
 */
class EmailSettings extends Page
{
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-envelope';
    protected static string|\UnitEnum|null $navigationGroup = 'System';
    protected static ?int $navigationSort = 3;
    protected static ?string $title = 'Email';

    protected string $view = 'filament.pages.email-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Label of the sample email shown in the preview */
    public ?string $previewEmail = null;

    public function mount(): void
    {
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->form->fill(app(MailSettings::class)->all() + ['password' => null]);
    }

    public function form(Schema $schema): Schema
    {
        $enabled = fn(Get $get): bool => (bool) $get('enabled');

        return $schema
            ->statePath('data')
            ->components([
                Section::make('SMTP server')
                    ->description('The server that sends the notification emails. While this is off, the MAIL_* values of the .env file are used.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Send emails through this SMTP server')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('host')
                            ->placeholder('smtp.example.com')
                            ->required($enabled)
                            ->maxLength(255),
                        TextInput::make('port')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->default(587)
                            ->required($enabled),
                        Select::make('scheme')
                            ->label('Encryption')
                            ->options([
                                'smtp' => 'STARTTLS or none (usually port 587 or 25)',
                                'smtps' => 'SSL / TLS (usually port 465)',
                            ])
                            ->default('smtp')
                            ->required($enabled)
                            ->native(false),
                        TextInput::make('username')
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(255)
                            ->helperText(fn(): ?string => app(MailSettings::class)->hasPassword()
                                ? 'A password is saved. Leave empty to keep it.'
                                : null),
                    ]),
                Section::make('Sender')
                    ->description('Shown as the sender of the emails.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('from_address')
                            ->label('From address')
                            ->email()
                            ->required($enabled)
                            ->maxLength(255),
                        TextInput::make('from_name')
                            ->label('From name')
                            ->placeholder('The company name')
                            ->maxLength(255),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send test email')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->action(fn() => $this->sendTest()),
        ];
    }

    /**
     * Labels of the sample emails of the enabled modules.
     *
     * @return list<string>
     */
    public function getPreviewOptions(): array
    {
        return array_keys($this->previewBuilders());
    }

    /**
     * HTML of the selected sample email, as it is sent (with the logo and the name of the company).
     */
    public function getPreviewHtml(): ?string
    {
        $builders = $this->previewBuilders();
        $builder = $builders[$this->previewEmail] ?? reset($builders);

        return $builder ? (string) $builder(Auth::user())->render() : null;
    }

    /**
     * @return array<string, \Closure>
     */
    private function previewBuilders(): array
    {
        $builders = [];
        foreach (app(ModuleManager::class)->enabled() as $module) {
            $builders += $module->emailPreviews();
        }
        return $builders;
    }

    public function save(): void
    {
        app(MailSettings::class)->update($this->form->getState());

        Notification::make()->title('Email settings saved')->success()->send();

        $this->fillForm();
    }

    /**
     * Send a test email to the admin, with the values of the form (saved or not).
     */
    public function sendTest(): void
    {
        $settings = app(MailSettings::class);
        $state = $this->form->getState();

        if (blank($state['host'] ?? null) || blank($state['from_address'] ?? null)) {
            Notification::make()->title('Enter the host and the from address first')->warning()->send();
            return;
        }

        // A blank password means the saved one
        $settings->applyValues($state + ['password' => null]);
        if (blank($state['password'] ?? null)) {
            config(['mail.mailers.smtp.password' => $settings->password()]);
        }
        config(['mail.mailers.smtp.timeout' => 10]);
        Mail::purge('smtp');

        $to = Auth::user()->email;
        try {
            Mail::mailer('smtp')->raw(
                'This is a test email. If you can read it, the email settings work.',
                fn($message) => $message->to($to)->subject('Test email')
            );
        } catch (Throwable $e) {
            Notification::make()
                ->title('The test email could not be sent')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        Notification::make()->title("Test email sent to {$to}")->success()->send();
    }
}
