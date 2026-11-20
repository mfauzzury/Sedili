<?php

namespace App\Modules\Chatbox\Exceptions;

use RuntimeException;

/**
 * Something went wrong while answering. The message is written for the employee: it is shown as is.
 */
class ChatboxException extends RuntimeException
{
    /** The chat the question was saved in, when it was saved before the failure (so the chat can go on) */
    public ?int $conversationId = null;
}
