<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentTranslationsTable extends Migration
{
    public function up()
    {
        Schema::create('document_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');

            $table->string('lang', 10); // en, ua, pl, etc.

            $table->string('title');
            $table->text('content')->nullable();
            $table->text('summary')->nullable();
            $table->text('file')->nullable();

            $table->unique(['document_id', 'lang']);

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('document_translations');
    }
}