<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The chats of the Insight screen of the admins live next to the chats of the employees:
        // "hr" is the chat bubble of the portal, "insight" the reports assistant
        Schema::table('chatbox_conversations', function (Blueprint $table) {
            $table->string('kind', 10)->default('hr')->after('employee_id');
            $table->index(['employee_id', 'kind', 'updated_at']);
        });

        // The reports shown under an answer of Insight: which report and with which filters, never the data
        Schema::table('chatbox_messages', function (Blueprint $table) {
            $table->json('visuals')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chatbox_messages', function (Blueprint $table) {
            $table->dropColumn('visuals');
        });
        Schema::table('chatbox_conversations', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'kind', 'updated_at']);
            $table->dropColumn('kind');
        });
    }
};
