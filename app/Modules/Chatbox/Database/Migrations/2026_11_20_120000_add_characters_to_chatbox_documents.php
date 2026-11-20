<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The size of the text, kept next to it: to measure the documents the text is not read again
        Schema::table('chatbox_documents', function (Blueprint $table) {
            $table->unsignedInteger('characters')->default(0)->after('pages');
        });

        foreach (DB::table('chatbox_documents')->get(['id', 'text']) as $document) {
            DB::table('chatbox_documents')->where('id', $document->id)->update(['characters' => mb_strlen($document->text)]);
        }
    }

    public function down(): void
    {
        Schema::table('chatbox_documents', function (Blueprint $table) {
            $table->dropColumn('characters');
        });
    }
};
