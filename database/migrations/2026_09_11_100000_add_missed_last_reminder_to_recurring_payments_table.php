<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->boolean('missed_last_reminder')->default(false)->after('reminder_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_payments', function (Blueprint $table) {
            $table->dropColumn('missed_last_reminder');
        });
    }
};
