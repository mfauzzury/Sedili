<?php

namespace App\Modules\Chatbox\Tests;

use App\Modules\Chatbox\Exceptions\ChatboxException;
use App\Modules\Chatbox\Models\ChatDocument;
use App\Modules\Chatbox\Services\ChatboxSettings;
use App\Modules\Chatbox\Services\ChatTurn;
use App\Modules\Chatbox\Services\ClaudeGateway;

/**
 * Helpers of the Chatbox tests: the assistant is set up, and Claude is replaced by a fake that never
 * calls the API.
 */
trait MakesChatbox
{
    protected FakeClaude $claude;

    protected function fakeClaude(): FakeClaude
    {
        $this->claude = new FakeClaude;
        $this->app->instance(ClaudeGateway::class, $this->claude);

        return $this->claude;
    }

    /** The assistant switched on, with a key and a handbook */
    protected function setUpAssistant(array $settings = []): ChatDocument
    {
        app(ChatboxSettings::class)->update($settings + [
            'enabled' => true,
            'api_key' => 'sk-ant-test-key',
            'model' => 'claude-sonnet-5-5',
            'hr_contact' => 'hr@example.com',
            'hourly_limit' => 30,
        ]);

        return $this->addDocument('Staff handbook', "Annual leave\nEvery employee gets 14 days of annual leave a year.");
    }

    /** A document the assistant answers from, without reading a file */
    protected function addDocument(string $label, string $text, bool $enabled = true): ChatDocument
    {
        $slug = str($label)->slug();

        return ChatDocument::query()->create([
            'name' => "01ABC__$slug.pdf",
            'label' => $label,
            'kind' => 'pdf',
            'enabled' => $enabled,
            'path' => "chatbox/01ABC__$slug.pdf",
            'pages' => 12,
            'characters' => mb_strlen($text),
            'text' => $text,
        ]);
    }

    /**
     * A Word document with these paragraphs ("Heading1: text" makes a heading, "- text" a list item) and
     * these table rows, as the bytes of the file.
     *
     * @param  list<string>  $paragraphs
     * @param  list<list<string>>  $table
     */
    protected function docx(array $paragraphs, array $table = []): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $body = '';
        foreach ($paragraphs as $paragraph) {
            $properties = '';
            if (preg_match('/^(Heading\d): (.*)$/', $paragraph, $match) === 1) {
                $properties = '<w:pPr><w:pStyle w:val="'.$match[1].'"/></w:pPr>';
                $paragraph = $match[2];
            } elseif (str_starts_with($paragraph, '- ')) {
                $properties = '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>';
                $paragraph = substr($paragraph, 2);
            }
            $body .= '<w:p>'.$properties.'<w:r><w:t xml:space="preserve">'.htmlspecialchars($paragraph, ENT_XML1).'</w:t></w:r></w:p>';
        }
        if ($table !== []) {
            $body .= '<w:tbl>';
            foreach ($table as $row) {
                $body .= '<w:tr>'.implode('', array_map(fn (string $cell): string => '<w:tc><w:p><w:r><w:t>'.htmlspecialchars($cell, ENT_XML1).'</w:t></w:r></w:p></w:tc>', $row)).'</w:tr>';
            }
            $body .= '</w:tbl>';
        }

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document '.$w.'><w:body>'.$body.'</w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A module manager that offers these chat tools (keyed by name) on top of nothing else.
     *
     * @param  array<string, \App\Modules\ChatTool>  $tools
     */
    protected function modulesOffering(array $tools): \App\Modules\ModuleManager
    {
        return new class($tools) extends \App\Modules\ModuleManager
        {
            /** @param array<string, \App\Modules\ChatTool> $tools */
            public function __construct(private readonly array $tools) {}

            public function chatTools(): array
            {
                $leave = app(\App\Modules\ModuleManager::class)->find('leave');

                return array_map(fn (\App\Modules\ChatTool $tool): array => ['module' => $leave, 'tool' => $tool], $this->tools);
            }
        };
    }

    /** @param  list<string>  $names */
    protected function enableTools(array $names): void
    {
        app(ChatboxSettings::class)->update(['tools' => $names]);
    }

    /**
     * A small valid PDF: one page per element (a string is one page), the lines of each on its page.
     *
     * @param  string|list<string>  $pages
     */
    protected function pdf(string|array $pages): string
    {
        $pages = (array) $pages;
        $count = count($pages);

        // 1 catalog, 2 page tree, 3 font, then a page and its content for each page
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(fn (int $i): string => (4 + $i * 2).' 0 R', array_keys($pages))).'] /Count '.$count.' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        foreach ($pages as $i => $text) {
            $stream = "BT /F1 12 Tf 14 TL 50 750 Td\n";
            foreach (explode("\n", $text) as $line) {
                $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).") Tj T*\n";
            }
            $stream .= 'ET';
            $objects[4 + $i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.(5 + $i * 2).' 0 R /Resources << /Font << /F1 3 0 R >> >> >>';
            $objects[5 + $i * 2] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
        }
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}

/**
 * Claude, as the tests need it: answers with what it is given and remembers what it was asked.
 */
class FakeClaude implements ClaudeGateway
{
    /** @var list<array{model: string, system: array, messages: array, tools: array, maxTokens: int|null}> */
    public array $calls = [];

    /** The answer of every turn, unless a script is given */
    public ?ChatTurn $reply = null;

    /**
     * Turns to answer with, one for each call, in order. A turn with tool calls makes the assistant
     * look something up and ask again.
     *
     * @var list<ChatTurn>
     */
    public array $script = [];

    public ?ChatboxException $failure = null;

    /** @var list<string> the pieces of the default answer */
    public array $deltas = ['The handbook says ', '14 days.'];

    public function stream(string $model, array $system, array $messages, array $tools, callable $onDelta, ?int $maxTokens = null): ChatTurn
    {
        $this->calls[] = compact('model', 'system', 'messages', 'tools', 'maxTokens');
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->script !== []) {
            $turn = array_shift($this->script);
            $pieces = [$turn->text];
        } elseif ($this->reply !== null) {
            $turn = $this->reply;
            $pieces = [$turn->text];
        } else {
            $turn = new ChatTurn(implode('', $this->deltas), inputTokens: 40, outputTokens: 12, cacheReadTokens: 5000);
            $pieces = $this->deltas;
        }

        foreach ($pieces as $piece) {
            if ($piece !== '') {
                $onDelta($piece);
            }
        }

        return $turn;
    }

    public function checkConnection(string $model): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

/**
 * A chat tool for the tests: whatever the closure does when it is run.
 */
final class FakeChatTool extends \App\Modules\ChatTool
{
    public function __construct(private readonly string $toolKey, private readonly \Closure $run) {}

    public function key(): string
    {
        return $this->toolKey;
    }

    public function label(): string
    {
        return ucfirst($this->toolKey);
    }

    public function description(): string
    {
        return 'A tool for the tests';
    }

    public function dataSent(): string
    {
        return 'Nothing';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];
    }

    public function run(\App\Models\Employee $employee, array $input): array
    {
        return ($this->run)($employee, $input);
    }
}
