<?php

namespace App\Modules\Inbox\Tests;

use App\Modules\Inbox\Models\Message;
use App\Modules\Inbox\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class MessageNotificationTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    public function test_the_receiver_is_told_about_the_first_message(): void
    {
        $alice = $this->makeEmployee('Alice');
        $admin = $this->makeEmployee('Boss', 'admin');
        $topic = Topic::create(['subject' => 'Salary', 'creator_id' => $alice->id, 'receiver_id' => $admin->id]);

        Message::create(['topic_id' => $topic->id, 'sender_id' => $alice->id, 'receiver_id' => $admin->id, 'content' => 'Hello']);

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $alice->notifications()->count());
        $this->assertSame('Salary', $admin->notifications()->first()->data['body']);
    }

    public function test_a_reply_tells_the_person_who_wrote_first_and_not_the_one_replying(): void
    {
        $alice = $this->makeEmployee('Alice');
        $admin = $this->makeEmployee('Boss', 'admin');
        $topic = Topic::create(['subject' => 'Salary', 'creator_id' => $alice->id, 'receiver_id' => $admin->id]);
        Message::create(['topic_id' => $topic->id, 'sender_id' => $alice->id, 'receiver_id' => $admin->id, 'content' => 'Hello']);

        // The admin answers: the message goes to Alice
        Message::create(['topic_id' => $topic->id, 'sender_id' => $admin->id, 'receiver_id' => $alice->id, 'content' => 'Hi Alice']);

        $this->assertSame(1, $alice->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count(), 'the admin was only told about the first message');
    }

    public function test_a_message_without_a_known_receiver_does_not_break_saving_it(): void
    {
        $alice = $this->makeEmployee('Alice');
        $topic = Topic::create(['subject' => 'Salary', 'creator_id' => $alice->id, 'receiver_id' => 999999]);

        $message = Message::create(['topic_id' => $topic->id, 'sender_id' => $alice->id, 'receiver_id' => 999999, 'content' => 'Hello']);

        $this->assertTrue($message->exists);
        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
    }
}
