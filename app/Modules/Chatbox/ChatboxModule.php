<?php

namespace App\Modules\Chatbox;

use App\Modules\Chatbox\ChatTools\ProfileTool;
use App\Modules\Chatbox\Filament\Pages\ChatboxSettingsPage;
use App\Modules\Chatbox\Livewire\ChatWidget;
use App\Modules\Chatbox\Reports\ChatboxUsageReport;
use App\Modules\Chatbox\Services\AnthropicGateway;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\Chatbox\Services\ClaudeGateway;
use App\Modules\Module;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;

class ChatboxModule extends Module
{
    public function key(): string
    {
        return 'chatbox';
    }

    public function label(): string
    {
        return 'Chatbox';
    }

    public function description(): string
    {
        return 'Employees ask an AI assistant (Claude) about company policies; it answers from the handbook. Add the Claude API key and the handbook in System > Chatbox.';
    }

    public function filament(string $panelId): array
    {
        return match ($panelId) {
            'admin' => ['pages' => [ChatboxSettingsPage::class]],
            // The chat bubble is shown by a render hook (see boot()), not on the dashboard
            'portal' => ['livewire' => [ChatWidget::class]],
            default => [],
        };
    }

    public function settingsUrl(): ?string
    {
        return ChatboxSettingsPage::getUrl(panel: 'admin');
    }

    public function chatTools(): array
    {
        return [new ProfileTool];
    }

    public function reports(): array
    {
        return [new ChatboxUsageReport];
    }

    public function boot(): void
    {
        app()->bind(ClaudeGateway::class, AnthropicGateway::class);

        // The chat bubble on every page of the employee portal, once the assistant is set up
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => Filament::getCurrentPanel()?->getId() === 'portal'
                && Auth::check()
                && app(ChatboxSettings::class)->isReady()
                    ? Blade::render('@livewire($component)', ['component' => ChatWidget::class])
                    : '',
        );
    }
}
