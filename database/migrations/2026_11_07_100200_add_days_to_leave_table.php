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
        Schema::table('leave', function (Blueprint $table) {
            // Number of leave days charged to the balance (working days, computed on save)
            $table->decimal('days', 5, 1)->default(0)->after('end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
