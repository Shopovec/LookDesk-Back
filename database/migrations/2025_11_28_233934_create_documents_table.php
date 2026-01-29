<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentsTable extends Migration
{
    public function up()
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_archived')->default(false);

            $table->string('file_path')->nullable(); // uploaded PDF, DOCX
            $table->string('type')->default('manual'); // ai-generated, uploaded, scanned

            $table->json('meta')->nullable(); // AI fields, SEO, etc.

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('documents');
    }
}