<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Updates2Table extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_function', function (Blueprint $table) {
            $table->primary(['user_id', 'function_id']);
            $table->foreignId('user_id')->cascadeOnDelete();
            $table->foreignId('function_id')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('client_creator_id')->nullable();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->integer('model_id')->nullable();
            $table->string('deleted_title', 255)->nullable();
            $table->timestamps();
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
