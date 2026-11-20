<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The handbook the assistant answers from: the uploaded PDF and the text extracted from it
        Schema::create('chatbox_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('pages')->default(0);
            $table->longText('text');
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chatbox_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamps();

            $table->index(['employee_id', 'updated_at']);
        });

        Schema::create('chatbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chatbox_conversations')->cascadeOnDelete();
            $table->string('role', 10); // user | assistant
            $table->text('content');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbox_messages');
        Schema::dropIfExists('chatbox_conversations');
        Schema::dropIfExists('chatbox_documents');
    }
};
