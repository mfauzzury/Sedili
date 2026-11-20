<?php

namespace App\Modules\Chatbox\Filament\Pages;

use App\Modules\Chatbox\Exceptions\ChatboxException;
use App\Modules\Chatbox\Models\ChatDocument;
use App\Modules\Chatbox\Services\ChatboxCost;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\Chatbox\Services\ChatToolbox;
use App\Modules\Chatbox\Services\ClaudeGateway;
use App\Modules\Chatbox\Services\KnowledgeImporter;
use App\Modules\ModuleManager;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The chat assistant: the Claude API key and behaviour (General), the documents it answers from
 * (Knowledge) and the data it may look up for an employee (Data access).
 */
class ChatboxSettingsPage extends Page
{
    /** Characters of each document shown to the admin, to check that the text was read properly */
    private const PREVIEW_CHARACTERS = 2000;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Chatbox';

    protected static ?string $title = 'Chatbox';

    protected static ?string $slug = 'chatbox';

    protected string $view = 'chatbox::settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var Collection<int, ChatDocument>|null */
    private ?Collection $stored = null;

    public function mount(): void
    {
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $settings = app(ChatboxSettings::class);

        $this->getSchema('form')?->fill($settings->all() + [
            'api_key' => null,
            'tools' => $settings->enabledTools(),
            'documents' => ChatDocument::query()->overview(self::PREVIEW_CHARACTERS)->orderBy('id')->get()->map(fn (ChatDocument $document): array => [
                'id' => $document->id,
                'label' => $document->displayLabel(),
                'enabled' => $document->enabled,
                'info' => $this->documentInfo($document),
                'preview' => $document->preview.($document->characters > self::PREVIEW_CHARACTERS ? ' [...]' : ''),
            ])->all(),
        ]);
    }

