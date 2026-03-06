<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('owner_change_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('new_owner_email');

            $table->text('comment')->nullable();

            $table->enum('status', ['pending','approved','rejected'])
                ->default('pending');

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_change_requests');
    }
};