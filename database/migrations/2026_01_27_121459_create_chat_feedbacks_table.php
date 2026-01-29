<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->boolean('is_useful');
            $table->string('comment', 255)->nullable();
            $table->timestamps();

            $table->unique('chat_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_feedbacks');
    }
};
