<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // 'pending_review', 'last_transaction'
            $table->json('payload');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // A user will very rarely have more than one active context of
            // a given type at once, but we'll query by (user_id, type) constantly
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_contexts');
    }
};