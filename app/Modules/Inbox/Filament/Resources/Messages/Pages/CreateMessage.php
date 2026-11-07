<?php

namespace App\Modules\Inbox\Filament\Resources\Messages\Pages;

use App\Modules\Inbox\Filament\Resources\Messages\MessageResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Models\{User, Employee};
use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\Topic;


class CreateMessage extends CreateRecord
{

    protected static string $resource = MessageResource::class;
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $topic = null;

        // Validate receiver_id exists
        if (empty($data['receiver_id'])) {
            throw new \Exception('Please select at least one recipient');
        }

        // Handle single receiver (convert to array if not already)
        $receiverIds = is_array($data['receiver_id']) ? $data['receiver_id'] : [$data['receiver_id']];

        foreach ($receiverIds as $receiverId) {
            // Ensure receiver exists
            $receiver = Employee::find($receiverId);
            if (!$receiver) {
                throw new \Exception("Invalid recipient selected");
            }

            // Create topic for this message thread
            $topic = Topic::create([
                'subject' => $data['subject'],
                'creator_id' => auth()->id(),
                'receiver_id' => $receiverId,
            ]);

            // Create the initial message
            Message::create([
                'topic_id' => $topic->id,
                'sender_id' => auth()->id(),
                'content' => $data['content'],
                'read_at' => null,
                'receiver_id' => $receiverId
            ]);
        }

        if (!$topic) {
            throw new \Exception('Failed to create message');
        }

        return $topic;
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
