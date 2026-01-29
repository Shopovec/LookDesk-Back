<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFunctionTranslationsTable extends Migration
{
    public function up()
    {
        Schema::create('function_translations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('function_id')->constrained('functions')->onDelete('cascade');
            $table->string('lang', 10); // en, ua, pl, de

            $table->string('title');
            $table->text('description')->nullable();

            $table->unique(['function_id', 'lang']); // Prevent duplicate lang rows

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('function_translations');
    }
}