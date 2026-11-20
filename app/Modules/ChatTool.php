<?php

namespace App\Modules;

use App\Models\Employee;

/**
 * A read-only lookup a module offers to the chat assistant (see Module::chatTools()), for example
 * "my leave balance". Claude decides when to call it while answering an employee. The admin chooses
 * which tools are switched on in System > Chatbox > Data access (all are off until then).
 *
 * Rules for a tool:
 * - It only reads, and only for the employee it is given (the one who is chatting). It never takes an
 *   employee, a user or an id from its input: the input comes from the model, which an employee can
 *   steer with what they type.
 * - It returns only what answers the question, as plain values (what it returns is sent to Claude).
 * - It validates its input and ignores what it does not know (see optionalYear() and optionalChoice()).
 * - The date of the lookup ("as_of") is added by the chat: a tool does not return it.
 */
abstract class ChatTool
{
    /**
     * Identifier, unique within the module (e.g. "balance"). The name Claude sees is "{module}_{key}".
     */
    abstract public function key(): string;

    /**
     * Name shown to the admin in the list of tools.
     */
    abstract public function label(): string;

    /**
     * Tells Claude what the tool returns and when to use it.
     */
    abstract public function description(): string;

    /**
     * Tells the admin which personal data the tool sends to Claude, in one sentence.
     */
    abstract public function dataSent(): string;

    /**
     * JSON schema of the input: an object with a "properties" list (empty if the tool needs no input).
     *
     * @return array<string, mixed>
     */
    abstract public function inputSchema(): array;

    /**
     * Look the answer up for this employee.
     *
     * @param  array<string, mixed>  $input  as given by the model: check every value
     * @return array<string, mixed>
     */
    abstract public function run(Employee $employee, array $input): array;

    /**
     * A calendar year from the input, or null when it is missing or not a year (2000 to 2100).
     *
     * @param  array<string, mixed>  $input
     */
    protected function optionalYear(array $input, string $key = 'year'): ?int
    {
        $value = $input[$key] ?? null;

        return is_numeric($value) && (int) $value >= 2000 && (int) $value <= 2100 ? (int) $value : null;
    }

    /**
     * One of the allowed values from the input, or null when it is missing or not one of them.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     */
    protected function optionalChoice(array $input, string $key, array $allowed): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }
}
