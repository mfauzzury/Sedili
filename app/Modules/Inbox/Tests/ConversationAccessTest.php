<?php

namespace App\Modules\Inbox\Tests;

use App\Models\Employee;
use App\Modules\Inbox\Models\Topic;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $email): Employee
    {
        $employee = new Employee();
        $employee->forceFill([
            'first_name' => 'Test',
            'last_name' => $email,
            'email' => $email,
            'password' => 'password',
            'employment_type' => 'Permanent',
        ])->save();
        return $employee;
    }

    public function test_only_the_participants_can_open_a_conversation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $sender = $this->employee('sender@example.com');
        $receiver = $this->employee('receiver@example.com');
        $outsider = $this->employee('outsider@example.com');
        $topic = Topic::create(['subject' => 'Salary', 'creator_id' => $sender->id, 'receiver_id' => $receiver->id]);

        // One session per user: switching users inside one session is logged out by AuthenticateSession
        foreach ([[$sender, 200], [$receiver, 200], [$outsider, 404]] as [$user, $status]) {
            $this->flushSession();
            $this->actingAs($user)->get("/portal/messages/{$topic->id}")->assertStatus($status);
        }
    }

    public function test_an_admin_cannot_open_a_conversation_they_are_not_part_of(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $sender = $this->employee('sender@example.com');
        $receiver = $this->employee('receiver@example.com');
        $admin = $this->employee('admin@example.com');
        $admin->assignRole('admin');
        $topic = Topic::create(['subject' => 'Private', 'creator_id' => $sender->id, 'receiver_id' => $receiver->id]);

        $this->actingAs($admin)->get("/messages/{$topic->id}")->assertNotFound();
    }
}
