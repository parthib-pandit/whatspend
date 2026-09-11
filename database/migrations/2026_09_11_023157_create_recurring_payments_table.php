<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->string('category');
            $table->enum('interval_unit', ['day', 'week', 'month']);
            $table->unsignedInteger('interval_count')->default(1);
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable(); // 0 = Sunday, matches Carbon::dayOfWeek
            $table->date('next_due_date');
            $table->boolean('active')->default(true);
            $table->timestamp('last_reminded_at')->nullable();
            $table->unsignedTinyInteger('reminder_attempts')->default(0);
            $table->timestamps();

            $table->index(['active', 'next_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_payments');
    }
};