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
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Yearly entitlement in days; NULL means there is no limit (e.g. unpaid leave)
            $table->decimal('days_per_year', 5, 1)->nullable();
            // Optional entitlement by years of service: [{"min_years": 0, "days": 8}, ...]
            $table->json('tiers')->nullable();
            // Restrict the type to one gender (maternity / paternity)
            $table->enum('gender_restriction', ['Male', 'Female'])->nullable();
            // Count every calendar day (weekends and holidays included), e.g. maternity leave
            $table->boolean('count_calendar_days')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
