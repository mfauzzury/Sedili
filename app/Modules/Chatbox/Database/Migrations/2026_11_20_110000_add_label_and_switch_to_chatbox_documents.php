<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The assistant reads several documents, each with the name the admin gives it and a switch
        Schema::table('chatbox_documents', function (Blueprint $table) {
            $table->string('label', 120)->nullable()->after('name');
            $table->string('kind', 10)->default('pdf')->after('label');
            $table->boolean('enabled')->default(true)->after('kind');
        });

        // The handbook uploaded before this change keeps working: it is named after its file
        foreach (DB::table('chatbox_documents')->get(['id', 'name']) as $document) {
            $file = str_contains($document->name, '__') ? substr($document->name, strpos($document->name, '__') + 2) : $document->name;
            DB::table('chatbox_documents')->where('id', $document->id)->update(['label' => pathinfo($file, PATHINFO_FILENAME)]);
        }
    }

    public function down(): void
    {
        Schema::table('chatbox_documents', function (Blueprint $table) {
            $table->dropColumn(['label', 'kind', 'enabled']);
        });
    }
};
