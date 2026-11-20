<?php

namespace App\Modules\Chatbox\Services;

use App\Modules\Chatbox\Models\ChatDocument;
use App\Modules\ModuleManager;
use App\Services\SettingsStore;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The settings of the chatbox, set in System > Chatbox and stored in the settings table
 * ("chatbox.{field}"). The Claude API key is stored encrypted and never shown again.
 */
class ChatboxSettings
{
    public const FIELDS = ['enabled', 'api_key', 'model', 'extra_instructions', 'hr_contact', 'hourly_limit', 'tools'];

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    public const DEFAULT_HOURLY_LIMIT = 30;

    /** @var array<string, string> model id => label */
    public const MODELS = [
        'claude-opus-5-5' => 'Claude Opus 5.5 (most capable)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (faster, cheaper)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (fastest, cheapest)',
    ];

    public function __construct(
        private readonly SettingsStore $store,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * The saved values, without the API key (see hasApiKey()).
     *
     * @return array{enabled: bool, model: string, extra_instructions: string|null, hr_contact: string|null, hourly_limit: int}
     */
    public function all(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'model' => $this->model(),
            'extra_instructions' => $this->extraInstructions(),
            'hr_contact' => $this->hrContact(),
            'hourly_limit' => $this->hourlyLimit(),
        ];
    }

    /** The admin switched the assistant on (it is not ready until it is set up: see isReady()) */
    public function isEnabled(): bool
    {
        return $this->store->group('chatbox', ['enabled'])['enabled'] === '1';
    }

    public function model(): string
    {
        $model = $this->store->group('chatbox', ['model'])['model'];

        return isset(self::MODELS[$model]) ? $model : self::DEFAULT_MODEL;
    }

    /** Questions one employee may ask per hour */
    public function hourlyLimit(): int
    {
        $limit = (int) $this->store->group('chatbox', ['hourly_limit'])['hourly_limit'];

        return $limit > 0 ? $limit : self::DEFAULT_HOURLY_LIMIT;
    }

    public function hrContact(): ?string
    {
        return $this->store->group('chatbox', ['hr_contact'])['hr_contact'];
    }

    public function extraInstructions(): ?string
    {
        return $this->store->group('chatbox', ['extra_instructions'])['extra_instructions'];
    }

    /**
     * The chat tools the admin switched on, by name ("leave_balance"). None until the admin chooses.
     *
     * @return list<string>
     */
    public function enabledTools(): array
    {
        $decoded = json_decode((string) $this->store->group('chatbox', ['tools'])['tools'], true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== null;
    }

    public function apiKey(): ?string
    {
        $stored = $this->store->group('chatbox', ['api_key'])['api_key'];
        if ($stored === null) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Saved with another APP_KEY: the key has to be entered again
            return null;
        }
    }

    /**
     * The tools the admin switched on that a module still offers: the name of a tool of a module that was
     * switched off since stays saved, but it cannot be used.
     *
     * @return list<string>
     */
    public function usableTools(): array
    {
        $enabled = $this->enabledTools();

        return $enabled === [] ? [] : array_values(array_intersect($enabled, array_keys($this->modules->chatTools())));
    }

    /**
     * Switched on and complete: the chat bubble is shown to the employees. It needs something to answer
     * from: data the assistant may look up, or a document. This runs on every page of the portal: the
     * checks that need no query come first.
     */
    public function isReady(): bool
    {
        return $this->isEnabled()
            && $this->hasApiKey()
            && ($this->usableTools() !== [] || ChatDocument::hasActive());
    }

    /**
     * Save the settings. A blank API key keeps the saved one.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));

        // Only what is given is changed: a partial update must not switch the assistant off
        if (array_key_exists('enabled', $values)) {
            $values['enabled'] = ! empty($values['enabled']) ? '1' : '0';
        }
        if (isset($values['hourly_limit'])) {
            $values['hourly_limit'] = (string) max(1, (int) $values['hourly_limit']);
        }
        if (array_key_exists('tools', $values)) {
            $values['tools'] = json_encode(array_values(array_unique(array_filter((array) $values['tools'], 'is_string'))));
        }
        if (blank($values['api_key'] ?? null)) {
            unset($values['api_key']);
        } else {
            $values['api_key'] = Crypt::encryptString(trim((string) $values['api_key']));
        }

        $this->store->save('chatbox', $values);
    }
}
