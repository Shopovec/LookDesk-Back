<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 5)->default('en');
            $table->json('embedding');
            $table->timestamps();

            $table->unique(['document_id', 'lang']);
            $table->index(['lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_embeddings');
    }
};
