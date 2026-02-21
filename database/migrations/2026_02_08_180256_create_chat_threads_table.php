<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChatThreadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
         $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // клиент
    $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('subject')->nullable();
    $table->enum('status', ['open','closed'])->default('open');
    $table->timestamps();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('chat_threads');
    }
}
