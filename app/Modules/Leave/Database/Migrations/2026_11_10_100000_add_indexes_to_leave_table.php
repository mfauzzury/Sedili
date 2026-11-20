<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The team and calendar screens filter leave by status and by dates, and the attendance lists by date.
     */
    public function up(): void
    {
        Schema::table('leave', function (Blueprint $table) {
            $table->index('status', 'leave_status_index');
            $table->index(['start_date', 'end_date'], 'leave_dates_index');
        });
    }

    public function down(): void
    {
        Schema::table('leave', function (Blueprint $table) {
            $table->dropIndex('leave_status_index');
            $table->dropIndex('leave_dates_index');
        });
    }
};
