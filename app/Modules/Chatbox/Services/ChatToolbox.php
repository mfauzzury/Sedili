<?php

namespace App\Modules\Chatbox\Services;

use App\Models\Employee;
use App\Modules\ChatTool;
use App\Modules\Module;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The lookups Claude may use while answering: the chat tools of the enabled modules that the admin
 * switched on (System > Chatbox > Data access). Every call runs for the employee who is chatting.
 */
class ChatToolbox
{
    /** What a tool returns may not be longer: it is sent to Claude, and it is paid for in tokens */
    public const MAX_RESULT_CHARACTERS = 20_000;

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ChatboxSettings $settings,
    ) {}

    /**
     * Every tool of the enabled modules, switched on or not, for the admin to choose from.
     *
     * @return array<string, array{module: Module, tool: ChatTool}> keyed by name
     */
    public function available(): array
    {
        return $this->modules->chatTools();
    }

    /**
     * The tools switched on, in a fixed order (the tools are the start of the cached prompt).
     *
     * @return array<string, ChatTool> keyed by name
     */
    public function enabled(): array
    {
        $enabled = array_flip($this->settings->enabledTools());
        $tools = [];
        foreach ($this->available() as $name => $entry) {
            if (isset($enabled[$name])) {
                $tools[$name] = $entry['tool'];
            }
        }
        ksort($tools);

        return $tools;
    }

    /**
     * The tools as Claude takes them.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->enabled() as $name => $tool) {
            $definitions[] = ['name' => $name, 'description' => $tool->description(), 'input_schema' => $tool->inputSchema()];
        }

        return $definitions;
    }

    /**
     * Run a tool Claude asked for, for this employee. A tool that is not switched on is refused, and a
     * failing tool gives Claude an error to explain, never the details of it.
     *
     * @param  array<string, mixed>  $input  as given by the model
     * @return array{content: string, isError: bool}
     */
    public function run(string $name, array $input, Employee $employee): array
    {
        $tool = $this->enabled()[$name] ?? null;
        if ($tool === null) {
            return ['content' => 'This lookup is not available.', 'isError' => true];
        }

        try {
            $json = json_encode(['as_of' => now()->toDateString()] + $tool->run($employee, $input), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('Chatbox: a chat tool failed', ['tool' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return ['content' => 'The lookup failed. Tell the employee that the information could not be retrieved right now.', 'isError' => true];
        }

        // Not cut: half a JSON document is worse than none. The tools limit their own lists, this is the safety net.
        if (mb_strlen($json) > self::MAX_RESULT_CHARACTERS) {
            return ['content' => 'The result was too long to use. Tell the employee that the information could not be retrieved in full.', 'isError' => true];
        }

        return ['content' => $json, 'isError' => false];
    }
}
