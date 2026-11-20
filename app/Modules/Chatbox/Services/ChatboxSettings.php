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
    public const FIELDS = [
        'enabled', 'api_key', 'model', 'extra_instructions', 'hr_contact', 'hourly_limit', 'tools',
        // Insight: the reports assistant of the admins (Reports > Insight)
        'insight_enabled', 'insight_model', 'insight_visuals', 'insight_reports', 'insight_max_rows',
        'insight_max_tokens', 'insight_hourly_limit', 'insight_instructions',
    ];

    public const DEFAULT_MODEL = 'claude-opus-5-5';

    public const DEFAULT_HOURLY_LIMIT = 30;

    public const DEFAULT_INSIGHT_MODEL = 'claude-sonnet-5-5';

    public const DEFAULT_INSIGHT_HOURLY_LIMIT = 60;

    /** Rows of a report given to Claude: the rest is on the screen, not in the bill */
    public const DEFAULT_INSIGHT_MAX_ROWS = 25;

    public const DEFAULT_INSIGHT_MAX_TOKENS = 1500;

    /** Reports Claude may not use until the admin allows them: they hold what each employee is paid */
    public const SENSITIVE_REPORTS = ['payroll.'];

    /** @var array<string, string> what Insight shows under an answer => label */
    public const INSIGHT_VISUALS = [
        'full' => 'Charts and tables',
        'tables' => 'Tables only',
        'text' => 'Text only',
    ];

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
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'model' => $this->model(),
            'extra_instructions' => $this->extraInstructions(),
            'hr_contact' => $this->hrContact(),
            'hourly_limit' => $this->hourlyLimit(),
            'insight_enabled' => $this->isInsightEnabled(),
            'insight_model' => $this->insightModel(),
            'insight_visuals' => $this->insightVisuals(),
            'insight_reports' => $this->insightReports(),
            'insight_max_rows' => $this->insightMaxRows(),
            'insight_max_tokens' => $this->insightMaxTokens(),
            'insight_hourly_limit' => $this->insightHourlyLimit(),
            'insight_instructions' => $this->insightInstructions(),
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

    /** The admin switched the Insight screen on (it is not ready until it is set up: see isInsightReady()) */
    public function isInsightEnabled(): bool
    {
        return $this->store->group('chatbox', ['insight_enabled'])['insight_enabled'] === '1';
    }

    public function insightModel(): string
    {
        $model = $this->store->group('chatbox', ['insight_model'])['insight_model'];

        return isset(self::MODELS[$model]) ? $model : self::DEFAULT_INSIGHT_MODEL;
    }

    /** What is shown under an answer: "full" (charts and tables), "tables" or "text". Costs no tokens: Claude does not draw it. */
    public function insightVisuals(): string
    {
        $visuals = $this->store->group('chatbox', ['insight_visuals'])['insight_visuals'];

        return isset(self::INSIGHT_VISUALS[$visuals]) ? $visuals : 'full';
    }

    public function insightMaxRows(): int
    {
        $rows = (int) $this->store->group('chatbox', ['insight_max_rows'])['insight_max_rows'];

        return $rows > 0 ? $rows : self::DEFAULT_INSIGHT_MAX_ROWS;
    }

    public function insightMaxTokens(): int
    {
        $tokens = (int) $this->store->group('chatbox', ['insight_max_tokens'])['insight_max_tokens'];

        return $tokens > 0 ? $tokens : self::DEFAULT_INSIGHT_MAX_TOKENS;
    }

    /** Questions one admin may ask Insight per hour */
    public function insightHourlyLimit(): int
    {
        $limit = (int) $this->store->group('chatbox', ['insight_hourly_limit'])['insight_hourly_limit'];

        return $limit > 0 ? $limit : self::DEFAULT_INSIGHT_HOURLY_LIMIT;
    }

    public function insightInstructions(): ?string
    {
        return $this->store->group('chatbox', ['insight_instructions'])['insight_instructions'];
    }

    /**
     * The ids ("leave.balances") of every report of the enabled modules, for the admin to choose from.
     *
     * @return list<string>
     */
    public function availableReportIds(): array
    {
        $ids = [];
        foreach ($this->modules->reports() as $moduleKey => $module) {
            foreach (array_keys($module['reports']) as $key) {
                $ids[] = "$moduleKey.$key";
            }
        }

        return $ids;
    }

    /**
     * The reports the admin allows Insight to use. Until the admin chooses: all, except the ones that
     * hold what employees are paid. A saved empty list means none.
     *
     * @return list<string>
     */
    public function insightReports(): array
    {
        $saved = $this->store->group('chatbox', ['insight_reports'])['insight_reports'];
        if ($saved === null) {
            return array_values(array_filter(
                $this->availableReportIds(),
                fn (string $id): bool => ! str_starts_with($id, self::SENSITIVE_REPORTS[0]),
            ));
        }
        $decoded = json_decode($saved, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * The allowed reports that a module still offers: the id of a report of a module that was switched
     * off since stays saved, but it cannot be used.
     *
     * @return list<string>
     */
    public function usableReports(): array
    {
        return array_values(array_intersect($this->insightReports(), $this->availableReportIds()));
    }

    /** Switched on and complete: the Insight screen is in the menu of the admins */
    public function isInsightReady(): bool
    {
        return $this->isInsightEnabled() && $this->hasApiKey() && $this->usableReports() !== [];
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
        if (array_key_exists('insight_enabled', $values)) {
            $values['insight_enabled'] = ! empty($values['insight_enabled']) ? '1' : '0';
        }
        foreach (['insight_hourly_limit' => [1, 1000], 'insight_max_rows' => [1, 200], 'insight_max_tokens' => [200, 8000]] as $field => [$min, $max]) {
            if (isset($values[$field])) {
                $values[$field] = (string) min($max, max($min, (int) $values[$field]));
            }
        }
        if (array_key_exists('insight_reports', $values)) {
            $values['insight_reports'] = json_encode(array_values(array_unique(array_filter((array) $values['insight_reports'], 'is_string'))));
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