    /**
     * The size of the documents (without their text), read once per request: the texts on the page are
     * worked out again at every render.
     *
     * @return Collection<int, ChatDocument>
     */
    private function stored(): Collection
    {
        return $this->stored ??= ChatDocument::query()->get(['id', 'enabled', 'characters']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Test connection')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->action(function (): void {
                    $settings = app(ChatboxSettings::class);
                    if (! $settings->hasApiKey()) {
                        Notification::make()->title('Save the API key first')->warning()->send();

                        return;
                    }
                    try {
                        app(ClaudeGateway::class)->checkConnection($settings->model());
                    } catch (ChatboxException $e) {
                        Notification::make()->title('Connection failed')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Connected to Claude')->body('The saved key works with '.ChatboxSettings::MODELS[$settings->model()].'.')->success()->send();
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('chatbox')
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        $this->generalTab(),
                        $this->knowledgeTab(),
                        $this->dataAccessTab(),
                        $this->insightTab(),
                    ]),
            ]);
    }

    private function generalTab(): Tab
    {
        return Tab::make('General')
            ->icon('heroicon-o-cog-6-tooth')
            ->columns(2)
            ->schema([
                Toggle::make('enabled')
                    ->label('Show the chat bubble to the employees')
                    ->helperText('A round button at the bottom of every employee portal page. It appears once the API key is saved and there is something to answer from: a document (Knowledge) or data to look up (Data access).')
                    ->columnSpanFull(),
                TextInput::make('api_key')
                    ->label('Claude API key')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => (bool) $get('enabled') && ! app(ChatboxSettings::class)->hasApiKey())
                    ->helperText(fn (): string => app(ChatboxSettings::class)->hasApiKey()
                        ? 'A key is saved. Leave empty to keep it.'
                        : 'Create one in the Claude Console (platform.claude.com) > API keys. It is stored encrypted and never shown again.')
                    ->columnSpanFull(),
                Select::make('model')
                    ->label('Model')
                    ->options(ChatboxSettings::MODELS)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false)
                    ->helperText('Opus answers best; Haiku costs the least. The documents are cached, so repeat questions are cheap on all three.'),
                TextInput::make('hourly_limit')
                    ->label('Questions per employee per hour')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1000)
                    ->required()
                    ->helperText('Keeps the bill under control.'),
                TextInput::make('hr_contact')
                    ->label('HR contact')
                    ->maxLength(255)
                    ->placeholder('hr@company.com, ext. 123')
                    ->helperText('Given to employees when the documents and the data do not answer their question.')
                    ->columnSpanFull(),
                Textarea::make('extra_instructions')
                    ->label('Extra instructions')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Optional, for example "Always remind employees that the handbook is updated every January".')
                    ->columnSpanFull(),
            ]);
    }

    private function knowledgeTab(): Tab
    {
        return Tab::make('Knowledge')
            ->icon('heroicon-o-book-open')
            ->schema([
                Text::make(fn (Get $get): string => $this->knowledgeSummary((string) $get('model'))),
                Repeater::make('documents')
                    ->label('Documents')
                    ->addActionLabel('Add a document')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => filled($state['label'] ?? null) ? $state['label'] : 'New document')
                    ->columns(2)
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('label')
                            ->label('Label')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('Staff handbook')
                            ->helperText('What this knowledge is. Claude names it when it answers from it.'),
                        Toggle::make('enabled')
                            ->label('In use')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Switch off to keep the document but stop answering from it.'),
                        Hidden::make('info')->dehydrated(false),
                        Text::make(fn (Get $get): string => (string) $get('info'))
                            ->visible(fn (Get $get): bool => filled($get('id')))
                            ->columnSpanFull(),
                        FileUpload::make('file')
                            ->label('File (PDF or Word .docx)')
                            ->disk(KnowledgeImporter::DISK)
                            ->directory('chatbox')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/zip',
                            ])
                            ->maxSize(12288)
                            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => $this->storageName($file))
                            ->required(fn (Get $get): bool => blank($get('id')))
                            ->visible(fn (Get $get): bool => blank($get('id')))
                            ->helperText('Up to 12 MB, and it must contain text (not only pictures of pages). Old .doc files are not supported: save them as .docx.')
                            ->columnSpanFull(),
                        Textarea::make('preview')
                            ->label('Start of the text the assistant reads')
                            ->helperText('Check that the text came out in the right order, with the headings and tables readable.')
                            ->rows(6)
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => filled($get('id')))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private function dataAccessTab(): Tab
    {
        $available = app(ChatToolbox::class)->available();

        return Tab::make('Data access')
            ->icon('heroicon-o-circle-stack')
            ->schema([
                Text::make('Besides the documents, the assistant can look up data of the employee who is asking, for example their leave balance, when they ask about it. The modules offer the lookups. They only read, they only ever see the data of the employee who is chatting (never someone else\'s), and all are off until you switch them on here. What a lookup returns is sent to Claude (Anthropic) to write the answer.'),
                CheckboxList::make('tools')
                    ->label('Data the assistant may look up')
                    ->options(array_map(fn (array $entry): string => $entry['module']->label().': '.$entry['tool']->label(), $available))
                    ->descriptions(array_map(fn (array $entry): string => 'Sends to Claude: '.$entry['tool']->dataSent(), $available))
                    ->visible($available !== []),
                Text::make('No module offers lookups yet, or the modules that do are switched off.')
                    ->visible($available === []),
            ]);
    }

    private function insightTab(): Tab
    {
        $reports = [];
        $descriptions = [];
        foreach (app(ModuleManager::class)->reports() as $moduleKey => $module) {
            foreach ($module['reports'] as $key => $report) {
                $reports["$moduleKey.$key"] = $module['label'].': '.$report->title();
                $descriptions["$moduleKey.$key"] = $report->description();
            }
        }

        return Tab::make('Insight')
            ->icon('heroicon-o-sparkles')
            ->columns(2)
            ->schema([
                Text::make('Insight is a chat screen in the Reports menu where admins ask about the figures of the company in plain words. Claude runs the reports you allow below and writes what stands out; the chart and the table of each answer are drawn by the server. It uses the Claude API key of the General tab, and only admins can open it.')
                    ->columnSpanFull(),
                Toggle::make('insight_enabled')
                    ->label('Show Insight in the Reports menu')
                    ->helperText('It appears once the API key is saved and at least one report is allowed.')
                    ->columnSpanFull(),
                Select::make('insight_model')
                    ->label('Model')
                    ->options(ChatboxSettings::MODELS)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false)
                    ->helperText('Reading figures does not need the biggest model: Sonnet is the default and costs a fraction of Opus.'),
                Select::make('insight_visuals')
                    ->label('Under each answer')
                    ->options(ChatboxSettings::INSIGHT_VISUALS)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('Costs no tokens: the server draws the chart and the table, Claude only names the report.'),
                TextInput::make('insight_max_rows')
                    ->label('Rows of a report given to Claude')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(200)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('The rest of the table is on the screen, not in the bill. More rows mean more tokens on every question.'),
                TextInput::make('insight_max_tokens')
                    ->label('Longest answer (tokens)')
                    ->numeric()
                    ->minValue(200)
                    ->maxValue(8000)
                    ->required()
                    ->helperText('About 0.75 words per token. Caps the output, which is the dearest part of a question.'),
                TextInput::make('insight_hourly_limit')
                    ->label('Questions per admin per hour')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(1000)
                    ->required()
                    ->helperText('Keeps the bill under control.'),
                Text::make(fn (Get $get): string => $this->insightSummary((string) $get('insight_model'), (int) $get('insight_max_rows')))
                    ->columnSpanFull(),
                CheckboxList::make('insight_reports')
                    ->label('Reports Claude may run')
                    ->options($reports)
                    ->descriptions(array_map(fn (string $description): string => $description, $descriptions))
                    ->helperText('What a report returns (the figures and the first rows, with names of employees where the report lists them) is sent to Claude (Anthropic) to write the answer. Payroll is off until you switch it on: it lists what each employee is paid.')
                    ->visible($reports !== [])
                    ->columnSpanFull(),
                Textarea::make('insight_instructions')
                    ->label('Extra instructions')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Optional, for example "Our financial year starts in April" or "Show amounts in MYR".')
                    ->columnSpanFull(),
            ]);
    }

    private function insightSummary(string $model, int $maxRows): string
    {
        $cost = ChatboxCost::perInsightQuestion($model, max(1, $maxRows));

        return $cost === null
            ? ''
            : sprintf(
                'A question that runs one report costs about %s (an estimate at list prices: the Claude Console has the real bill). Charts and tables add nothing; the model, the rows given to Claude and the length of the answer are what the price depends on.',
                ChatboxCost::format($cost),
            );
    }

    /**
     * The name of an uploaded file on the disk: unique, readable, and with an extension we read.
     */
    private function storageName(TemporaryUploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, KnowledgeImporter::EXTENSIONS, true)) {
            $extension = $file->getMimeType() === 'application/pdf' ? 'pdf' : 'docx';
        }

        return Str::ulid().'__'.(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'document').'.'.$extension;
    }

    private function documentInfo(ChatDocument $document): string
    {
        return sprintf(
            '%s (%s), %sabout %s tokens, added %s.',
            $document->fileName(),
            strtoupper($document->kind),
            $document->pages > 0 ? $document->pages.' '.Str::plural('page', $document->pages).', ' : '',
            number_format($document->estimatedTokens()),
            $document->created_at?->format('j M Y'),
        );
    }

    /**
     * What the documents add up to, and what a question costs with the ones in use and the chosen model.
     */
    private function knowledgeSummary(string $model): string
    {
        $documents = $this->stored();
        if ($documents->isEmpty()) {
            return 'No documents yet. Add the handbook, policies or guides the assistant should answer from: PDF or Word, each with a label.';
        }

        $inUse = $documents->where('enabled', true);
        $tokens = fn (int $characters): int => (int) ceil($characters / ChatDocument::CHARS_PER_TOKEN);
        $tokensInUse = $tokens((int) $inUse->sum('characters'));

        $summary = sprintf(
            '%d of %d %s in use, about %s tokens. All the documents together: %s of the %s tokens allowed.',
            $inUse->count(),
            $documents->count(),
            Str::plural('document', $documents->count()),
            number_format($tokensInUse),
            number_format($tokens((int) $documents->sum('characters'))),
            number_format(KnowledgeImporter::MAX_TOKENS),
        );

        $cost = ChatboxCost::perQuestion($tokensInUse, $model);
        if ($tokensInUse > 0 && $cost !== null) {
            $summary .= sprintf(
                ' Estimated cost: about %s per question while they are in Claude\'s cache (it stays for an hour after each question), and about %s for the first question after that, which refills it. List prices, an estimate: see the Claude Console for the real bill.',
                ChatboxCost::format($cost['warm']),
                ChatboxCost::format($cost['cold']),
            );
        }

        return $summary;
    }

    public function save(): void
    {
        $state = $this->getSchema('form')?->getState() ?? [];

        app(ChatboxSettings::class)->update($state);

        $problems = is_array($state['documents'] ?? null) ? app(KnowledgeImporter::class)->sync($state['documents'], Auth::id()) : [];

        if ($problems === []) {
            Notification::make()->title('Chatbox settings saved')->success()->send();
        } else {
            Notification::make()->title('Saved, but not every document was added')->body(implode("\n", $problems))->danger()->persistent()->send();
        }

        $this->stored = null;
        $this->fillForm();
    }
}
