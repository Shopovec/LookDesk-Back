<?PHP
// database/migrations/2026_03_02_000010_add_payment_method_to_users.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_payment_method_id')->nullable()->index();
            $table->string('payment_method_brand', 50)->nullable();
            $table->string('payment_method_last4', 4)->nullable();
            $table->string('payment_method_exp', 7)->nullable(); // MM/YYYY
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_payment_method_id',
                'payment_method_brand',
                'payment_method_last4',
                'payment_method_exp',
            ]);
        });
    }
};