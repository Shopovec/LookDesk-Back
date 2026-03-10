<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentAttachmentsTable extends Migration
{
    public function up()
    {
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');

            $table->text('file')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_attachments');
    }
}