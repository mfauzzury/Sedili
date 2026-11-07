<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // The employee must attach a document (e.g. a medical certificate) to request this leave
            $table->boolean('requires_attachment')->default(false)->after('count_calendar_days');
        });

        Schema::table('leave', function (Blueprint $table) {
            // Path of the document on the private disk (see LeaveAttachment)
            $table->string('attachment')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave', function (Blueprint $table) {
            $table->dropColumn('attachment');
        });

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('requires_attachment');
        });
    }
};
