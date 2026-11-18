<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            // Mileage: the employee enters a distance, the amount is distance x rate_per_km
            $table->boolean('is_mileage')->default(false);
            $table->decimal('rate_per_km', 8, 2)->nullable();
            // Each item of this type needs a receipt before the claim can be submitted
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title');
            // Draft, Submitted, Approved, Rejected, Paid
            $table->string('status')->default('Draft')->index();
            // Sum of the items, kept up to date by ClaimItem
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('actioned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });

        Schema::create('claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->foreignId('claim_type_id')->constrained('claim_types')->restrictOnDelete();
            $table->date('date');
            $table->string('description');
            $table->decimal('distance_km', 8, 1)->nullable();
            // Rate per km at the time of the claim (a later change of the type does not change old claims)
            $table->decimal('rate', 8, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('receipt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_items');
        Schema::dropIfExists('claims');
        Schema::dropIfExists('claim_types');
    }
};
