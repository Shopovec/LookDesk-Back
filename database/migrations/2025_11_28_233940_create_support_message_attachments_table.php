<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSupportMessageAttachmentsTable extends Migration
{
    public function up()
    {
        Schema::create('chat_support_message_attachments', function (Blueprint $table) {
            $table->id();

            $table->integer('chat_support_message_id');

            $table->text('file')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('chat_support_message_attachments');
    }
}