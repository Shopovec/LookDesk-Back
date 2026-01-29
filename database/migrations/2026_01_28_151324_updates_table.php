<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('document_category', function (Blueprint $table) {
            $table->primary(['document_id', 'category_id']);

            $table->foreignId('document_id')->cascadeOnDelete();
            $table->foreignId('category_id')->cascadeOnDelete();
        });
        Schema::create('document_function', function (Blueprint $table) {
            $table->primary(['document_id', 'function_id']);
            $table->foreignId('document_id')->cascadeOnDelete();
            $table->foreignId('function_id')->cascadeOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('confidential')->default(false);
            $table->boolean('only_view')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

    }
}
