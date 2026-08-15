<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category')->nullable(); // null = overall monthly budget
            $table->decimal('monthly_limit', 10, 2);
            $table->unsignedTinyInteger('alert_threshold_percent')->default(80);
            $table->string('last_alerted_period')->nullable(); // e.g. "2026-08"
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};