<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOcrScansTable extends Migration
{
    public function up()
    {
        Schema::create('ocr_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('image_path')->nullable();
            $table->longText('extracted_text')->nullable();

            $table->json('meta')->nullable(); // language, confidence, bounding boxes

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ocr_scans');
    }
}